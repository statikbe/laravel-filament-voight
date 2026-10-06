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
3. `RunOsvScanJob` dispatches immediately after every successful sync, sending that environment's stored lockfiles to the OSV Scanner Lambda's `/locks` endpoint and persisting results as `AuditRun`, `AuditFinding`, and `Vulnerability` records.
4. `RunNightlyOsvScanJob` runs on a daily cron. Instead of re-scanning every environment's lockfiles, it collects the **distinct** `(ecosystem, name, version)` set across all environments flagged `scan_nightly`, scans it in a few batched calls to the Lambda's `/packages` endpoint, and fans the results back out to per-environment `AuditRun`s. Environments containing commit-pinned (`dev-*`) dependencies fall back to the per-environment `/locks` path.
5. `voight:push-system-details` runs on each monitored server (after deploy and daily) and POSTs a server snapshot (PHP, OS, database, deployment, Laravel `about`) to `/api/voight/system-details`. Voight keeps the latest snapshot per environment.

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
        'url'   => env('VOIGHT_SCANNER_URL'),
        'token' => env('VOIGHT_SCANNER_TOKEN'),
    ],
];
```

Add to your `.env`:

```dotenv
VOIGHT_LOCKFILES_DISK=voight-lockfiles
VOIGHT_SCANNER_URL=https://your-lambda-url/locks
VOIGHT_SCANNER_TOKEN=your-lambda-secret
```

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

### `POST /api/voight/system-details`

Authenticated via the project bearer token. The token names the project, so there is no `project_code` field.

| Field | Type | Notes |
|---|---|---|
| `environment` | string | required; must match the name used for the lockfile sync (`APP_ENV`) |
| `collected_at` | date | required |
| `versions` | object | optional (`php`, `laravel`, `filament`, `livewire`); stored in indexed columns for filtering |
| `server` | object | required (`php`, `system`, `database`, …) |
| `laravel` | object | optional (output of `php artisan about`) |

Response `204 No Content`. Voight keeps only the latest snapshot per environment (overwritten on each push); bodies over 256 KB get `413`. The snapshot is stored in `voight_environment_system_details` and shown on the environment page (click an environment in the project's Environments tab): stat cards for Laravel, Filament, PHP, database, environment and debug mode, then Laravel / Server / Extensions tabs, plus a **Copy as Markdown** action. The global **Environments** list shows all environments with their PHP / Laravel / Filament / Livewire versions and filters on each.

Pushing is done by the monitored app itself, with the `voight:push-system-details` command from this package and the same project token as the lockfile sync:

```dotenv
VOIGHT_API_BASE_URL=https://your-voight-app.example.com
VOIGHT_API_TOKEN=1|your-project-token
# Optional: environment name to file the snapshot under (default: APP_ENV)
VOIGHT_PUSH_ENVIRONMENT=
# Optional: base URL the app uses to call itself so PHP values come from the web server, not the CLI (default: APP_URL)
VOIGHT_PUSH_LOOPBACK_URL=
```

These are the same variables `voight.sh` already uses, so there is nothing extra to set. The environment name must match the one `voight.sh` sends, or the snapshot lands on a second environment.

```bash
php artisan voight:push-system-details
```

The command does nothing (exit 0) when `VOIGHT_API_BASE_URL` is empty and refuses a non-`https` URL (except `localhost` / `127.0.0.1`). `--url`, `--token` and `--environment` override the config. The command fetches PHP/server values from the app's own web server through a one-minute signed URL (`GET /api/voight/system-details/collect`); if that fails it warns and falls back to CLI values (`sapi` then shows `cli`). In DDEV set `VOIGHT_PUSH_LOOPBACK_URL=http://localhost`, because the container does not trust the local certificate. When a base URL is configured the command is also scheduled daily; to push after each deploy, add a Deployer task running `artisan:voight:push-system-details` after `deploy:symlink`.

## Artisan commands

| Command | Description |
|---|---|
| `voight:create-token --project= --name=` | Generate an API token for a project |
| `voight:sync-lockfile` | Push lockfiles from the command line |
| `voight:push-system-details` | Push a server snapshot (PHP, OS, database, Laravel `about`) to Voight |
| `voight:run-osv-scan` | Dispatch OSV scan jobs (all or filtered) |

### `voight:run-osv-scan`

```bash
php artisan voight:run-osv-scan --nightly                                     # deduplicated nightly sweep
php artisan voight:run-osv-scan                                               # all environments (per-environment /locks)
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
