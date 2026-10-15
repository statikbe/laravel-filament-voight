#!/bin/sh
set -eu

# Load .env if present
if [ -f ".env" ]; then
  . ./.env
fi

export VOIGHT_API_BASE_URL VOIGHT_API_TOKEN PROJECT_CODE APP_ENV APP_DEBUG APP_URL PRIMARY_SITE_URL

# Required env vars
: "${VOIGHT_API_BASE_URL:?VOIGHT_API_BASE_URL is not set}"
: "${VOIGHT_API_TOKEN:?VOIGHT_API_TOKEN is not set}"
: "${PROJECT_CODE:?PROJECT_CODE is not set}"
: "${APP_ENV:?APP_ENV is not set}"

# ── Collect lock files ────────────────────────────────────────────────────────

echo ""
echo "Collecting lock files..."

FOUND_LOCKS=""
for lock in yarn.lock package-lock.json pnpm-lock.yaml bun.lock composer.lock; do
  if [ -f "$lock" ]; then
    FOUND_LOCKS="${FOUND_LOCKS}${FOUND_LOCKS:+ }${lock}"
    echo "  Found: $lock"
  fi
done

if [ -f "package.json" ] && echo "$FOUND_LOCKS" | grep -q "yarn.lock"; then
  FOUND_LOCKS="${FOUND_LOCKS} package.json"
  echo "  Found: package.json"
fi

LOCK_STATUS=0

if [ -z "$FOUND_LOCKS" ]; then
  echo "No lock files found. Skipping lock file upload."
else

# ── Send lock files to API ────────────────────────────────────────────────────

echo ""
echo "Sending lock files to API..."

# Build multipart form-data with all lock files
set -- \
  -s -w "\n%{http_code}" \
  -X POST "$VOIGHT_API_BASE_URL/api/voight/lock-file" \
  -H "Authorization: Bearer $VOIGHT_API_TOKEN" \
  -F "project_code=$PROJECT_CODE" \
  -F "environment=$APP_ENV"

# Public site URL, used by Voight to fetch the project favicon. Laravel sets
# APP_URL, Craft PRIMARY_SITE_URL; VOIGHT_SITE_URL overrides both.
SITE_URL="${VOIGHT_SITE_URL:-${APP_URL:-${PRIMARY_SITE_URL:-}}}"
case "$SITE_URL" in
  http://*|https://*) set -- "$@" -F "url=$SITE_URL" ;;
esac

for lock in $FOUND_LOCKS; do
  set -- "$@" -F "lockfiles[${lock}]=@${lock};filename=${lock}"
done

# A failure must not abort the script: system details are still attempted below.
RESPONSE=$(curl "$@") || RESPONSE="
000"
HTTP_STATUS=$(printf '%s' "$RESPONSE" | tail -n1)
BODY=$(printf '%s' "$RESPONSE" | sed '$d')

echo "  HTTP status: $HTTP_STATUS"
if [ -n "$BODY" ]; then
  echo "  Response: $BODY"
fi

if [ "$HTTP_STATUS" -ge 200 ] && [ "$HTTP_STATUS" -lt 300 ]; then
  echo ""
  echo "Done. Lock files sent successfully."
else
  echo ""
  echo "Error: API returned status $HTTP_STATUS" >&2
  LOCK_STATUS=1
fi

fi

# ── Send system details to API ────────────────────────────────────────────────
# Runs after the lock file upload, which creates the project environment.
# PHP values come from the web server through a temporary probe file, with the
# CLI PHP as fallback. Failures only warn.

# ── Web probe ─────────────────────────────────────────────────────────────────
# CLI PHP ini values differ from the web server's, so ask the web server.

PROBE_JSON=""
PROBE_FILE=""
PROBE_DIR=""
PROBE_URL="${APP_URL:-${PRIMARY_SITE_URL:-}}"

if [ -d public ]; then
  PROBE_DIR="public"
elif [ -d web ]; then
  PROBE_DIR="web"
fi

if [ -n "$PROBE_DIR" ] && [ -n "$PROBE_URL" ]; then
  PROBE_FILE="$PROBE_DIR/voight-probe-$(php -r 'echo bin2hex(random_bytes(16));').php"
  trap 'rm -f "$PROBE_FILE"' EXIT
  trap 'exit 1' INT TERM

  # Mirrors the php block of the CLI collector below (without exec: it is often disabled under FPM).
  if cat > "$PROBE_FILE" <<'PHP'
<?php
function ini(string $key): ?string
{
    $value = ini_get($key);

    return $value === false || $value === '' ? null : $value;
}

function opcache_enabled(): ?bool
{
    if (! function_exists('opcache_get_status')) {
        return false;
    }

    try {
        $status = @opcache_get_status(false);

        return is_array($status) ? (bool) ($status['opcache_enabled'] ?? false) : false;
    } catch (Throwable) {
        return null;
    }
}

function opcache_details(): ?array
{
    if (! function_exists('opcache_get_status')) {
        return null;
    }

    try {
        $status = @opcache_get_status(false);
    } catch (Throwable) {
        return null;
    }

    if (! is_array($status) || ! ($status['opcache_enabled'] ?? false)) {
        return null;
    }

    return [
        'memory_used_bytes' => $status['memory_usage']['used_memory'] ?? null,
        'memory_free_bytes' => $status['memory_usage']['free_memory'] ?? null,
        'memory_wasted_bytes' => $status['memory_usage']['wasted_memory'] ?? null,
        'cached_scripts' => $status['opcache_statistics']['num_cached_scripts'] ?? null,
        'max_cached_keys' => $status['opcache_statistics']['max_cached_keys'] ?? null,
        'oom_restarts' => $status['opcache_statistics']['oom_restarts'] ?? null,
        'hash_restarts' => $status['opcache_statistics']['hash_restarts'] ?? null,
        'interned_strings_used_bytes' => $status['interned_strings_usage']['used_memory'] ?? null,
        'interned_strings_free_bytes' => $status['interned_strings_usage']['free_memory'] ?? null,
        'jit' => isset($status['jit']['enabled']) ? (bool) $status['jit']['enabled'] : null,
    ];
}

$extensions = [];
foreach (get_loaded_extensions() as $name) {
    $extensions[$name] = phpversion($name) ?: null;
}
ksort($extensions, SORT_NATURAL | SORT_FLAG_CASE);

$validateTimestamps = ini('opcache.validate_timestamps');

header('Content-Type: application/json');
echo json_encode([
    'php' => [
        'version' => PHP_VERSION,
        'sapi' => PHP_SAPI,
        'memory_limit' => ini('memory_limit'),
        'max_execution_time' => ini('max_execution_time'),
        'upload_max_filesize' => ini('upload_max_filesize'),
        'post_max_size' => ini('post_max_size'),
        'max_input_vars' => ini('max_input_vars'),
        'timezone' => ini('date.timezone') ?? date_default_timezone_get(),
        'ini_path' => php_ini_loaded_file() ?: null,
        'xdebug' => extension_loaded('xdebug'),
        'opcache' => opcache_enabled(),
        'opcache_validate_timestamps' => $validateTimestamps === null
            ? null
            : filter_var($validateTimestamps, FILTER_VALIDATE_BOOLEAN),
        'extensions' => $extensions,
    ],
    'opcache' => opcache_details(),
    'server_software' => $_SERVER['SERVER_SOFTWARE'] ?? null,
], JSON_UNESCAPED_SLASHES);
PHP
  then
    if PROBE_RESPONSE=$(curl -s --max-time 5 -w "\n%{http_code}" "${PROBE_URL%/}/${PROBE_FILE##*/}") \
      && [ "$(printf '%s' "$PROBE_RESPONSE" | tail -n1)" = "200" ]; then
      PROBE_JSON=$(printf '%s' "$PROBE_RESPONSE" | sed '$d')
    fi
  fi

  rm -f "$PROBE_FILE"

  if [ -z "$PROBE_JSON" ]; then
    echo "Warning: Web probe failed, using CLI PHP values" >&2
  fi
fi

echo ""
echo "Collecting system details..."

if PAYLOAD=$(VOIGHT_PROBE_JSON="$PROBE_JSON" VOIGHT_PROBE_URL="$PROBE_URL" php -d display_errors=stderr <<'PHP'
<?php
$env = getenv('APP_ENV');
$cwd = getcwd();

// One failing section must not cost the whole payload: a Throwable gives null for that section only.
function safe(callable $callback): mixed
{
    try {
        return $callback();
    } catch (Throwable) {
        return null;
    }
}

function ini(string $key): ?string
{
    $value = ini_get($key);

    return $value === false || $value === '' ? null : $value;
}

function package_version(string $package): ?string
{
    $file = 'vendor/composer/installed.json';
    $installed = is_file($file) ? json_decode((string) file_get_contents($file), true) : null;
    // Composer 2 wraps the list in "packages"; Composer 1 is the bare list.
    $packages = is_array($installed) ? ($installed['packages'] ?? $installed) : [];

    foreach ($packages as $entry) {
        if (($entry['name'] ?? null) === $package) {
            return ltrim((string) ($entry['version'] ?? ''), 'v') ?: null;
        }
    }

    return null;
}

function artisan_json(string $command): ?array
{
    // `about` output is translated; the receiver expects stable English values.
    if (! function_exists('exec')) {
        return null;
    }

    putenv('APP_LOCALE=en');
    exec(escapeshellarg(PHP_BINARY) . ' -d display_errors=stderr artisan ' . $command . ' --json 2>/dev/null', $lines, $code);

    return $code === 0 && is_array($data = json_decode(implode("\n", $lines), true)) ? $data : null;
}

function opcache_enabled(): ?bool
{
    if (! function_exists('opcache_get_status')) {
        return false;
    }

    try {
        $status = @opcache_get_status(false);

        return is_array($status) ? (bool) ($status['opcache_enabled'] ?? false) : false;
    } catch (Throwable) {
        return null;
    }
}

function cgroup_limits(): array
{
    $memory = trim((string) @file_get_contents('/sys/fs/cgroup/memory.max'));
    [$quota, $period] = array_pad(explode(' ', trim((string) @file_get_contents('/sys/fs/cgroup/cpu.max'))), 2, null);

    $current = trim((string) @file_get_contents('/sys/fs/cgroup/memory.current'));
    $peak = trim((string) @file_get_contents('/sys/fs/cgroup/memory.peak'));
    $cpuStat = (string) @file_get_contents('/sys/fs/cgroup/cpu.stat');
    $throttledCount = preg_match('/^nr_throttled (\d+)$/m', $cpuStat, $count) === 1 ? (int) $count[1] : null;
    $throttledUsec = preg_match('/^throttled_usec (\d+)$/m', $cpuStat, $usec) === 1 ? (int) $usec[1] : null;

    return [
        'cgroup_memory_limit_bytes' => ctype_digit($memory) ? (int) $memory : null,
        'cgroup_cpu_limit' => ctype_digit((string) $quota) && ctype_digit((string) $period) && (int) $period > 0
            ? round((int) $quota / (int) $period, 2)
            : null,
        'cgroup_memory_current_bytes' => ctype_digit($current) ? (int) $current : null,
        'cgroup_memory_peak_bytes' => ctype_digit($peak) ? (int) $peak : null,
        'cgroup_cpu_throttled_count' => $throttledCount,
        'cgroup_cpu_throttled_seconds' => $throttledUsec === null ? null : round($throttledUsec / 1_000_000, 1),
    ];
}

function os_distro(): ?string
{
    $osRelease = (string) @file_get_contents('/etc/os-release');

    return preg_match('/^PRETTY_NAME=(?:"([^"]*)"|(\S*))$/m', $osRelease, $match) === 1 ? ($match[1] ?: ($match[2] ?? '') ?: null) : null;
}

function uptime_human(): ?string
{
    $seconds = (int) explode(' ', trim((string) @file_get_contents('/proc/uptime')))[0];

    if ($seconds <= 0) {
        return null;
    }

    [$days, $hours, $minutes] = [intdiv($seconds, 86400), intdiv($seconds % 86400, 3600), intdiv($seconds % 3600, 60)];

    return $days > 0 ? "{$days}d {$hours}h" : "{$hours}h {$minutes}m";
}

function ssl_certificate(?string $url): ?array
{
    $parts = $url === null ? false : parse_url($url);

    if (! is_array($parts) || ($parts['scheme'] ?? null) !== 'https' || ! isset($parts['host']) || ! function_exists('openssl_x509_parse')) {
        return null;
    }

    $context = stream_context_create(['ssl' => [
        'capture_peer_cert' => true,
        'verify_peer' => false,
        'verify_peer_name' => false,
        'SNI_enabled' => true,
        'peer_name' => $parts['host'],
    ]]);
    $stream = @stream_socket_client('ssl://' . $parts['host'] . ':' . ($parts['port'] ?? 443), $errno, $error, 5, STREAM_CLIENT_CONNECT, $context);

    if ($stream === false) {
        return null;
    }

    $certificate = stream_context_get_params($stream)['options']['ssl']['peer_certificate'] ?? null;
    fclose($stream);
    $cert = $certificate === null ? false : openssl_x509_parse($certificate);

    if (! is_array($cert)) {
        return null;
    }

    return [
        'issuer' => $cert['issuer']['O'] ?? $cert['issuer']['CN'] ?? null,
        'issuer_cn' => $cert['issuer']['CN'] ?? null,
        'subject' => $cert['subject']['CN'] ?? null,
        'expires_at' => isset($cert['validTo_time_t']) ? date('Y-m-d H:i:s T', $cert['validTo_time_t']) : null,
    ];
}

function commit(string $cwd): ?string
{
    // VOIGHT_COMMIT (set by the deploy) wins; else the Deployer REVISION file in the release directory; checkouts have .git instead.
    $revision = getenv('VOIGHT_COMMIT');

    if (! is_string($revision) || $revision === '') {
        $revision = @file_get_contents($cwd . '/REVISION');
    }

    if ($revision === false) {
        $head = trim((string) @file_get_contents($cwd . '/.git/HEAD'));
        $revision = str_starts_with($head, 'ref: ')
            ? @file_get_contents($cwd . '/.git/' . substr($head, 5))
            : $head;
    }

    $revision = trim((string) $revision);

    return preg_match('/^[0-9a-f]{7,40}$/i', $revision) === 1 ? substr($revision, 0, 7) : null;
}

function process_user(): ?string
{
    if (function_exists('posix_getpwuid') && function_exists('posix_geteuid')) {
        $user = @posix_getpwuid(posix_geteuid());

        if (is_array($user)) {
            return $user['name'];
        }
    }

    return get_current_user() ?: null;
}

$framework = null;
$database = ['driver' => null, 'version' => null, 'size_bytes' => null];

if (is_file('artisan')) {
    $framework = safe(fn (): array => [
        'name' => 'laravel',
        'version' => package_version('laravel/framework'),
        'groups' => artisan_json('about'),
    ]);

    $db = safe(fn (): ?array => artisan_json('db:show'));
    $database = [
        'driver' => $db['platform']['config']['driver'] ?? null,
        'version' => $db['platform']['version'] ?? null,
        'size_bytes' => is_array($db['tables'] ?? null) ? (array_sum(array_column($db['tables'], 'size')) ?: null) : null,
    ];
} elseif (is_file('craft')) {
    $framework = safe(fn (): array => ['name' => 'craft', 'version' => package_version('craftcms/cms'), 'groups' => null]);
}

$extensions = [];
foreach (get_loaded_extensions() as $name) {
    $extensions[$name] = phpversion($name) ?: null;
}
ksort($extensions, SORT_NATURAL | SORT_FLAG_CASE);

$validateTimestamps = ini('opcache.validate_timestamps');
$phpBlock = safe(fn (): array => [
    'version' => PHP_VERSION,
    'sapi' => PHP_SAPI,
    'memory_limit' => ini('memory_limit'),
    'max_execution_time' => ini('max_execution_time'),
    'upload_max_filesize' => ini('upload_max_filesize'),
    'post_max_size' => ini('post_max_size'),
    'max_input_vars' => ini('max_input_vars'),
    'timezone' => ini('date.timezone') ?? date_default_timezone_get(),
    'ini_path' => php_ini_loaded_file() ?: null,
    'xdebug' => extension_loaded('xdebug'),
    'opcache' => opcache_enabled(),
    'opcache_validate_timestamps' => $validateTimestamps === null
        ? null
        : filter_var($validateTimestamps, FILTER_VALIDATE_BOOLEAN),
    'extensions' => $extensions,
]);
$serverSoftware = null;
$opcache = null;

// Web-server values replace the CLI ones when the probe answered with a valid payload.
$probeJson = getenv('VOIGHT_PROBE_JSON');
$probe = $probeJson === false || $probeJson === '' ? null : json_decode($probeJson, true);

if (is_array($probe) && is_array($probe['php'] ?? null) && is_string($probe['php']['version'] ?? null)) {
    $phpBlock = $probe['php'];
    $serverSoftware = is_string($probe['server_software'] ?? null) ? $probe['server_software'] : null;
    // CLI opcache is usually off, so its numbers would mislead: only the web probe reports them.
    $opcache = is_array($probe['opcache'] ?? null) ? $probe['opcache'] : null;
} elseif ($probeJson !== false && $probeJson !== '') {
    fwrite(STDERR, "Warning: Web probe failed, using CLI PHP values\n");
}

$load = function_exists('sys_getloadavg') ? @sys_getloadavg() : null;
$cpuInfo = @file_get_contents('/proc/cpuinfo');

echo json_encode([
    'environment' => $env,
    'collected_at' => date('c'),
    'app' => [
        'environment' => $env,
        'debug_mode' => filter_var(getenv('APP_DEBUG'), FILTER_VALIDATE_BOOLEAN),
    ],
    'framework' => $framework,
    'server' => [
        'php' => $phpBlock,
        'system' => safe(fn (): array => [
            'os' => PHP_OS_FAMILY . ' ' . php_uname('r'),
            'distro' => os_distro(),
            'architecture' => php_uname('m'),
            'uptime' => uptime_human(),
            'server_software' => $serverSoftware,
            'load_average' => is_array($load) ? array_map(fn (float $v): float => round($v, 2), array_values($load)) : null,
            'cpu_cores' => $cpuInfo === false ? null : (preg_match_all('/^processor\s*:/m', $cpuInfo) ?: null),
            'hostname' => gethostname() ?: null,
        ] + cgroup_limits()),
        'deployment' => safe(fn (): array => [
            'release_path' => $cwd,
            'commit' => commit($cwd),
            'php_user' => process_user(),
            'server_time' => date('Y-m-d H:i:s T'),
        ]),
        'database' => $database,
    ] + array_filter([
        // Groups without data are left out so the UI shows no empty section.
        'opcache' => $opcache,
        'ssl' => safe(fn (): ?array => ssl_certificate(getenv('VOIGHT_PROBE_URL') ?: null)),
    ]),
], JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE | JSON_PARTIAL_OUTPUT_ON_ERROR);
PHP
); then
  case "$PAYLOAD" in
    "{"*) SD_VALID=1 ;;
    *) SD_VALID=0 ;;
  esac
else
  SD_VALID=0
fi

if [ "$SD_VALID" = 1 ]; then
  echo ""
  echo "Sending system details to API..."

  if SD_RESPONSE=$(printf '%s' "$PAYLOAD" | curl -s --max-time 15 -w "\n%{http_code}" \
    -X POST "$VOIGHT_API_BASE_URL/api/voight/system-details" \
    -H "Authorization: Bearer $VOIGHT_API_TOKEN" \
    -H "Content-Type: application/json" \
    -H "Accept: application/json" \
    --data-binary @-); then
    SD_STATUS=$(printf '%s' "$SD_RESPONSE" | tail -n1)
    SD_BODY=$(printf '%s' "$SD_RESPONSE" | sed '$d')

    echo "  HTTP status: $SD_STATUS"
    if [ -n "$SD_BODY" ]; then
      echo "  Response: $SD_BODY"
    fi

    if [ "$SD_STATUS" -ge 200 ] && [ "$SD_STATUS" -lt 300 ]; then
      echo ""
      echo "Done. System details sent successfully."
    else
      echo "Warning: system details API returned status $SD_STATUS" >&2
    fi
  else
    echo "Warning: could not reach the API to send system details" >&2
  fi
else
  echo "Warning: could not collect system details (PHP failed)" >&2
fi

exit "$LOCK_STATUS"
