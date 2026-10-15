# Laravel Filament Voight

[![Latest Version on Packagist](https://img.shields.io/packagist/v/statikbe/laravel-filament-voight.svg?style=flat-square)](https://packagist.org/packages/statikbe/laravel-filament-voight)
[![GitHub Tests Action Status](https://img.shields.io/github/actions/workflow/status/statikbe/laravel-filament-voight/run-tests.yml?branch=main&label=tests&style=flat-square)](https://github.com/statikbe/laravel-filament-voight/actions?query=workflow%3Arun-tests+branch%3Amain)
[![GitHub Code Style Action Status](https://img.shields.io/github/actions/workflow/status/statikbe/laravel-filament-voight/fix-php-code-style-issues.yml?branch=main&label=code%20style&style=flat-square)](https://github.com/statikbe/laravel-filament-voight/actions?query=workflow%3A"Fix+PHP+code+styling"+branch%3Amain)
[![Total Downloads](https://img.shields.io/packagist/dt/statikbe/laravel-filament-voight.svg?style=flat-square)](https://packagist.org/packages/statikbe/laravel-filament-voight)

Dependency and vulnerability scanner for PHP and JS projects. Projects push their lockfiles to the Voight app via an authenticated API. The app parses them, stores the full dependency tree, and runs OSV vulnerability scans via a [dedicated Lambda](https://github.com/statikbe/voight-osv-scanner-lambda).

## How it works

```
CI/CD project  →  POST /api/voight/lock-file  →  Voight app  →  ProcessLockFilesJob
                                                                       ↓
                                                              RunOsvScanJob  →  OSV Lambda
                                                                       ↓
                                                   AuditRun + AuditFindings + Vulnerabilities
```

1. A `voight.sh` (or `voight:sync-lockfile`) runs in each project on deploy/install and POSTs lockfiles to the Voight API.
2. `ProcessLockFilesJob` parses `composer.lock` / `package-lock.json` and syncs the full dependency tree into the database.
3. `RunOsvScanJob` dispatches immediately after every successful sync, submitting that environment's stored lockfiles as an asynchronous scan (`POST /scans`, upload, then callback or polling) and persisting results as `AuditRun`, `AuditFinding`, and `Vulnerability` records.
4. `RunNightlyOsvScanJob` runs on a daily cron. Instead of re-scanning every environment's lockfiles, it collects the **distinct** `(ecosystem, name, version)` set across all environments flagged `scan_nightly`, scans it as a few asynchronous `packages` batches, and fans the merged results back out to per-environment `AuditRun`s. Environments containing commit-pinned (`dev-*`) dependencies fall back to per-environment scans. See [specs/15-async-osv-scans.md](specs/15-async-osv-scans.md).
5. `voight.sh` also sends a server snapshot (PHP, OS, database, deployment, framework details; for Laravel the `about` output) to `/api/voight/system-details` after the lockfiles, on deploy. Voight keeps the latest snapshot per environment.

## Installation

```bash
composer require statikbe/laravel-filament-voight
```

> [!IMPORTANT]
> If you have not set up a custom Filament theme, follow the [Filament docs](https://filamentphp.com/docs/4.x/styling/overview#creating-a-custom-theme) first.

Add the plugin's views to your theme CSS:

```css
@source '../../../../vendor/statikbe/laravel-filament-voight/resources/**/*.blade.php';
```

Publish and run the migrations:

```bash
php artisan vendor:publish --tag="filament-voight-migrations"
php artisan migrate
```

When upgrading, publish again to pick up new migrations, run `php artisan migrate`, and run `php artisan filament:optimize` again if you cache Filament components (a stale cache hides new pages such as the environment view).

Publish the config file:

```bash
php artisan vendor:publish --tag="filament-voight-config"
```

Register the plugin in your Filament panel provider:

```php
use Statikbe\FilamentVoight\FilamentVoightPlugin;

public function panel(Panel $panel): Panel
{
    return $panel->plugin(FilamentVoightPlugin::make());
}
```

Or use the standalone panel provider:

```php
// bootstrap/providers.php
Statikbe\FilamentVoight\FilamentVoightPanelProvider::class,
```

## Configuration

After publishing, `config/filament-voight.php` contains:

```php
return [
    'panel' => [
        'path' => 'voight',
    ],

    'lockfiles' => [
        'disk'          => env('VOIGHT_LOCKFILES_DISK', 'voight-lockfiles'),
        'allowed_names' => ['composer.lock', 'package-lock.json', 'yarn.lock', 'pnpm-lock.yaml'],
    ],

    'api' => [
        // Swap for ['auth:sanctum'] to use regular user tokens instead of project tokens
        'middleware' => [\Statikbe\FilamentVoight\Http\Middleware\AuthenticateProjectToken::class],
    ],

    'scanner' => [
        'url'             => env('VOIGHT_SCANNER_URL'),
        'token'           => env('VOIGHT_SCANNER_TOKEN'),
        'callback_url'    => env('VOIGHT_SCANNER_CALLBACK_URL'),
        'callback_secret' => env('VOIGHT_SCANNER_CALLBACK_SECRET'),
        'batch_size'      => (int) env('VOIGHT_SCANNER_BATCH_SIZE', 5000),
        'poll_interval'   => (int) env('VOIGHT_SCANNER_POLL_INTERVAL', 15),
        'max_resubmits'   => (int) env('VOIGHT_SCANNER_MAX_RESUBMITS', 2),
        'reap_scans_after_minutes'  => (int) env('VOIGHT_SCANNER_REAP_SCANS_AFTER_MINUTES', 120),
        'reap_sweeps_after_minutes' => (int) env('VOIGHT_SCANNER_REAP_SWEEPS_AFTER_MINUTES', 360),
    ],
];
```

Add to your `.env`:

```dotenv
VOIGHT_LOCKFILES_DISK=voight-lockfiles
VOIGHT_SCANNER_URL=https://your-lambda-url/prod/scans
VOIGHT_SCANNER_TOKEN=your-lambda-secret
VOIGHT_SCANNER_CALLBACK_SECRET=at-least-32-characters-equal-to-the-lambda-CALLBACK_SECRET
```

- `VOIGHT_SCANNER_URL` must point at the lambda's `ScansUrl` output (ending in `/scans`). `VOIGHT_SCANNER_PACKAGES_URL` no longer exists.
- `VOIGHT_SCANNER_CALLBACK_SECRET` must equal the lambda's `CALLBACK_SECRET` (minimum 32 characters). The scanner signs completion callbacks to `POST api/voight/scans/callback` with it.
- `VOIGHT_SCANNER_BATCH_SIZE` is the number of packages per nightly batch. The scanner accepts at most 8000 per batch, so values are clamped to 1..8000.
- `VOIGHT_SCANNER_CALLBACK_URL` is optional: unset uses the package route, an empty string disables callbacks. A callback URL that is not `https`, or any callback URL while `VOIGHT_SCANNER_CALLBACK_SECRET` is unset, is omitted (a warning is logged); polling still completes the scan.
- Scans are asynchronous, so a **queue worker is required**. Publish and run the new migrations: `php artisan vendor:publish --tag=filament-voight-migrations && php artisan migrate`.
- Queue timeouts: the scan jobs run for up to 300 seconds and `RecordNightlySweepJob` for up to 900 seconds. The queue connection's `retry_after` (in `config/queue.php`) must be **greater than 900**, and the worker's `--timeout` must be **at least 900** (e.g. `php artisan queue:work --timeout=900`, with `retry_after` set to 960). Otherwise a long-running job is killed, or handed to a second worker while the first is still running.

Add the lockfiles disk to `config/filesystems.php`:

```php
'voight-lockfiles' => [
    'driver' => 'local',
    'root'   => storage_path('app/private/voight/lockfiles'),
],
```

## Project tokens

Each project that pushes lockfiles authenticates with a Sanctum token scoped to that project record:

```bash
php artisan voight:create-token --project=my-project --name=ci-pipeline
```

The plaintext token is shown once — store it in your CI secrets.

## Sending lockfiles from a project

### Option A — shell script (recommended for CI/CD)

Copy `vendor/statikbe/laravel-filament-voight/resources/scripts/voight.sh` into your project. Add environment variables (`.env` or CI secrets):

```dotenv
VOIGHT_API_BASE_URL=https://your-voight-app.example.com
VOIGHT_API_TOKEN=1|your-project-token
PROJECT_CODE=my-project
APP_ENV=production
```

Run it after every install or deploy:

```bash
sh voight.sh
```

The script auto-discovers `composer.lock`, `package-lock.json`, `yarn.lock`, `pnpm-lock.yaml`, and `bun.lock` in the current directory and POSTs them as `multipart/form-data`.

After the lockfiles, the script also POSTs a system-details snapshot (PHP, OS, disk, database, and framework info for Laravel or Craft CMS). It needs only `php` on the server, not `jq`. PHP values come from the web server: the script briefly writes a random `voight-probe-*.php` file into `public/` (or `web/` for Craft) and fetches it at `APP_URL` (or `PRIMARY_SITE_URL`), then deletes it. If that fails it warns and falls back to the CLI PHP values (`sapi` is `cli`). The site must therefore be reachable at that URL, so on Deployer run the script after `deploy:symlink`. A failure never changes the exit code.

### Option B — artisan command

Useful for local testing or one-off syncs:

```bash
php artisan voight:sync-lockfile \
  --project=my-project \
  --environment=production \
  --path=/path/to/project \
  --url=https://your-voight-app.example.com \
  --token=1|your-project-token
```

## MCP server

AI clients such as Claude Code or Cursor can query Voight over MCP: vulnerabilities per project, which direct dependency to bump, where a package is used, and statistics. They can also onboard a project (create it and issue its sync token). Enable it in `.env`:

```dotenv
VOIGHT_MCP_ENABLED=true
# Hide the tools that create projects and project tokens:
# VOIGHT_MCP_WRITE_TOOLS=false
```

**Local** (sees every project, runs as the machine's operator):

```bash
claude mcp add voight -- php artisan mcp:start voight
```

**Remote** at `/mcp/voight`, authenticated with a personal token. Add `Laravel\Sanctum\HasApiTokens` to your user model, then create a token on the **MCP access** page of the Voight panel; it shows the `claude mcp add` command to run.

Remote users only see the projects of their teams. Grant some users everything, or replace the team rule, in a service provider:

```php
use Statikbe\FilamentVoight\Facades\FilamentVoight;

FilamentVoight::resolveMcpAdminUsing(fn ($user): bool => $user->is_admin);
FilamentVoight::resolveMcpVisibleProjectsUsing(fn ($query, $user) => $query->where(/* your rules */));
```

When your app registers a policy for the project model, the MCP tools respect it (`viewAny`, `view`, `create`, `update`). See `specs/13-mcp-server.md` for the full design.

## API reference

### `POST /api/voight/lock-file`

Authenticated via the project bearer token.

| Field | Type | Notes |
|---|---|---|
| `project_code` | string | required |
| `environment` | string | required (`production`, `staging`, …) |
| `url` | string | optional, public site URL of the environment (used for the project favicon) |
| `lockfiles[filename]` | file | required, one or more |

Response `202 Accepted`:

```json
{ "sync_id": "01HXY...", "status": "pending" }
```

Processing is async — the client gets an immediate acknowledgement and the sync + scan happen in the background queue.

### `POST /api/voight/system-details`

Authenticated via the project bearer token. The token names the project, so there is no `project_code` field.

| Field | Type | Notes |
|---|---|---|
| `environment` | string | required; must match the name used for the lockfile sync (`APP_ENV`) |
| `collected_at` | date | required |
| `app` | object | optional: `environment` (string), `debug_mode` (bool); feed the Environment and Debug mode stat cards |
| `framework` | object | optional: `name` (e.g. `laravel`, `craft`), `version`, `groups` (object of group name => key/value rows, rendered as-is in the Framework tab, titled "Name version") |
| `server` | object | required (`php`, `system`, `database`, `deployment`, `disk`, …); `server.php.version` is stored in an indexed column for filtering, the rest only in the stored payload |

The contract is framework-neutral: any client (for example a Craft CMS plugin) can push the same JSON. Unknown keys and groups are rendered generically.

```json
{
  "environment": "production",
  "collected_at": "2026-10-15T09:00:00+00:00",
  "app": { "environment": "production", "debug_mode": false },
  "framework": { "name": "craft", "version": "5.4.0", "groups": { "edition": { "edition": "pro" } } },
  "server": { "php": { "version": "8.4.1" }, "database": { "driver": "mysql", "version": "8.0.46" } }
}
```

Response `204 No Content`. Voight keeps only the latest snapshot per environment (overwritten on each push); bodies over 256 KB get `413`. The snapshot is stored in `voight_environment_system_details` and shown on the environment page (click an environment in the project's Environments tab): stat cards for PHP, database, environment and debug mode, then Framework / Server / Extensions tabs, plus a **Copy as Markdown** action. The global **Environments** list shows all environments with their PHP version and filters on it.

## Artisan commands

| Command | Description |
|---|---|
| `voight:create-token --project= --name=` | Generate an API token for a project |
| `voight:sync-lockfile` | Push lockfiles from the command line |
| `voight:run-osv-scan` | Dispatch OSV scan jobs (all or filtered) |
| `voight:fetch-favicons [--project=] [--force]` | Fetch project favicons from their environment URLs |

### `voight:run-osv-scan`

```bash
php artisan voight:run-osv-scan --nightly                                     # deduplicated nightly sweep
php artisan voight:run-osv-scan                                               # all environments (per-environment scans)
php artisan voight:run-osv-scan --project=my-project                          # one project
php artisan voight:run-osv-scan --project=my-project --environment=production # one environment
```

## Project favicons

The projects and environments tables show each project's favicon so projects are easy to tell apart. Projects without one get a coloured initial.

1. `voight.sh` sends the environment's public URL (`VOIGHT_SITE_URL`, else `APP_URL`, else Craft's `PRIMARY_SITE_URL`) with every sync.
2. When an environment URL changes, or the stored favicon is older than `favicons.refresh_after_days`, `FetchProjectFaviconJob` is queued.
3. The job reads `<link rel="icon">` / `apple-touch-icon` tags from a production-stage environment (see `environments.stages`) that has a URL, falling back to `/favicon.ico`, and stores the largest icon on the `favicons.disk` disk (default `public`, so run `php artisan storage:link`).

Only production-like environments are preferred because test and acceptance sites are usually behind basic auth. Third-party lookups (`duck-duck-go`, `google-shared-stuff`, …) are off by default; enable them via `favicons.fallback_drivers`. Disable the feature with `VOIGHT_FAVICONS_ENABLED=false`.

Backfill existing projects once their environments have a URL (set it in the environment form, or wait for the next deploy):

```bash
php artisan voight:fetch-favicons
```

## Scheduling

The package automatically schedules `voight:run-osv-scan --nightly` (with `withoutOverlapping`). This only runs if your application's scheduler is active — add the standard Laravel scheduler cron entry:

```cron
* * * * * php /path/to/artisan schedule:run >> /dev/null 2>&1
```

The run time is configurable via a cron expression (`scanner.nightly_cron`, default `0 0 * * *` = midnight). Set `VOIGHT_SCANNER_NIGHTLY_CRON` in your `.env`, e.g. `0 2 * * *` for 02:00, or an empty string to disable the automatic schedule:

```dotenv
VOIGHT_SCANNER_NIGHTLY_CRON="0 2 * * *"
```

The package also schedules `voight:reap-stranded-scans` hourly (independent of the nightly cron). It recovers scans that can no longer finish on their own — a lost poll job, or a worker killed mid-recording:

- a scan still `pending` or `processing` after `scanner.reap_scans_after_minutes` (default 120) is failed with `scan abandoned: no result after N minutes`. A lockfile scan records one failed audit run; a nightly batch fails its sweep, recording a failed run per sweep environment;
- a nightly sweep still `scanning` or `recording` after `scanner.reap_sweeps_after_minutes` (default 360) is failed, recording a failed nightly run per environment.

A scan that completes at the same moment is never double-recorded: the reaper and the completion claim the row atomically and only one wins. Keep the thresholds well above the 30-minute poll deadline and the 900-second recording timeout. You can also run `php artisan voight:reap-stranded-scans` by hand.

Scans also trigger automatically after every successful lockfile sync (post-sync hook). Environments can be excluded from the nightly sweep by turning off their **Nightly scan** toggle (the `scan_nightly` flag).

## Testing

```bash
composer test
```

## Changelog

Please see [CHANGELOG](CHANGELOG.md) for more information on what has changed recently.

## Contributing

Please see [CONTRIBUTING](.github/CONTRIBUTING.md) for details.

## Security Vulnerabilities

Please review [our security policy](.github/SECURITY.md) on how to report security vulnerabilities.

## Credits

- [Sten Govaerts](https://github.com/sten)
- [All Contributors](../../contributors)

## License

The MIT License (MIT). Please see [License File](LICENSE.md) for more information.
