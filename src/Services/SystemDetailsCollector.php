<?php

namespace Statikbe\FilamentVoight\Services;

use Composer\InstalledVersions;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use PDO;
use Throwable;

class SystemDetailsCollector
{
    /**
     * @return array{collected_at: string, versions: array<string, string|null>, server: array<string, array<string, mixed>>, laravel: array<string, mixed>|null}
     */
    public function collect(): array
    {
        // `about` output is translated; the receiver expects stable English values.
        $locale = app()->getLocale();
        app()->setLocale('en');

        try {
            return [
                'collected_at' => now()->toIso8601String(),
                'versions' => $this->versions(),
                'server' => $this->server(),
                'laravel' => $this->laravel(),
            ];
        } finally {
            app()->setLocale($locale);
        }
    }

    /**
     * Mirrored into indexed columns on the receiving side so projects can be filtered by stack version.
     *
     * @return array<string, string|null>
     */
    private function versions(): array
    {
        return [
            'php' => PHP_VERSION,
            'laravel' => $this->packageVersion('laravel/framework'),
            'filament' => $this->packageVersion('filament/filament'),
            'livewire' => $this->packageVersion('livewire/livewire'),
        ];
    }

    private function packageVersion(string $package): ?string
    {
        if (! InstalledVersions::isInstalled($package)) {
            return null;
        }

        return ltrim((string) InstalledVersions::getPrettyVersion($package), 'v') ?: null;
    }

    /**
     * @return array<string, array<string, mixed>>
     */
    private function server(): array
    {
        return [
            'php' => $this->php(),
            'system' => $this->system(),
            'deployment' => $this->deployment(),
            'database' => $this->database(),
            'disk' => [
                'free_bytes' => @disk_free_space(base_path()) ?: null,
                'total_bytes' => @disk_total_space(base_path()) ?: null,
            ],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function php(): array
    {
        return [
            'version' => PHP_VERSION,
            'sapi' => PHP_SAPI,
            'memory_limit' => $this->ini('memory_limit'),
            'max_execution_time' => $this->ini('max_execution_time'),
            'upload_max_filesize' => $this->ini('upload_max_filesize'),
            'post_max_size' => $this->ini('post_max_size'),
            'max_input_vars' => $this->ini('max_input_vars'),
            'timezone' => $this->ini('date.timezone') ?? date_default_timezone_get(),
            'ini_path' => php_ini_loaded_file() ?: null,
            'xdebug' => extension_loaded('xdebug'),
            'opcache' => $this->opcacheEnabled(),
            'opcache_validate_timestamps' => $this->ini('opcache.validate_timestamps') === null
                ? null
                : filter_var($this->ini('opcache.validate_timestamps'), FILTER_VALIDATE_BOOLEAN),
            'extensions' => collect(get_loaded_extensions())->sort(SORT_NATURAL | SORT_FLAG_CASE)->values()->all(),
        ];
    }

    private function ini(string $key): ?string
    {
        $value = ini_get($key);

        return $value === false || $value === '' ? null : $value;
    }

    private function opcacheEnabled(): ?bool
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

    /**
     * @return array<string, mixed>
     */
    private function system(): array
    {
        return [
            'os' => PHP_OS_FAMILY . ' ' . php_uname('r'),
            'server_software' => PHP_SAPI === 'cli' ? null : ($_SERVER['SERVER_SOFTWARE'] ?? null),
            'load_average' => $this->loadAverage(),
            'cpu_cores' => $this->cpuCores(),
            'hostname' => gethostname() ?: null,
        ];
    }

    /**
     * @return array<int, float>|null
     */
    private function loadAverage(): ?array
    {
        if (! function_exists('sys_getloadavg')) {
            return null;
        }

        $load = @sys_getloadavg();

        return is_array($load) ? array_map(fn (float $value): float => round($value, 2), array_values($load)) : null;
    }

    private function cpuCores(): ?int
    {
        // Linux only; other platforms report null rather than shelling out.
        $cpuInfo = @file_get_contents('/proc/cpuinfo');

        if ($cpuInfo === false) {
            return null;
        }

        return preg_match_all('/^processor\s*:/m', $cpuInfo) ?: null;
    }

    /**
     * @return array<string, string|null>
     */
    private function deployment(): array
    {
        return [
            'release_path' => base_path(),
            'commit' => $this->commit(),
            'php_user' => $this->processUser(),
            'server_time' => now()->format('Y-m-d H:i:s T'),
        ];
    }

    private function commit(): ?string
    {
        try {
            // Deployer writes REVISION into the release directory; checkouts have .git instead.
            $revision = @file_get_contents(base_path('REVISION'));

            if ($revision === false) {
                $head = trim((string) @file_get_contents(base_path('.git/HEAD')));
                $revision = str_starts_with($head, 'ref: ')
                    ? @file_get_contents(base_path('.git/' . substr($head, 5)))
                    : $head;
            }

            $revision = trim((string) $revision);

            return preg_match('/^[0-9a-f]{7,40}$/i', $revision) === 1 ? substr($revision, 0, 7) : null;
        } catch (Throwable) {
            return null;
        }
    }

    private function processUser(): ?string
    {
        if (function_exists('posix_getpwuid') && function_exists('posix_geteuid')) {
            $user = @posix_getpwuid(posix_geteuid());

            if (is_array($user)) {
                return $user['name'];
            }
        }

        return get_current_user() ?: null;
    }

    /**
     * @return array{driver: string|null, version: string|null}
     */
    private function database(): array
    {
        try {
            $connection = DB::connection();

            return [
                'driver' => $connection->getDriverName(),
                'version' => $connection->getPdo()->getAttribute(PDO::ATTR_SERVER_VERSION),
            ];
        } catch (Throwable) {
            return ['driver' => null, 'version' => null];
        }
    }

    /**
     * @return array<string, mixed>|null
     */
    private function laravel(): ?array
    {
        try {
            if (Artisan::call('about', ['--json' => true]) !== 0) {
                return null;
            }

            $about = json_decode(Artisan::output(), true);

            return is_array($about) ? $about : null;
        } catch (Throwable) {
            return null;
        }
    }
}
