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
3. `RunOsvScanJob` dispatches immediately after every successful sync, and also on a daily cron. It sends the stored lockfiles to the OSV Scanner Lambda and persists results as `AuditRun`, `AuditFinding`, and `Vulnerability` records.

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

Copy `vendor/statikbe/laravel-filament-voight/resources/scripts/script.sh` into your project. Add environment variables (`.env` or CI secrets):

```dotenv
API_URL=https://your-voight-app.example.com/api/voight/lock-file
API_TOKEN=1|your-project-token
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
php artisan voight:run-osv-scan                                               # all environments
php artisan voight:run-osv-scan --project=my-project                          # one project
php artisan voight:run-osv-scan --project=my-project --environment=production # one environment
```

## Scheduling

The package automatically schedules `voight:run-osv-scan` daily. No extra cron entry is needed beyond the standard Laravel scheduler:

```cron
* * * * * php /path/to/artisan schedule:run >> /dev/null 2>&1
```

Scans also trigger automatically after every successful lockfile sync (post-sync hook).

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
