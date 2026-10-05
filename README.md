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

1. A `script.sh` (or `voight:sync-lockfile`) runs in each project on deploy/install and POSTs lockfiles to the Voight API.
2. `ProcessLockFilesJob` parses `composer.lock` / `package-lock.json` and syncs the full dependency tree into the database.
3. `RunOsvScanJob` dispatches immediately after every successful sync, submitting that environment's stored lockfiles as an asynchronous scan (`POST /scans`, upload, then callback or polling) and persisting results as `AuditRun`, `AuditFinding`, and `Vulnerability` records.
4. `RunNightlyOsvScanJob` runs on a daily cron. Instead of re-scanning every environment's lockfiles, it collects the **distinct** `(ecosystem, name, version)` set across all environments flagged `scan_nightly`, scans it as a few asynchronous `packages` batches, and fans the merged results back out to per-environment `AuditRun`s. Environments containing commit-pinned (`dev-*`) dependencies fall back to per-environment scans. See [specs/15-async-osv-scans.md](specs/15-async-osv-scans.md).

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
php artisan vendor:publish --tag="laravel-filament-voight-migrations"
php artisan migrate
```

Publish the config file:

```bash
php artisan vendor:publish --tag="laravel-filament-voight-config"
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

After publishing, `config/laravel-filament-voight.php` contains:

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
- `VOIGHT_SCANNER_CALLBACK_URL` is optional: unset uses the package route, an empty string disables callbacks. A callback URL that is not `https` is omitted (a warning is logged); polling still completes the scan.
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

Copy `vendor/statikbe/laravel-filament-voight/resources/scripts/script.sh` into your project. Add environment variables (`.env` or CI secrets):

```dotenv
VOIGHT_API_URL=https://your-voight-app.example.com/api/voight/lock-file
VOIGHT_API_TOKEN=1|your-project-token
PROJECT_CODE=my-project
APP_ENV=production
```

Run it after every install or deploy:

```bash
sh script.sh
```

The script auto-discovers `composer.lock`, `package-lock.json`, `yarn.lock`, `pnpm-lock.yaml`, and `bun.lock` in the current directory and POSTs them as `multipart/form-data`.

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

## API reference

### `POST /api/voight/lock-file`

Authenticated via the project bearer token.

| Field | Type | Notes |
|---|---|---|
| `project_code` | string | required |
| `environment` | string | required (`production`, `staging`, …) |
| `lockfiles[filename]` | file | required, one or more |

Response `202 Accepted`:

```json
{ "sync_id": "01HXY...", "status": "pending" }
```

Processing is async — the client gets an immediate acknowledgement and the sync + scan happen in the background queue.

## Artisan commands

| Command | Description |
|---|---|
| `voight:create-token --project= --name=` | Generate an API token for a project |
| `voight:sync-lockfile` | Push lockfiles from the command line |
| `voight:run-osv-scan` | Dispatch OSV scan jobs (all or filtered) |

### `voight:run-osv-scan`

```bash
php artisan voight:run-osv-scan --nightly                                     # deduplicated nightly sweep
php artisan voight:run-osv-scan                                               # all environments (per-environment scans)
php artisan voight:run-osv-scan --project=my-project                          # one project
php artisan voight:run-osv-scan --project=my-project --environment=production # one environment
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
