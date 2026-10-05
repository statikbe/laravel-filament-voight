# Async Scans — Package Implementation Plan (Plan B of 2)

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Make Voight submit scans asynchronously (`POST /scans` → S3 upload), learn about completion through a signed callback or polling, record each scan exactly once, and retry only the batch that failed.

**Architecture:** Every scan is an `OsvScan` row; nightly batches also belong to a `NightlySweep`. `OsvScanSubmitter` saves the input and queues `SubmitOsvScanJob`, which creates the remote scan, uploads, and queues `PollOsvScanJob`. Completion arrives either through `PollOsvScanJob` or the callback route (→ `CompleteOsvScanJob`); both hand the terminal status to `OsvScanResultHandler`, whose atomic `UPDATE … WHERE status='pending'` claim guarantees a single winner. A sweep is recorded by `RecordNightlySweepJob` once its last batch completes.

**Tech Stack:** PHP 8.4, Laravel 13, Pest, Orchestra Testbench, PHPStan level 4, Pint. Laravel HTTP client (`Http::fake` in tests), queues, filesystem.

**Spec:** `/Users/zias/dev/voight-osv-scanner-lambda/.worktrees/async-scans/docs/superpowers/specs/2026-10-05-async-scans-design.md` (§5 is this plan's scope; §4.1/§4.3 is the HTTP contract).

**Companion plan:** `voight-osv-scanner-lambda` → `docs/superpowers/plans/2026-10-05-async-scans-lambda.md` (Plan A). This plan only needs the contract, not a running scanner.

**Working directory:** `/Users/zias/dev/laravel-filament-voight/.worktrees/async-scans` (branch `feature/async-scans`).

**Running tests:** `php -d memory_limit=1G vendor/bin/pest --no-coverage` (the default 128 MB crashes). Baseline: 343 pass, **9 pre-existing failures** in `ComposerLockParserTest` and `ProcessLockFilesJobTest` caused by the git-ignored fixture `tests/Fixtures/lockfiles/composer/composer.lock`. Those 9 stay failing and are out of scope; every other test must pass after each task.

## Global Constraints

- All primary keys are ULIDs (`HasUlids`, `$table->ulid('id')->primary()`); foreign keys use `foreignUlid(...)->constrained(...)->cascadeOnDelete()`.
- Models are resolved through `FilamentVoight::config()->get…Model()`, like every existing model.
- New migrations ship as `database/migrations/*.php.stub`, are listed in `FilamentVoightServiceProvider::getMigrations()`, and get a numbered copy under `tests/database/migrations/`.
- Scanner HTTP: bearer token on scanner calls only, **never** on the S3 upload or result download. Timeouts: 15 s for API calls, 60 s for upload/download, 10 s connect.
- Callback: `POST api/voight/scans/callback`, route name `voight.scans.callback`, only the `VerifyScannerCallbackSignature` middleware; `X-Voight-Signature: sha256=<hex HMAC-SHA256(secret, timestamp + "." + raw_body)>`, timestamp within ±300 s, `hash_equals`; any failure → `401`; unknown scan → `404`; success → `202`.
- Config `scanner`: `url`, `token`, `callback_url` (null → package route; `''` → callbacks off), `callback_secret`, `batch_size` (5000), `poll_interval` (15), `max_resubmits` (2), `nightly_cron`. `packages_url` is removed.
- Poll deadline: 30 minutes from the first poll (`retryUntil`).
- A failed scan records **one** failed `AuditRun`; `error_message` is truncated to 500 chars.
- `ScanResponse` is reused unchanged for result parsing.
- Commit messages end with `Co-Authored-By: Claude Opus 5.5 <noreply@anthropic.com>`.

## Review Focus

1. **Callback and poller observe the same finished scan at once** → results recorded exactly once. Pinned in Task 4 (`handleTerminal` twice).
2. **A resubmit replaced the scanner scan id while an old poller or callback is still queued** → the stale job must not complete or fail the new submission. Pinned in Task 4.
3. **An environment is deleted while its scan is in flight** (cascade deletes the `OsvScan`) → queued jobs are dropped quietly (`deleteWhenMissingModels`), not retried into errors. Pinned in Task 4.
4. **The nightly job dies halfway through creating batches** → no partial sweep exists that could later "complete" with missing packages and record a false clean result. Pinned in Task 7.
5. **A batch of an already-failed sweep reports a retryable failure** → it is not resubmitted, and no further failed runs are written. Pinned in Task 4.

## File Structure

| File | Responsibility | Status |
|---|---|---|
| `src/Enums/OsvScanType.php`, `OsvScanStatus.php`, `NightlySweepStatus.php` | internal state enums (no Filament contracts; never shown in UI) | create |
| `database/migrations/create_voight_nightly_sweeps_table.php.stub`, `create_voight_osv_scans_table.php.stub` (+ test copies) | schema | create |
| `src/Models/OsvScan.php`, `src/Models/NightlySweep.php` (+ factories) | models | create |
| `config/filament-voight.php`, `src/FilamentVoightConfig.php` | scanner + model config | modify |
| `src/Support/CreatedScan.php`, `ScanStatus.php`, `ScanError.php` | client DTOs | create |
| `src/Services/OsvScannerClient.php` | `createScan`, `upload`, `getScan` | modify |
| `src/Services/RecordEnvironmentAuditRunsService.php` | + `recordFailed`, `recordFailedForEnvironments` | modify |
| `src/Jobs/RecordNightlySweepJob.php` | merge batch results, record per environment | create |
| `src/Services/OsvScanSubmitter.php` | create row, save input, queue submit | create |
| `src/Services/OsvScanResultHandler.php` | claim, record/complete, resubmit, fail | create |
| `src/Jobs/SubmitOsvScanJob.php`, `PollOsvScanJob.php`, `CompleteOsvScanJob.php` | scan lifecycle | create |
| `src/Jobs/RunOsvScanJob.php` | build locks input, start scan | rewrite |
| `src/Http/Middleware/VerifyScannerCallbackSignature.php`, `src/Http/Controllers/ScanCallbackController.php`, `routes/api.php` | callback | create/modify |
| `src/Jobs/RunNightlyOsvScanJob.php` | partition, create sweep + batches atomically | rewrite |
| `tests/Pest.php` | `fakeScanner()` helper | modify |
| `specs/09-…`, `specs/10-async-osv-scans.md`, `README.md`, `CHANGELOG.md` | docs | modify/create |

---

### Task 1: Data model and configuration

**Files:**
- Create: `src/Enums/OsvScanType.php`, `src/Enums/OsvScanStatus.php`, `src/Enums/NightlySweepStatus.php`
- Create: `database/migrations/create_voight_nightly_sweeps_table.php.stub`, `database/migrations/create_voight_osv_scans_table.php.stub`
- Create: `tests/database/migrations/0001_01_01_000024_create_voight_nightly_sweeps_table.php`, `tests/database/migrations/0001_01_01_000025_create_voight_osv_scans_table.php` (identical copies of the stubs)
- Create: `src/Models/OsvScan.php`, `src/Models/NightlySweep.php`, `database/factories/OsvScanFactory.php`, `database/factories/NightlySweepFactory.php`
- Modify: `src/FilamentVoightServiceProvider.php` (`getMigrations()`), `config/filament-voight.php`, `src/FilamentVoightConfig.php`
- Test: `tests/Models/OsvScanTest.php`, `tests/database/MigrationTest.php`, `tests/Unit/FilamentVoightConfigTest.php`

**Interfaces:**
- Produces:
  - `enum OsvScanType: string { Packages = 'packages'; Locks = 'locks' }`
  - `enum OsvScanStatus: string { Pending = 'pending'; Processing = 'processing'; Completed = 'completed'; Failed = 'failed' }`
  - `enum NightlySweepStatus: string { Scanning = 'scanning'; Recording = 'recording'; Completed = 'completed'; Failed = 'failed' }`
  - `OsvScan` (table `voight_osv_scans`): `id`, `scanner_scan_id ?string`, `type OsvScanType`, `status OsvScanStatus`, `environment_id ?string`, `nightly_sweep_id ?string`, `trigger AuditRunTrigger`, `meta array`, `resubmits int`, `input_path ?string`, `error_message ?string`; relations `environment(): BelongsTo`, `nightlySweep(): BelongsTo`.
  - `NightlySweep` (table `voight_nightly_sweeps`): `id`, `status NightlySweepStatus`, `environment_ids array<string>`, `started_at`, `completed_at`; relation `scans(): HasMany<OsvScan>`.
  - Factories: `OsvScan::factory()` (pending locks scan with a `scanner_scan_id`), `->packages()` state; `NightlySweep::factory()`.
  - `FilamentVoightConfig`: `getOsvScanModel(): class-string<OsvScan>`, `getNightlySweepModel(): class-string<NightlySweep>`, `getScannerPollInterval(): int`, `getScannerMaxResubmits(): int`, `getScannerCallbackSecret(): ?string`.

- [ ] **Step 1: Write the failing tests**

Create `tests/Models/OsvScanTest.php`:

```php
<?php

use Statikbe\FilamentVoight\Enums\AuditRunTrigger;
use Statikbe\FilamentVoight\Enums\NightlySweepStatus;
use Statikbe\FilamentVoight\Enums\OsvScanStatus;
use Statikbe\FilamentVoight\Enums\OsvScanType;
use Statikbe\FilamentVoight\Models\Environment;
use Statikbe\FilamentVoight\Models\NightlySweep;
use Statikbe\FilamentVoight\Models\OsvScan;

it('casts a locks scan and links it to its environment', function () {
    $environment = Environment::factory()->create();
    $scan = OsvScan::factory()->create(['environment_id' => $environment->id, 'meta' => ['project_code' => 'p1']]);

    $scan->refresh();

    expect($scan->type)->toBe(OsvScanType::Locks)
        ->and($scan->status)->toBe(OsvScanStatus::Pending)
        ->and($scan->trigger)->toBe(AuditRunTrigger::Manual)
        ->and($scan->meta)->toBe(['project_code' => 'p1'])
        ->and($scan->resubmits)->toBe(0)
        ->and($scan->environment->is($environment))->toBeTrue()
        ->and(strlen($scan->id))->toBe(26);
});

it('groups packages scans under a nightly sweep', function () {
    $sweep = NightlySweep::factory()->create(['environment_ids' => ['a', 'b']]);
    OsvScan::factory()->packages()->count(2)->create(['nightly_sweep_id' => $sweep->id]);

    $sweep->refresh();

    expect($sweep->status)->toBe(NightlySweepStatus::Scanning)
        ->and($sweep->environment_ids)->toBe(['a', 'b'])
        ->and($sweep->scans)->toHaveCount(2)
        ->and($sweep->scans->first()->type)->toBe(OsvScanType::Packages)
        ->and($sweep->scans->first()->nightlySweep->is($sweep))->toBeTrue();
});

it('deletes an environment\'s scans with the environment', function () {
    $scan = OsvScan::factory()->create();

    $scan->environment->delete();

    expect(OsvScan::count())->toBe(0);
});
```

Append to `tests/database/MigrationTest.php`:

```php
it('creates the async scan tables', function () {
    expect(Schema::hasTable('voight_nightly_sweeps'))->toBeTrue()
        ->and(Schema::hasColumns('voight_osv_scans', [
            'id', 'scanner_scan_id', 'type', 'status', 'environment_id', 'nightly_sweep_id',
            'trigger', 'meta', 'resubmits', 'input_path', 'error_message', 'created_at', 'updated_at',
        ]))->toBeTrue();
});
```

Append to `tests/Unit/FilamentVoightConfigTest.php`:

```php
it('reads the async scanner settings with spec defaults', function () {
    config()->set('filament-voight.scanner.poll_interval', null);
    config()->set('filament-voight.scanner.max_resubmits', null);
    config()->set('filament-voight.scanner.callback_secret', '');

    expect(FilamentVoight::config()->getScannerPollInterval())->toBe(15)
        ->and(FilamentVoight::config()->getScannerMaxResubmits())->toBe(2)
        ->and(FilamentVoight::config()->getScannerCallbackSecret())->toBeNull();

    config()->set('filament-voight.scanner.poll_interval', 5);
    config()->set('filament-voight.scanner.max_resubmits', 0);
    config()->set('filament-voight.scanner.callback_secret', 's3cret');

    expect(FilamentVoight::config()->getScannerPollInterval())->toBe(5)
        ->and(FilamentVoight::config()->getScannerMaxResubmits())->toBe(0)
        ->and(FilamentVoight::config()->getScannerCallbackSecret())->toBe('s3cret');
});

it('resolves the scan models from config', function () {
    expect(FilamentVoight::config()->getOsvScanModel())->toBe(Statikbe\FilamentVoight\Models\OsvScan::class)
        ->and(FilamentVoight::config()->getNightlySweepModel())->toBe(Statikbe\FilamentVoight\Models\NightlySweep::class);
});
```

> `config()->set(..., null)` makes `config($key, $default)` return `null`, not the default — so the getters below cast and fall back explicitly.

- [ ] **Step 2: Run to verify failure**

Run: `php -d memory_limit=1G vendor/bin/pest --no-coverage tests/Models/OsvScanTest.php tests/database/MigrationTest.php tests/Unit/FilamentVoightConfigTest.php`
Expected: FAIL — classes and tables missing.

- [ ] **Step 3: Enums**

`src/Enums/OsvScanType.php`:

```php
<?php

namespace Statikbe\FilamentVoight\Enums;

enum OsvScanType: string
{
    case Packages = 'packages';
    case Locks = 'locks';
}
```

`src/Enums/OsvScanStatus.php`:

```php
<?php

namespace Statikbe\FilamentVoight\Enums;

enum OsvScanStatus: string
{
    case Pending = 'pending';
    case Processing = 'processing';
    case Completed = 'completed';
    case Failed = 'failed';
}
```

`src/Enums/NightlySweepStatus.php`:

```php
<?php

namespace Statikbe\FilamentVoight\Enums;

enum NightlySweepStatus: string
{
    case Scanning = 'scanning';
    case Recording = 'recording';
    case Completed = 'completed';
    case Failed = 'failed';
}
```

- [ ] **Step 4: Migrations**

`database/migrations/create_voight_nightly_sweeps_table.php.stub` (and an identical `tests/database/migrations/0001_01_01_000024_create_voight_nightly_sweeps_table.php`):

```php
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('voight_nightly_sweeps', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->string('status');
            $table->json('environment_ids');
            $table->timestamp('started_at')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('voight_nightly_sweeps');
    }
};
```

`database/migrations/create_voight_osv_scans_table.php.stub` (and an identical `tests/database/migrations/0001_01_01_000025_create_voight_osv_scans_table.php`):

```php
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('voight_osv_scans', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->string('scanner_scan_id', 26)->nullable()->unique();
            $table->string('type');
            $table->string('status');
            $table->foreignUlid('environment_id')->nullable()->constrained('voight_environments')->cascadeOnDelete();
            $table->foreignUlid('nightly_sweep_id')->nullable()->constrained('voight_nightly_sweeps')->cascadeOnDelete();
            $table->string('trigger');
            $table->json('meta');
            $table->unsignedTinyInteger('resubmits')->default(0);
            $table->string('input_path')->nullable();
            $table->string('error_message', 500)->nullable();
            $table->timestamps();
            $table->index(['nightly_sweep_id', 'status'], 'voight_osv_scans_sweep_status_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('voight_osv_scans');
    }
};
```

In `FilamentVoightServiceProvider::getMigrations()` append, in this order:

```php
            'create_voight_nightly_sweeps_table',
            'create_voight_osv_scans_table',
```

- [ ] **Step 5: Models and factories**

`src/Models/OsvScan.php`:

```php
<?php

namespace Statikbe\FilamentVoight\Models;

use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;
use Statikbe\FilamentVoight\Enums\AuditRunTrigger;
use Statikbe\FilamentVoight\Enums\OsvScanStatus;
use Statikbe\FilamentVoight\Enums\OsvScanType;

/**
 * One asynchronous scan at the OSV scanner service: a lockfile scan for one
 * environment, or one package batch of a nightly sweep.
 *
 * @property string $id
 * @property string|null $scanner_scan_id
 * @property OsvScanType $type
 * @property OsvScanStatus $status
 * @property string|null $environment_id
 * @property string|null $nightly_sweep_id
 * @property AuditRunTrigger $trigger
 * @property array<string, mixed> $meta
 * @property int $resubmits
 * @property string|null $input_path
 * @property string|null $error_message
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
class OsvScan extends Model
{
    use HasFactory;
    use HasUlids;

    protected $table = 'voight_osv_scans';

    protected $guarded = [];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'type' => OsvScanType::class,
            'status' => OsvScanStatus::class,
            'trigger' => AuditRunTrigger::class,
            'meta' => 'array',
            'resubmits' => 'integer',
        ];
    }

    /**
     * @return BelongsTo<Environment, $this>
     */
    public function environment(): BelongsTo
    {
        return $this->belongsTo(Environment::class);
    }

    /**
     * @return BelongsTo<NightlySweep, $this>
     */
    public function nightlySweep(): BelongsTo
    {
        return $this->belongsTo(NightlySweep::class);
    }
}
```

`src/Models/NightlySweep.php`:

```php
<?php

namespace Statikbe\FilamentVoight\Models;

use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;
use Statikbe\FilamentVoight\Enums\NightlySweepStatus;

/**
 * One nightly deduplicated scan: its package batches are OsvScans, and it is
 * recorded for the environments snapshotted in environment_ids once every
 * batch has completed.
 *
 * @property string $id
 * @property NightlySweepStatus $status
 * @property array<int, string> $environment_ids
 * @property Carbon|null $started_at
 * @property Carbon|null $completed_at
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
class NightlySweep extends Model
{
    use HasFactory;
    use HasUlids;

    protected $table = 'voight_nightly_sweeps';

    protected $guarded = [];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'status' => NightlySweepStatus::class,
            'environment_ids' => 'array',
            'started_at' => 'datetime',
            'completed_at' => 'datetime',
        ];
    }

    /**
     * @return HasMany<OsvScan, $this>
     */
    public function scans(): HasMany
    {
        return $this->hasMany(OsvScan::class);
    }
}
```

`database/factories/OsvScanFactory.php`:

```php
<?php

namespace Statikbe\FilamentVoight\Database\Factories;

use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;
use Statikbe\FilamentVoight\Enums\AuditRunTrigger;
use Statikbe\FilamentVoight\Enums\OsvScanStatus;
use Statikbe\FilamentVoight\Enums\OsvScanType;
use Statikbe\FilamentVoight\Models\Environment;
use Statikbe\FilamentVoight\Models\NightlySweep;
use Statikbe\FilamentVoight\Models\OsvScan;

/**
 * @extends Factory<OsvScan>
 */
class OsvScanFactory extends Factory
{
    protected $model = OsvScan::class;

    public function definition(): array
    {
        return [
            'scanner_scan_id' => (string) Str::ulid(),
            'type' => OsvScanType::Locks,
            'status' => OsvScanStatus::Pending,
            'environment_id' => Environment::factory(),
            'nightly_sweep_id' => null,
            'trigger' => AuditRunTrigger::Manual,
            'meta' => [],
            'resubmits' => 0,
            'input_path' => null,
        ];
    }

    public function packages(): static
    {
        return $this->state([
            'type' => OsvScanType::Packages,
            'environment_id' => null,
            'nightly_sweep_id' => NightlySweep::factory(),
            'trigger' => AuditRunTrigger::Nightly,
        ]);
    }
}
```

`database/factories/NightlySweepFactory.php`:

```php
<?php

namespace Statikbe\FilamentVoight\Database\Factories;

use Illuminate\Database\Eloquent\Factories\Factory;
use Statikbe\FilamentVoight\Enums\NightlySweepStatus;
use Statikbe\FilamentVoight\Models\NightlySweep;

/**
 * @extends Factory<NightlySweep>
 */
class NightlySweepFactory extends Factory
{
    protected $model = NightlySweep::class;

    public function definition(): array
    {
        return [
            'status' => NightlySweepStatus::Scanning,
            'environment_ids' => [],
            'started_at' => now(),
        ];
    }
}
```

- [ ] **Step 6: Config**

In `config/filament-voight.php`, replace the `scanner` block with:

```php
    'scanner' => [
        'url' => env('VOIGHT_SCANNER_URL'),
        'packages_url' => env('VOIGHT_SCANNER_PACKAGES_URL'), // removed in Task 8
        'token' => env('VOIGHT_SCANNER_TOKEN'),

        /*
        | Where the scanner posts completion callbacks. Unset uses the package's
        | own route; an empty string disables callbacks (polling still works),
        | e.g. for local development the scanner cannot reach.
        */
        'callback_url' => env('VOIGHT_SCANNER_CALLBACK_URL'),
        'callback_secret' => env('VOIGHT_SCANNER_CALLBACK_SECRET'),

        'batch_size' => (int) env('VOIGHT_SCANNER_BATCH_SIZE', 5000),
        'poll_interval' => (int) env('VOIGHT_SCANNER_POLL_INTERVAL', 15),
        'max_resubmits' => (int) env('VOIGHT_SCANNER_MAX_RESUBMITS', 2),

        /*
        | Cron expression for the nightly deduplicated OSV scan. Defaults to
        | midnight daily. Set to any valid cron expression (e.g. '0 2 * * *'
        | for 02:00) or an empty string to disable the automatic schedule.
        */
        'nightly_cron' => env('VOIGHT_SCANNER_NIGHTLY_CRON', '0 0 * * *'),
    ],
```

In the same file's `models` array add (with `use` statements for both classes):

```php
        'osv_scan' => OsvScan::class,
        'nightly_sweep' => NightlySweep::class,
```

In `src/FilamentVoightConfig.php`, add under `// -- Scanner --`:

```php
    public function getScannerCallbackSecret(): ?string
    {
        $secret = $this->packageConfig('scanner.callback_secret');

        return is_string($secret) && $secret !== '' ? $secret : null;
    }

    public function getScannerPollInterval(): int
    {
        return (int) ($this->packageConfig('scanner.poll_interval') ?? 15);
    }

    public function getScannerMaxResubmits(): int
    {
        return (int) ($this->packageConfig('scanner.max_resubmits') ?? 2);
    }
```

and under `// -- Models --` (with `use` statements):

```php
    /**
     * @return class-string<OsvScan>
     */
    public function getOsvScanModel(): string
    {
        return $this->packageConfig('models.osv_scan', OsvScan::class);
    }

    /**
     * @return class-string<NightlySweep>
     */
    public function getNightlySweepModel(): string
    {
        return $this->packageConfig('models.nightly_sweep', NightlySweep::class);
    }
```

- [ ] **Step 7: Run tests**

Run: `php -d memory_limit=1G vendor/bin/pest --no-coverage tests/Models/OsvScanTest.php tests/database/MigrationTest.php tests/Unit/FilamentVoightConfigTest.php`
Expected: PASS.

Run the full suite: `php -d memory_limit=1G vendor/bin/pest --no-coverage`
Expected: only the 9 pre-existing fixture failures.

- [ ] **Step 8: Format and commit**

```bash
vendor/bin/pint --dirty
git add -A
git commit -m "feat: add OsvScan and NightlySweep models for async scans

Co-Authored-By: Claude Opus 5.5 <noreply@anthropic.com>"
```

---

### Task 2: Scanner client — `createScan`, `upload`, `getScan`

**Files:**
- Create: `src/Support/CreatedScan.php`, `src/Support/ScanStatus.php`, `src/Support/ScanError.php`
- Modify: `src/Services/OsvScannerClient.php` (add methods; keep `scanPackages`/`scanLockfiles` until Task 8)
- Modify: `tests/Pest.php` (add `fakeScanner()`)
- Test: `tests/Services/OsvScannerClientTest.php` (append)

**Interfaces:**
- Consumes: `OsvScanType` (Task 1), `ScanResponse` (existing).
- Produces:
  - `final class CreatedScan { __construct(public string $scanId, public string $uploadUrl, public array $uploadHeaders) }`
  - `final class ScanError { __construct(public string $code, public string $message, public bool $retryable); static fromArray(array $error): self }`
  - `final class ScanStatus { const DONE = 'done'; const FAILED = 'failed'; __construct(public string $status, public ?ScanResponse $result = null, public ?ScanError $error = null); isTerminal(): bool; isDone(): bool }`
  - `OsvScannerClient::createScan(OsvScanType $type, array $meta, ?string $callbackUrl): CreatedScan`
  - `OsvScannerClient::upload(CreatedScan $scan, string $body): void`
  - `OsvScannerClient::getScan(string $scannerScanId): ScanStatus` — `404` → `FAILED` with `ScanError('not_found', …, false)`; `done` → downloads `result_url` into `result`.
  - Test helper `fakeScanner(array $result = [...], string $status = 'done', ?array $error = null): void` — fakes all four calls; each `POST /scans` returns a distinct id `01J9ZK3M4N5P6R7S8T9V0W0001`, `…0002`, …

- [ ] **Step 1: Add the test helper**

Append to `tests/Pest.php`:

```php
/**
 * Fake the async OSV scanner: POST /scans (distinct ids per call), the
 * presigned upload, GET /scans/{id} with the given status, and the result
 * download. Scanner URL must be https://scanner.test/scans.
 *
 * @param  array<string, mixed>  $result  result.json body
 * @param  array<string, mixed>|null  $error  error object for status=failed
 */
function fakeScanner(array $result = ['findings' => [], 'vulnerabilities' => [], 'summary' => []], string $status = 'done', ?array $error = null): void
{
    $counter = 0;

    Illuminate\Support\Facades\Http::fake(function (Illuminate\Http\Client\Request $request) use (&$counter, $result, $status, $error) {
        $url = $request->url();

        if ($request->method() === 'POST' && $url === 'https://scanner.test/scans') {
            $counter++;
            $id = '01J9ZK3M4N5P6R7S8T9V0W' . str_pad((string) $counter, 4, '0', STR_PAD_LEFT);

            return Illuminate\Support\Facades\Http::response([
                'scan_id' => $id,
                'upload' => ['url' => "https://s3.test/upload/{$id}", 'method' => 'PUT', 'headers' => ['Content-Type' => 'application/json'], 'expires_at' => '2026-10-05T10:15:00Z'],
            ], 201);
        }

        if ($request->method() === 'PUT' && str_starts_with($url, 'https://s3.test/upload/')) {
            return Illuminate\Support\Facades\Http::response('', 200);
        }

        if ($request->method() === 'GET' && str_starts_with($url, 'https://scanner.test/scans/')) {
            $id = basename($url);

            return Illuminate\Support\Facades\Http::response([
                'scan_id' => $id,
                'type' => 'locks',
                'status' => $status,
                'created_at' => '2026-10-05T10:00:00Z',
                'result_url' => $status === 'done' ? "https://s3.test/result/{$id}" : null,
                'error' => $error,
            ], 200);
        }

        if ($request->method() === 'GET' && str_starts_with($url, 'https://s3.test/result/')) {
            return Illuminate\Support\Facades\Http::response($result, 200);
        }

        return Illuminate\Support\Facades\Http::response('unexpected request ' . $request->method() . ' ' . $url, 500);
    });
}
```

- [ ] **Step 2: Write the failing client tests**

Append to `tests/Services/OsvScannerClientTest.php`. Leave its `beforeEach` alone (the old `/packages`/`/locks` tests still need it until Task 8); every new test sets `scanner.url` itself:

```php
use Statikbe\FilamentVoight\Enums\OsvScanType;
use Statikbe\FilamentVoight\Support\CreatedScan;
use Statikbe\FilamentVoight\Support\ScanStatus;

it('creates a scan with type, callback and meta, authenticated', function () {
    config()->set('filament-voight.scanner.url', 'https://scanner.test/scans/');
    fakeScanner();

    $created = app(OsvScannerClient::class)->createScan(OsvScanType::Packages, ['batch_id' => 'b1'], 'https://voight.test/cb');

    expect($created)->toBeInstanceOf(CreatedScan::class)
        ->and($created->scanId)->toBe('01J9ZK3M4N5P6R7S8T9V0W0001')
        ->and($created->uploadUrl)->toBe('https://s3.test/upload/01J9ZK3M4N5P6R7S8T9V0W0001')
        ->and($created->uploadHeaders)->toBe(['Content-Type' => 'application/json']);

    Http::assertSent(fn ($request) => $request->method() === 'POST'
        && $request->url() === 'https://scanner.test/scans'
        && $request->hasHeader('Authorization', 'Bearer secret')
        && $request['type'] === 'packages'
        && $request['callback_url'] === 'https://voight.test/cb'
        && $request['meta'] === ['batch_id' => 'b1']);
});

it('sends empty meta as a JSON object, not an array', function () {
    config()->set('filament-voight.scanner.url', 'https://scanner.test/scans');
    fakeScanner();

    app(OsvScannerClient::class)->createScan(OsvScanType::Locks, [], null);

    Http::assertSent(fn ($request) => $request->method() === 'POST' && str_contains($request->body(), '"meta":{}'));
});

it('uploads to the presigned url with its headers and without the bearer token', function () {
    config()->set('filament-voight.scanner.url', 'https://scanner.test/scans');
    fakeScanner();

    app(OsvScannerClient::class)->upload(
        new CreatedScan('S1', 'https://s3.test/upload/S1', ['Content-Type' => 'application/json']),
        '{"packages":[]}',
    );

    Http::assertSent(fn ($request) => $request->method() === 'PUT'
        && $request->url() === 'https://s3.test/upload/S1'
        && $request->body() === '{"packages":[]}'
        && $request->hasHeader('Content-Type', 'application/json')
        && ! $request->hasHeader('Authorization'));
});

it('returns a done status with the downloaded result', function () {
    config()->set('filament-voight.scanner.url', 'https://scanner.test/scans');
    fakeScanner(['findings' => [['ecosystem' => 'npm', 'name' => 'lodash', 'version' => '4.17.15', 'vulnerability_id' => 'GHSA-x', 'max_severity' => '8.1']], 'vulnerabilities' => ['GHSA-x' => ['id' => 'GHSA-x']], 'summary' => []]);

    $status = app(OsvScannerClient::class)->getScan('01J9ZK3M4N5P6R7S8T9V0W0001');

    expect($status->isDone())->toBeTrue()
        ->and($status->isTerminal())->toBeTrue()
        ->and($status->result->findings)->toHaveCount(1);

    Http::assertSent(fn ($request) => $request->url() === 'https://s3.test/result/01J9ZK3M4N5P6R7S8T9V0W0001'
        && ! $request->hasHeader('Authorization'));
});

it('returns a failed status with the scanner error', function () {
    config()->set('filament-voight.scanner.url', 'https://scanner.test/scans');
    fakeScanner(status: 'failed', error: ['code' => 'scan_failed', 'message' => 'boom', 'retryable' => true]);

    $status = app(OsvScannerClient::class)->getScan('S1');

    expect($status->status)->toBe(ScanStatus::FAILED)
        ->and($status->error->code)->toBe('scan_failed')
        ->and($status->error->message)->toBe('boom')
        ->and($status->error->retryable)->toBeTrue()
        ->and($status->result)->toBeNull();
});

it('returns a non-terminal status while the scan runs', function () {
    config()->set('filament-voight.scanner.url', 'https://scanner.test/scans');
    fakeScanner(status: 'running');

    $status = app(OsvScannerClient::class)->getScan('S1');

    expect($status->status)->toBe('running')->and($status->isTerminal())->toBeFalse();
});

it('treats an unknown scan as a permanent failure', function () {
    config()->set('filament-voight.scanner.url', 'https://scanner.test/scans');
    Http::fake(['scanner.test/scans/*' => Http::response(['error' => 'Scan not found'], 404)]);

    $status = app(OsvScannerClient::class)->getScan('S1');

    expect($status->status)->toBe(ScanStatus::FAILED)
        ->and($status->error->code)->toBe('not_found')
        ->and($status->error->retryable)->toBeFalse();
});

it('throws on scanner errors so the calling job retries', function () {
    config()->set('filament-voight.scanner.url', 'https://scanner.test/scans');
    Http::fake(['scanner.test/scans' => Http::response('boom', 503)]);

    app(OsvScannerClient::class)->createScan(OsvScanType::Locks, [], null);
})->throws(RuntimeException::class, 'OSV scanner POST /scans returned HTTP 503');

it('throws when the scanner url is not configured', function () {
    config()->set('filament-voight.scanner.url', null);

    app(OsvScannerClient::class)->getScan('S1');
})->throws(RuntimeException::class, 'OSV scanner URL is not configured. Set VOIGHT_SCANNER_URL.');
```

- [ ] **Step 3: Run to verify failure**

Run: `php -d memory_limit=1G vendor/bin/pest --no-coverage tests/Services/OsvScannerClientTest.php`
Expected: FAIL — `createScan` undefined.

- [ ] **Step 4: Implement the DTOs**

`src/Support/CreatedScan.php`:

```php
<?php

namespace Statikbe\FilamentVoight\Support;

final class CreatedScan
{
    /**
     * @param  array<string, string>  $uploadHeaders  headers the presigned upload was signed with
     */
    public function __construct(
        public string $scanId,
        public string $uploadUrl,
        public array $uploadHeaders,
    ) {}
}
```

`src/Support/ScanError.php`:

```php
<?php

namespace Statikbe\FilamentVoight\Support;

final class ScanError
{
    public function __construct(
        public string $code,
        public string $message,
        public bool $retryable,
    ) {}

    /**
     * @param  array<string, mixed>  $error
     */
    public static function fromArray(array $error): self
    {
        return new self(
            code: (string) ($error['code'] ?? 'unknown'),
            message: (string) ($error['message'] ?? 'Scanner reported a failure without details'),
            retryable: (bool) ($error['retryable'] ?? true),
        );
    }
}
```

`src/Support/ScanStatus.php`:

```php
<?php

namespace Statikbe\FilamentVoight\Support;

final class ScanStatus
{
    public const DONE = 'done';

    public const FAILED = 'failed';

    public function __construct(
        public string $status,
        public ?ScanResponse $result = null,
        public ?ScanError $error = null,
    ) {}

    public function isTerminal(): bool
    {
        return $this->status === self::DONE || $this->status === self::FAILED;
    }

    public function isDone(): bool
    {
        return $this->status === self::DONE;
    }
}
```

- [ ] **Step 5: Implement the client methods**

Add to `src/Services/OsvScannerClient.php` (new `use` lines: `Illuminate\Http\Client\PendingRequest`, `Illuminate\Http\Client\Response`, `Statikbe\FilamentVoight\Enums\OsvScanType`, `Statikbe\FilamentVoight\Support\CreatedScan`, `Statikbe\FilamentVoight\Support\ScanError`, `Statikbe\FilamentVoight\Support\ScanStatus`):

```php
    /**
     * @param  array<string, mixed>  $meta
     */
    public function createScan(OsvScanType $type, array $meta, ?string $callbackUrl): CreatedScan
    {
        $response = $this->api()->post($this->scansUrl(), [
            'type' => $type->value,
            'callback_url' => $callbackUrl,
            // An object even when empty: the scanner rejects a JSON array.
            'meta' => (object) $meta,
        ]);

        $this->ensureSuccessful($response, 'POST /scans');

        return new CreatedScan(
            scanId: (string) $response->json('scan_id'),
            uploadUrl: (string) $response->json('upload.url'),
            uploadHeaders: (array) ($response->json('upload.headers') ?? []),
        );
    }

    public function upload(CreatedScan $scan, string $body): void
    {
        // Presigned S3 URL: the signature is the credential, so no bearer token.
        $response = Http::timeout(60)
            ->connectTimeout(10)
            ->withHeaders($scan->uploadHeaders)
            ->withBody($body, $scan->uploadHeaders['Content-Type'] ?? 'application/json')
            ->put($scan->uploadUrl);

        if (! $response->successful()) {
            throw new RuntimeException("Uploading OSV scan input returned HTTP {$response->status()}");
        }
    }

    public function getScan(string $scannerScanId): ScanStatus
    {
        $response = $this->api()->get($this->scansUrl() . '/' . $scannerScanId);

        if ($response->status() === 404) {
            return new ScanStatus(ScanStatus::FAILED, error: new ScanError('not_found', "The scanner has no scan {$scannerScanId}", false));
        }

        $this->ensureSuccessful($response, 'GET /scans/{id}');

        $status = (string) $response->json('status');

        if ($status === ScanStatus::FAILED) {
            return new ScanStatus($status, error: ScanError::fromArray((array) ($response->json('error') ?? [])));
        }

        if ($status !== ScanStatus::DONE) {
            return new ScanStatus($status);
        }

        $result = Http::timeout(60)->connectTimeout(10)->get((string) $response->json('result_url'));

        if (! $result->successful()) {
            throw new RuntimeException("Downloading OSV scan result returned HTTP {$result->status()}");
        }

        return new ScanStatus($status, result: ScanResponse::fromArray((array) $result->json()));
    }

    private function api(): PendingRequest
    {
        return Http::timeout(15)
            ->connectTimeout(10)
            ->acceptJson()
            ->withToken(FilamentVoight::config()->getScannerToken() ?? '');
    }

    private function scansUrl(): string
    {
        $url = FilamentVoight::config()->getScannerUrl();

        if (! $url) {
            throw new RuntimeException('OSV scanner URL is not configured. Set VOIGHT_SCANNER_URL.');
        }

        return rtrim($url, '/');
    }

    private function ensureSuccessful(Response $response, string $call): void
    {
        if (! $response->successful()) {
            throw new RuntimeException(
                "OSV scanner {$call} returned HTTP {$response->status()}: " . mb_substr($response->body(), 0, 500)
            );
        }
    }
```

- [ ] **Step 6: Run tests**

Run: `php -d memory_limit=1G vendor/bin/pest --no-coverage tests/Services/OsvScannerClientTest.php`
Expected: PASS (old and new tests).

- [ ] **Step 7: Format and commit**

```bash
vendor/bin/pint --dirty
git add -A
git commit -m "feat: add async scan methods to the OSV scanner client

Co-Authored-By: Claude Opus 5.5 <noreply@anthropic.com>"
```

---

### Task 3: Failed-run recording and `RecordNightlySweepJob`

**Files:**
- Modify: `src/Services/RecordEnvironmentAuditRunsService.php`
- Create: `src/Jobs/RecordNightlySweepJob.php`
- Test: `tests/Services/RecordEnvironmentAuditRunsServiceTest.php` (create if missing; otherwise append), `tests/Jobs/RecordNightlySweepJobTest.php`

**Interfaces:**
- Consumes: `NightlySweep`, `OsvScan`, `NightlySweepStatus` (Task 1); `OsvScannerClient::getScan`, `ScanStatus` (Task 2); `fakeScanner` (Task 2).
- Produces:
  - `RecordEnvironmentAuditRunsService::recordFailed(string $environmentId, AuditRunTrigger $trigger, string $message): AuditRun`
  - `RecordEnvironmentAuditRunsService::recordFailedForEnvironments(array $environmentIds, AuditRunTrigger $trigger, string $message): void` — skips ids that no longer exist.
  - `RecordNightlySweepJob(NightlySweep $sweep)` — `handle(OsvScannerClient, RecordEnvironmentAuditRunsService)`; `failed(Throwable)`.

- [ ] **Step 1: Write the failing tests**

`tests/Services/RecordEnvironmentAuditRunsServiceTest.php` (append if it exists):

```php
<?php

use Statikbe\FilamentVoight\Enums\AuditRunStatus;
use Statikbe\FilamentVoight\Enums\AuditRunTrigger;
use Statikbe\FilamentVoight\Models\AuditRun;
use Statikbe\FilamentVoight\Models\Environment;
use Statikbe\FilamentVoight\Services\RecordEnvironmentAuditRunsService;

it('records one failed run with a truncated message', function () {
    $environment = Environment::factory()->create();

    $run = app(RecordEnvironmentAuditRunsService::class)->recordFailed($environment->id, AuditRunTrigger::PostSync, str_repeat('x', 600));

    expect($run->status)->toBe(AuditRunStatus::Failed)
        ->and($run->trigger)->toBe(AuditRunTrigger::PostSync)
        ->and($run->completed_at)->not->toBeNull()
        ->and(mb_strlen($run->error_message))->toBe(500);
});

it('records failed runs only for environments that still exist', function () {
    $kept = Environment::factory()->create();

    app(RecordEnvironmentAuditRunsService::class)->recordFailedForEnvironments([$kept->id, '01J9ZK3M4N5P6R7S8T9V0WGONE'], AuditRunTrigger::Nightly, 'boom');

    expect(AuditRun::count())->toBe(1)
        ->and(AuditRun::first()->environment_id)->toBe($kept->id);
});
```

`tests/Jobs/RecordNightlySweepJobTest.php`:

```php
<?php

use Statikbe\FilamentVoight\Enums\AuditRunStatus;
use Statikbe\FilamentVoight\Enums\AuditRunTrigger;
use Statikbe\FilamentVoight\Enums\NightlySweepStatus;
use Statikbe\FilamentVoight\Enums\OsvScanStatus;
use Statikbe\FilamentVoight\Jobs\RecordNightlySweepJob;
use Statikbe\FilamentVoight\Models\AuditFinding;
use Statikbe\FilamentVoight\Models\AuditRun;
use Statikbe\FilamentVoight\Models\Environment;
use Statikbe\FilamentVoight\Models\EnvironmentPackage;
use Statikbe\FilamentVoight\Models\NightlySweep;
use Statikbe\FilamentVoight\Models\OsvScan;
use Statikbe\FilamentVoight\Models\Package;

beforeEach(function () {
    config()->set('filament-voight.scanner.url', 'https://scanner.test/scans');
    config()->set('filament-voight.scanner.token', 'secret');
});

function laravelVulnerabilityResult(): array
{
    return [
        'findings' => [
            ['ecosystem' => 'Packagist', 'name' => 'laravel/framework', 'version' => '10.9.0', 'vulnerability_id' => 'GHSA-5vg9', 'max_severity' => '9.1'],
        ],
        'vulnerabilities' => ['GHSA-5vg9' => [
            'id' => 'GHSA-5vg9', 'summary' => 's', 'database_specific' => ['severity' => 'HIGH'],
            'affected' => [['package' => ['name' => 'laravel/framework'], 'ranges' => [['events' => [['introduced' => '0'], ['fixed' => 'v10.48.29']]]]]],
        ]],
        'summary' => ['skipped_packages' => []],
    ];
}

it('records every sweep environment from the merged batch results and completes the sweep', function () {
    $laravel = Package::factory()->composer()->create(['name' => 'laravel/framework']);
    [$a, $b] = Environment::factory()->count(2)->create()->all();
    foreach ([$a, $b] as $environment) {
        EnvironmentPackage::factory()->create(['environment_id' => $environment->id, 'package_id' => $laravel->id, 'version' => '10.9.0']);
    }
    $sweep = NightlySweep::factory()->create(['status' => NightlySweepStatus::Recording, 'environment_ids' => [$a->id, $b->id]]);
    OsvScan::factory()->packages()->count(2)->create(['nightly_sweep_id' => $sweep->id, 'status' => OsvScanStatus::Completed]);
    fakeScanner(laravelVulnerabilityResult());

    RecordNightlySweepJob::dispatchSync($sweep);

    expect(AuditRun::where('trigger', AuditRunTrigger::Nightly)->where('status', AuditRunStatus::Completed)->count())->toBe(2)
        ->and(AuditFinding::count())->toBe(2)
        ->and($sweep->refresh()->status)->toBe(NightlySweepStatus::Completed)
        ->and($sweep->completed_at)->not->toBeNull();
});

it('skips environments deleted since the sweep started', function () {
    $kept = Environment::factory()->create();
    $sweep = NightlySweep::factory()->create(['status' => NightlySweepStatus::Recording, 'environment_ids' => [$kept->id, '01J9ZK3M4N5P6R7S8T9V0WGONE']]);
    OsvScan::factory()->packages()->create(['nightly_sweep_id' => $sweep->id, 'status' => OsvScanStatus::Completed]);
    fakeScanner();

    RecordNightlySweepJob::dispatchSync($sweep);

    expect(AuditRun::pluck('environment_id')->all())->toBe([$kept->id]);
});

it('throws, recording nothing, when a batch result is no longer available', function () {
    $environment = Environment::factory()->create();
    $sweep = NightlySweep::factory()->create(['status' => NightlySweepStatus::Recording, 'environment_ids' => [$environment->id]]);
    OsvScan::factory()->packages()->create(['nightly_sweep_id' => $sweep->id, 'status' => OsvScanStatus::Completed]);
    fakeScanner(status: 'failed', error: ['code' => 'not_found', 'message' => 'gone', 'retryable' => false]);

    expect(fn () => app()->call([new RecordNightlySweepJob($sweep), 'handle']))->toThrow(RuntimeException::class);
    expect(AuditRun::count())->toBe(0);
});

it('marks the sweep failed and records a failed run per environment once retries are exhausted', function () {
    $environments = Environment::factory()->count(2)->create();
    $sweep = NightlySweep::factory()->create(['status' => NightlySweepStatus::Recording, 'environment_ids' => $environments->pluck('id')->all()]);

    (new RecordNightlySweepJob($sweep))->failed(new RuntimeException('db down'));
    (new RecordNightlySweepJob($sweep))->failed(new RuntimeException('db down')); // second call is a no-op

    expect($sweep->refresh()->status)->toBe(NightlySweepStatus::Failed)
        ->and(AuditRun::where('status', AuditRunStatus::Failed)->count())->toBe(2)
        ->and(AuditRun::first()->error_message)->toBe('Recording the nightly scan failed: db down');
});
```

- [ ] **Step 2: Run to verify failure**

Run: `php -d memory_limit=1G vendor/bin/pest --no-coverage tests/Services/RecordEnvironmentAuditRunsServiceTest.php tests/Jobs/RecordNightlySweepJobTest.php`
Expected: FAIL — methods/class missing.

- [ ] **Step 3: Implement the recorder methods**

Add to `RecordEnvironmentAuditRunsService`:

```php
    public function recordFailed(string $environmentId, AuditRunTrigger $trigger, string $message): AuditRun
    {
        /** @var class-string<AuditRun> $auditRunModel */
        $auditRunModel = FilamentVoight::config()->getAuditRunModel();

        return $auditRunModel::create([
            'environment_id' => $environmentId,
            'status' => AuditRunStatus::Failed,
            'trigger' => $trigger,
            'started_at' => now(),
            'completed_at' => now(),
            'error_message' => mb_substr($message, 0, 500),
        ]);
    }

    /**
     * @param  array<int, string>  $environmentIds
     */
    public function recordFailedForEnvironments(array $environmentIds, AuditRunTrigger $trigger, string $message): void
    {
        /** @var class-string<Environment> $environmentModel */
        $environmentModel = FilamentVoight::config()->getEnvironmentModel();

        // Environments deleted since the scan started have nothing to record against.
        foreach ($environmentModel::query()->whereKey($environmentIds)->pluck('id') as $environmentId) {
            $this->recordFailed((string) $environmentId, $trigger, $message);
        }
    }
```

- [ ] **Step 4: Implement `RecordNightlySweepJob`**

`src/Jobs/RecordNightlySweepJob.php` (the two merge helpers are copied verbatim from `RunNightlyOsvScanJob`, which loses them in Task 7):

```php
<?php

namespace Statikbe\FilamentVoight\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\DB;
use RuntimeException;
use Statikbe\FilamentVoight\Enums\AuditRunTrigger;
use Statikbe\FilamentVoight\Enums\NightlySweepStatus;
use Statikbe\FilamentVoight\Facades\FilamentVoight;
use Statikbe\FilamentVoight\Models\Environment;
use Statikbe\FilamentVoight\Models\NightlySweep;
use Statikbe\FilamentVoight\Services\OsvScannerClient;
use Statikbe\FilamentVoight\Services\RecordEnvironmentAuditRunsService;
use Throwable;

/**
 * Records a nightly sweep once every package batch has completed: merges the
 * batch results and records one AuditRun per snapshotted environment.
 */
class RecordNightlySweepJob implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    public int $tries = 3;

    public int $timeout = 900;

    /** @var array<int> */
    public array $backoff = [30, 120, 300];

    public bool $deleteWhenMissingModels = true;

    public function __construct(public NightlySweep $sweep) {}

    public function handle(OsvScannerClient $client, RecordEnvironmentAuditRunsService $recorder): void
    {
        $findingsMap = [];
        $vulnerabilities = [];
        $maxSeverityById = [];

        // Fetch everything before writing anything: a missing batch must not
        // leave half the environments recorded.
        foreach ($this->sweep->scans()->get() as $scan) {
            $status = $client->getScan((string) $scan->scanner_scan_id);

            if (! $status->isDone() || $status->result === null) {
                throw new RuntimeException("Nightly batch {$scan->id} has no result to record (scanner status: {$status->status})");
            }

            $findingsMap = $this->mergeFindings($findingsMap, $status->result->findingsByPackageKey());
            $vulnerabilities += $status->result->vulnerabilities;
            $maxSeverityById = $this->mergeMaxSeverity($maxSeverityById, $status->result->maxSeverityById());
        }

        /** @var class-string<Environment> $environmentModel */
        $environmentModel = FilamentVoight::config()->getEnvironmentModel();

        DB::transaction(function () use ($recorder, $environmentModel, $findingsMap, $vulnerabilities, $maxSeverityById): void {
            $models = $recorder->upsertVulnerabilities($vulnerabilities, $maxSeverityById);

            foreach ($environmentModel::query()->whereKey($this->sweep->environment_ids)->get() as $environment) {
                $recorder->record($environment, $findingsMap, $models, $vulnerabilities, AuditRunTrigger::Nightly);
            }

            $this->sweep->update(['status' => NightlySweepStatus::Completed, 'completed_at' => now()]);
        });
    }

    public function failed(Throwable $e): void
    {
        /** @var class-string<NightlySweep> $sweepModel */
        $sweepModel = FilamentVoight::config()->getNightlySweepModel();

        $claimed = $sweepModel::query()
            ->whereKey($this->sweep->id)
            ->where('status', NightlySweepStatus::Recording)
            ->update(['status' => NightlySweepStatus::Failed, 'completed_at' => now()]);

        if ($claimed === 0) {
            return;
        }

        app(RecordEnvironmentAuditRunsService::class)->recordFailedForEnvironments(
            $this->sweep->environment_ids,
            AuditRunTrigger::Nightly,
            'Recording the nightly scan failed: ' . $e->getMessage(),
        );
    }

    /**
     * @param  array<string, array<int, array{vulnerability_id: string, max_severity: string|null}>>  $into
     * @param  array<string, array<int, array{vulnerability_id: string, max_severity: string|null}>>  $from
     * @return array<string, array<int, array{vulnerability_id: string, max_severity: string|null}>>
     */
    private function mergeFindings(array $into, array $from): array
    {
        foreach ($from as $key => $findings) {
            $into[$key] = array_merge($into[$key] ?? [], $findings);
        }

        return $into;
    }

    /**
     * @param  array<string, string|null>  $into
     * @param  array<string, string|null>  $from
     * @return array<string, string|null>
     */
    private function mergeMaxSeverity(array $into, array $from): array
    {
        foreach ($from as $id => $severity) {
            if (! array_key_exists($id, $into)) {
                $into[$id] = $severity;

                continue;
            }

            if ($severity !== null && (float) $severity > (float) ($into[$id] ?? 0)) {
                $into[$id] = $severity;
            }
        }

        return $into;
    }
}
```

- [ ] **Step 5: Run tests**

Run: `php -d memory_limit=1G vendor/bin/pest --no-coverage tests/Services/RecordEnvironmentAuditRunsServiceTest.php tests/Jobs/RecordNightlySweepJobTest.php`
Expected: PASS.

- [ ] **Step 6: Format and commit**

```bash
vendor/bin/pint --dirty
git add -A
git commit -m "feat: record nightly sweeps from merged batch results

Co-Authored-By: Claude Opus 5.5 <noreply@anthropic.com>"
```

---

### Task 4: Scan lifecycle — submitter, submit/poll/complete jobs, result handler

**Files:**
- Create: `src/Services/OsvScanSubmitter.php`, `src/Services/OsvScanResultHandler.php`
- Create: `src/Jobs/SubmitOsvScanJob.php`, `src/Jobs/PollOsvScanJob.php`, `src/Jobs/CompleteOsvScanJob.php`
- Modify: `src/FilamentVoightConfig.php` (`getScannerCallbackUrl()`)
- Test: `tests/Services/OsvScanSubmitterTest.php`, `tests/Services/OsvScanResultHandlerTest.php`, `tests/Jobs/OsvScanLifecycleJobsTest.php`

**Interfaces:**
- Consumes: Tasks 1–3 (`OsvScan`, enums, client + DTOs, recorder, `RecordNightlySweepJob`, `fakeScanner`); existing `SendAuditAlertsJob`.
- Produces:
  - `FilamentVoightConfig::getScannerCallbackUrl(): ?string` — `null` config → `route('voight.scans.callback')` if that route exists, else `null`; `''` → `null`.
  - `OsvScanSubmitter::start(OsvScanType $type, string $input, array $meta, AuditRunTrigger $trigger, ?string $environmentId = null, ?string $nightlySweepId = null): OsvScan` — saves input to `voight/scans/{id}/input.json` on the lockfiles disk, dispatches `SubmitOsvScanJob` after commit.
  - `SubmitOsvScanJob(OsvScan $scan)`, `PollOsvScanJob(OsvScan $scan, string $scannerScanId)`, `CompleteOsvScanJob(OsvScan $scan, string $scannerScanId)` — all `$deleteWhenMissingModels = true`.
  - `OsvScanResultHandler::handleTerminal(OsvScan $scan, string $scannerScanId, ScanStatus $status): void`
  - `OsvScanResultHandler::failForGood(OsvScan $scan, string $message, ?string $scannerScanId = null): void`

- [ ] **Step 1: Write the failing handler tests**

`tests/Services/OsvScanResultHandlerTest.php`:

```php
<?php

use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Statikbe\FilamentVoight\Enums\AuditRunStatus;
use Statikbe\FilamentVoight\Enums\AuditRunTrigger;
use Statikbe\FilamentVoight\Enums\NightlySweepStatus;
use Statikbe\FilamentVoight\Enums\OsvScanStatus;
use Statikbe\FilamentVoight\Jobs\RecordNightlySweepJob;
use Statikbe\FilamentVoight\Jobs\SendAuditAlertsJob;
use Statikbe\FilamentVoight\Jobs\SubmitOsvScanJob;
use Statikbe\FilamentVoight\Models\AuditFinding;
use Statikbe\FilamentVoight\Models\AuditRun;
use Statikbe\FilamentVoight\Models\Environment;
use Statikbe\FilamentVoight\Models\EnvironmentPackage;
use Statikbe\FilamentVoight\Models\NightlySweep;
use Statikbe\FilamentVoight\Models\OsvScan;
use Statikbe\FilamentVoight\Models\Package;
use Statikbe\FilamentVoight\Services\OsvScanResultHandler;
use Statikbe\FilamentVoight\Support\ScanError;
use Statikbe\FilamentVoight\Support\ScanResponse;
use Statikbe\FilamentVoight\Support\ScanStatus;

beforeEach(function () {
    Storage::fake('voight-lockfiles');
    Bus::fake([SendAuditAlertsJob::class, SubmitOsvScanJob::class, RecordNightlySweepJob::class]);
});

function doneScanStatus(array $findings = [], array $vulnerabilities = []): ScanStatus
{
    return new ScanStatus(ScanStatus::DONE, ScanResponse::fromArray(['findings' => $findings, 'vulnerabilities' => $vulnerabilities]));
}

function failedScanStatus(string $code = 'scan_failed', bool $retryable = true): ScanStatus
{
    return new ScanStatus(ScanStatus::FAILED, error: new ScanError($code, 'boom', $retryable));
}

function scanResultHandler(): OsvScanResultHandler
{
    return app(OsvScanResultHandler::class);
}

it('records a done locks scan once, dispatches alerts, deletes its input', function () {
    $laravel = Package::factory()->composer()->create(['name' => 'laravel/framework']);
    $environment = Environment::factory()->create();
    EnvironmentPackage::factory()->create(['environment_id' => $environment->id, 'package_id' => $laravel->id, 'version' => '10.9.0']);
    Storage::disk('voight-lockfiles')->put('voight/scans/x/input.json', '{}');
    $scan = OsvScan::factory()->create(['environment_id' => $environment->id, 'trigger' => AuditRunTrigger::PostSync, 'input_path' => 'voight/scans/x/input.json']);

    $status = doneScanStatus(
        [['ecosystem' => 'Packagist', 'name' => 'laravel/framework', 'version' => 'v10.9.0', 'vulnerability_id' => 'GHSA-5vg9', 'max_severity' => '9.1']],
        ['GHSA-5vg9' => ['id' => 'GHSA-5vg9', 'summary' => 's', 'affected' => [['package' => ['name' => 'laravel/framework'], 'ranges' => [['events' => [['introduced' => '0'], ['fixed' => 'v10.48.29']]]]]]]],
    );

    scanResultHandler()->handleTerminal($scan, $scan->scanner_scan_id, $status);
    scanResultHandler()->handleTerminal($scan, $scan->scanner_scan_id, $status); // callback + poller race

    $run = AuditRun::sole();
    expect($run->trigger)->toBe(AuditRunTrigger::PostSync)
        ->and($run->status)->toBe(AuditRunStatus::Completed)
        ->and(AuditFinding::where('audit_run_id', $run->id)->count())->toBe(1)
        ->and($scan->refresh()->status)->toBe(OsvScanStatus::Completed)
        ->and($scan->input_path)->toBeNull();
    Storage::disk('voight-lockfiles')->assertMissing('voight/scans/x/input.json');
    Bus::assertDispatchedTimes(SendAuditAlertsJob::class, 1);
});

it('ignores a terminal status for a scanner scan id that a resubmit replaced', function () {
    $scan = OsvScan::factory()->create(['scanner_scan_id' => '01J9ZK3M4N5P6R7S8T9V0WNEW1']);

    scanResultHandler()->handleTerminal($scan, '01J9ZK3M4N5P6R7S8T9V0WOLD1', doneScanStatus());
    scanResultHandler()->failForGood($scan, 'poll deadline exceeded', '01J9ZK3M4N5P6R7S8T9V0WOLD1');

    expect($scan->refresh()->status)->toBe(OsvScanStatus::Pending)
        ->and(AuditRun::count())->toBe(0);
});

it('resubmits a retryable failure until max_resubmits, then records one failed run', function () {
    config()->set('filament-voight.scanner.max_resubmits', 2);
    $scan = OsvScan::factory()->create(['trigger' => AuditRunTrigger::Manual]);

    foreach ([1, 2] as $attempt) {
        scanResultHandler()->handleTerminal($scan, $scan->scanner_scan_id, failedScanStatus());
        $scan->refresh();
        expect($scan->status)->toBe(OsvScanStatus::Pending)
            ->and($scan->resubmits)->toBe($attempt)
            ->and($scan->scanner_scan_id)->toBeNull();
        $scan->update(['scanner_scan_id' => (string) Str::ulid()]); // what SubmitOsvScanJob does
    }

    scanResultHandler()->handleTerminal($scan, $scan->scanner_scan_id, failedScanStatus());

    Bus::assertDispatchedTimes(SubmitOsvScanJob::class, 2);
    expect($scan->refresh()->status)->toBe(OsvScanStatus::Failed)
        ->and($scan->error_message)->toBe('scan_failed: boom')
        ->and(AuditRun::sole()->status)->toBe(AuditRunStatus::Failed)
        ->and(AuditRun::sole()->error_message)->toBe('scan_failed: boom');
});

it('does not resubmit a non-retryable failure', function () {
    $scan = OsvScan::factory()->create();

    scanResultHandler()->handleTerminal($scan, $scan->scanner_scan_id, failedScanStatus('invalid_input', false));

    Bus::assertNotDispatched(SubmitOsvScanJob::class);
    expect($scan->refresh()->status)->toBe(OsvScanStatus::Failed)
        ->and(AuditRun::count())->toBe(1);
});

it('completes a batch and starts recording only when the last batch of the sweep completes', function () {
    $sweep = NightlySweep::factory()->create();
    [$first, $second] = OsvScan::factory()->packages()->count(2)->create(['nightly_sweep_id' => $sweep->id])->all();

    scanResultHandler()->handleTerminal($first, $first->scanner_scan_id, doneScanStatus());
    Bus::assertNotDispatched(RecordNightlySweepJob::class);
    expect($sweep->refresh()->status)->toBe(NightlySweepStatus::Scanning);

    scanResultHandler()->handleTerminal($second, $second->scanner_scan_id, doneScanStatus());
    scanResultHandler()->handleTerminal($second, $second->scanner_scan_id, doneScanStatus());

    Bus::assertDispatchedTimes(RecordNightlySweepJob::class, 1);
    expect($sweep->refresh()->status)->toBe(NightlySweepStatus::Recording);
});

it('fails the sweep once, records failed runs for its environments, and never resubmits its other batches', function () {
    $environments = Environment::factory()->count(2)->create();
    $sweep = NightlySweep::factory()->create(['environment_ids' => $environments->pluck('id')->all()]);
    [$first, $second] = OsvScan::factory()->packages()->count(2)->create(['nightly_sweep_id' => $sweep->id])->all();

    scanResultHandler()->handleTerminal($first, $first->scanner_scan_id, failedScanStatus('invalid_input', false));
    scanResultHandler()->handleTerminal($second, $second->scanner_scan_id, failedScanStatus()); // retryable, but the sweep already failed

    Bus::assertNotDispatched(SubmitOsvScanJob::class);
    expect($sweep->refresh()->status)->toBe(NightlySweepStatus::Failed)
        ->and(AuditRun::where('status', AuditRunStatus::Failed)->count())->toBe(2)
        ->and(AuditRun::first()->error_message)->toBe('Nightly scan batch failed: invalid_input: boom')
        ->and($second->refresh()->status)->toBe(OsvScanStatus::Failed);
});

it('ignores late completions of batches in a failed sweep', function () {
    $sweep = NightlySweep::factory()->create(['status' => NightlySweepStatus::Failed]);
    $late = OsvScan::factory()->packages()->create(['nightly_sweep_id' => $sweep->id]);

    scanResultHandler()->handleTerminal($late, $late->scanner_scan_id, doneScanStatus());

    Bus::assertNotDispatched(RecordNightlySweepJob::class);
    expect($late->refresh()->status)->toBe(OsvScanStatus::Completed)
        ->and($sweep->refresh()->status)->toBe(NightlySweepStatus::Failed);
});

it('failForGood only acts on a pending scan', function () {
    $scan = OsvScan::factory()->create(['status' => OsvScanStatus::Completed]);

    scanResultHandler()->failForGood($scan, 'too late');

    expect($scan->refresh()->status)->toBe(OsvScanStatus::Completed)
        ->and(AuditRun::count())->toBe(0);
});
```

- [ ] **Step 2: Write the failing submitter and job tests**

`tests/Services/OsvScanSubmitterTest.php`:

```php
<?php

use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Storage;
use Statikbe\FilamentVoight\Enums\AuditRunTrigger;
use Statikbe\FilamentVoight\Enums\OsvScanStatus;
use Statikbe\FilamentVoight\Enums\OsvScanType;
use Statikbe\FilamentVoight\Jobs\SubmitOsvScanJob;
use Statikbe\FilamentVoight\Models\Environment;
use Statikbe\FilamentVoight\Services\OsvScanSubmitter;

it('creates a pending scan, saves its input and queues the submission', function () {
    Storage::fake('voight-lockfiles');
    Bus::fake([SubmitOsvScanJob::class]);
    $environment = Environment::factory()->create();

    $scan = app(OsvScanSubmitter::class)->start(OsvScanType::Locks, '{"files":[]}', ['project_code' => 'p1'], AuditRunTrigger::PostSync, environmentId: $environment->id);

    expect($scan->status)->toBe(OsvScanStatus::Pending)
        ->and($scan->scanner_scan_id)->toBeNull()
        ->and($scan->meta)->toBe(['project_code' => 'p1'])
        ->and($scan->input_path)->toBe("voight/scans/{$scan->id}/input.json");
    Storage::disk('voight-lockfiles')->assertExists($scan->input_path);
    expect(Storage::disk('voight-lockfiles')->get($scan->input_path))->toBe('{"files":[]}');
    Bus::assertDispatched(SubmitOsvScanJob::class, fn ($job) => $job->scan->is($scan));
});
```

`tests/Jobs/OsvScanLifecycleJobsTest.php`:

```php
<?php

use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Statikbe\FilamentVoight\Enums\AuditRunStatus;
use Statikbe\FilamentVoight\Enums\OsvScanStatus;
use Statikbe\FilamentVoight\Jobs\CompleteOsvScanJob;
use Statikbe\FilamentVoight\Jobs\PollOsvScanJob;
use Statikbe\FilamentVoight\Jobs\SendAuditAlertsJob;
use Statikbe\FilamentVoight\Jobs\SubmitOsvScanJob;
use Statikbe\FilamentVoight\Models\AuditRun;
use Statikbe\FilamentVoight\Models\OsvScan;

beforeEach(function () {
    Storage::fake('voight-lockfiles');
    config()->set('filament-voight.scanner.url', 'https://scanner.test/scans');
    config()->set('filament-voight.scanner.token', 'secret');
    config()->set('filament-voight.scanner.callback_url', 'https://voight.test/api/voight/scans/callback');
    config()->set('filament-voight.scanner.poll_interval', 15);
});

function pendingScanWithInput(): OsvScan
{
    Storage::disk('voight-lockfiles')->put('voight/scans/s/input.json', '{"files":[]}');

    return OsvScan::factory()->create(['scanner_scan_id' => null, 'input_path' => 'voight/scans/s/input.json']);
}

it('submit creates the remote scan, uploads the saved input and schedules a poll', function () {
    Bus::fake([PollOsvScanJob::class]);
    fakeScanner();
    $scan = pendingScanWithInput();

    SubmitOsvScanJob::dispatchSync($scan);

    expect($scan->refresh()->scanner_scan_id)->toBe('01J9ZK3M4N5P6R7S8T9V0W0001');
    Http::assertSent(fn ($r) => $r->method() === 'POST' && $r['callback_url'] === 'https://voight.test/api/voight/scans/callback' && $r['type'] === 'locks');
    Http::assertSent(fn ($r) => $r->method() === 'PUT' && $r->body() === '{"files":[]}');
    Bus::assertDispatched(PollOsvScanJob::class, fn ($job) => $job->scannerScanId === '01J9ZK3M4N5P6R7S8T9V0W0001' && $job->delay !== null);
});

it('submit does nothing for a scan that is no longer pending', function () {
    Http::fake();
    $scan = pendingScanWithInput();
    $scan->update(['status' => OsvScanStatus::Completed]);

    SubmitOsvScanJob::dispatchSync($scan);

    Http::assertNothingSent();
});

it('submit failing for good records a failed run', function () {
    $scan = pendingScanWithInput();

    (new SubmitOsvScanJob($scan))->failed(new RuntimeException('scanner unreachable'));

    expect($scan->refresh()->status)->toBe(OsvScanStatus::Failed)
        ->and(AuditRun::sole()->error_message)->toBe('Submitting the scan failed: scanner unreachable');
});

it('poll releases while the scan is running', function () {
    fakeScanner(status: 'running');
    $scan = OsvScan::factory()->create();

    $job = (new PollOsvScanJob($scan, $scan->scanner_scan_id))->withFakeQueueInteractions();
    app()->call([$job, 'handle']);

    $job->assertReleased(delay: 15);
});

it('poll hands a terminal status to the handler', function () {
    Bus::fake([SendAuditAlertsJob::class]);
    fakeScanner();
    $scan = OsvScan::factory()->create();

    $job = (new PollOsvScanJob($scan, $scan->scanner_scan_id))->withFakeQueueInteractions();
    app()->call([$job, 'handle']);

    $job->assertNotReleased();
    expect($scan->refresh()->status)->toBe(OsvScanStatus::Completed)
        ->and(AuditRun::sole()->status)->toBe(AuditRunStatus::Completed);
});

it('poll stops without calling the scanner once its scanner scan id was replaced', function () {
    Http::fake();
    $scan = OsvScan::factory()->create();

    $job = (new PollOsvScanJob($scan, '01J9ZK3M4N5P6R7S8T9V0WOLD1'))->withFakeQueueInteractions();
    app()->call([$job, 'handle']);

    Http::assertNothingSent();
    $job->assertNotReleased();
});

it('poll deadline records a failed run', function () {
    $scan = OsvScan::factory()->create();

    (new PollOsvScanJob($scan, $scan->scanner_scan_id))->failed(new RuntimeException('max attempts'));

    expect($scan->refresh()->status)->toBe(OsvScanStatus::Failed)
        ->and(AuditRun::sole()->error_message)->toBe('Polling the scan failed: max attempts');
});

it('complete ignores a non-terminal status, so an early or forged callback does nothing', function () {
    fakeScanner(status: 'running');
    $scan = OsvScan::factory()->create();

    CompleteOsvScanJob::dispatchSync($scan, $scan->scanner_scan_id);

    expect($scan->refresh()->status)->toBe(OsvScanStatus::Pending);
});

it('drops queued lifecycle jobs whose scan was deleted', function () {
    foreach ([SubmitOsvScanJob::class, PollOsvScanJob::class, CompleteOsvScanJob::class] as $job) {
        expect((new ReflectionClass($job))->getProperty('deleteWhenMissingModels')->getDefaultValue())->toBeTrue();
    }
});

it('polls until the 30-minute deadline', function () {
    $scan = OsvScan::factory()->create();
    $job = new PollOsvScanJob($scan, $scan->scanner_scan_id);

    expect($job->retryUntil()->getTimestamp())->toBeGreaterThanOrEqual(now()->addMinutes(29)->getTimestamp())
        ->and($job->tries)->toBe(0);
});
```

Also add to `tests/Unit/FilamentVoightConfigTest.php`:

```php
it('turns callbacks off with an empty callback url', function () {
    config()->set('filament-voight.scanner.callback_url', '');

    expect(FilamentVoight::config()->getScannerCallbackUrl())->toBeNull();
});

it('uses a configured callback url as-is', function () {
    config()->set('filament-voight.scanner.callback_url', 'https://voight.example/cb');

    expect(FilamentVoight::config()->getScannerCallbackUrl())->toBe('https://voight.example/cb');
});
```

- [ ] **Step 3: Run to verify failure**

Run: `php -d memory_limit=1G vendor/bin/pest --no-coverage tests/Services/OsvScanResultHandlerTest.php tests/Services/OsvScanSubmitterTest.php tests/Jobs/OsvScanLifecycleJobsTest.php tests/Unit/FilamentVoightConfigTest.php`
Expected: FAIL — classes missing.

- [ ] **Step 4: Callback URL getter**

Add to `FilamentVoightConfig` (with `use Illuminate\Support\Facades\Route;`):

```php
    public function getScannerCallbackUrl(): ?string
    {
        $url = $this->packageConfig('scanner.callback_url');

        if ($url === null) {
            // Default to the package's own callback route, when it is registered.
            return Route::has('voight.scans.callback') ? route('voight.scans.callback') : null;
        }

        return $url === '' ? null : (string) $url;
    }
```

- [ ] **Step 5: Implement `OsvScanSubmitter`**

`src/Services/OsvScanSubmitter.php`:

```php
<?php

namespace Statikbe\FilamentVoight\Services;

use Illuminate\Support\Facades\Storage;
use Statikbe\FilamentVoight\Enums\AuditRunTrigger;
use Statikbe\FilamentVoight\Enums\OsvScanStatus;
use Statikbe\FilamentVoight\Enums\OsvScanType;
use Statikbe\FilamentVoight\Facades\FilamentVoight;
use Statikbe\FilamentVoight\Jobs\SubmitOsvScanJob;
use Statikbe\FilamentVoight\Models\OsvScan;

class OsvScanSubmitter
{
    /**
     * Create the scan and queue its submission. The input is saved so a failed
     * scan can be resubmitted without rebuilding it.
     *
     * @param  array<string, mixed>  $meta  echoed by the scanner; batch_id or project_code + environment
     */
    public function start(
        OsvScanType $type,
        string $input,
        array $meta,
        AuditRunTrigger $trigger,
        ?string $environmentId = null,
        ?string $nightlySweepId = null,
    ): OsvScan {
        /** @var class-string<OsvScan> $scanModel */
        $scanModel = FilamentVoight::config()->getOsvScanModel();

        $scan = $scanModel::create([
            'type' => $type,
            'status' => OsvScanStatus::Pending,
            'environment_id' => $environmentId,
            'nightly_sweep_id' => $nightlySweepId,
            'trigger' => $trigger,
            'meta' => $meta,
        ]);

        $path = "voight/scans/{$scan->id}/input.json";
        Storage::disk(FilamentVoight::config()->getLockfilesDisk())->put($path, $input);
        $scan->update(['input_path' => $path]);

        // After commit: a nightly sweep creates all its batches in one transaction.
        SubmitOsvScanJob::dispatch($scan)->afterCommit();

        return $scan;
    }
}
```

- [ ] **Step 6: Implement `OsvScanResultHandler`**

`src/Services/OsvScanResultHandler.php`:

```php
<?php

namespace Statikbe\FilamentVoight\Services;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Storage;
use Statikbe\FilamentVoight\Enums\AuditRunTrigger;
use Statikbe\FilamentVoight\Enums\NightlySweepStatus;
use Statikbe\FilamentVoight\Enums\OsvScanStatus;
use Statikbe\FilamentVoight\Enums\OsvScanType;
use Statikbe\FilamentVoight\Facades\FilamentVoight;
use Statikbe\FilamentVoight\Jobs\RecordNightlySweepJob;
use Statikbe\FilamentVoight\Jobs\SendAuditAlertsJob;
use Statikbe\FilamentVoight\Jobs\SubmitOsvScanJob;
use Statikbe\FilamentVoight\Models\NightlySweep;
use Statikbe\FilamentVoight\Models\OsvScan;
use Statikbe\FilamentVoight\Support\ScanError;
use Statikbe\FilamentVoight\Support\ScanResponse;
use Statikbe\FilamentVoight\Support\ScanStatus;
use Throwable;

/**
 * Acts on a scan's terminal status. Callbacks and polling both end here; the
 * atomic claim makes exactly one of them record the outcome.
 */
class OsvScanResultHandler
{
    public function __construct(private RecordEnvironmentAuditRunsService $recorder) {}

    public function handleTerminal(OsvScan $scan, string $scannerScanId, ScanStatus $status): void
    {
        if (! $this->claim($scan, $scannerScanId)) {
            return;
        }

        $scan->refresh();

        if ($status->isDone() && $status->result !== null) {
            try {
                $scan->type === OsvScanType::Locks
                    ? $this->recordLocks($scan, $status->result)
                    : $this->completeBatch($scan);
            } catch (Throwable $e) {
                $this->markFailed($scan, 'Recording the scan result failed: ' . $e->getMessage());

                throw $e;
            }

            return;
        }

        $error = $status->error ?? new ScanError('unknown', 'Scanner reported a failure without details', true);

        if ($error->retryable && $scan->resubmits < FilamentVoight::config()->getScannerMaxResubmits() && $this->canResubmit($scan)) {
            $scan->update(['status' => OsvScanStatus::Pending, 'scanner_scan_id' => null, 'resubmits' => $scan->resubmits + 1]);
            SubmitOsvScanJob::dispatch($scan);

            return;
        }

        $this->markFailed($scan, "{$error->code}: {$error->message}");
    }

    /**
     * Fail a scan that can no longer finish (submission or polling gave up).
     * With $scannerScanId, only if that submission is still the current one.
     */
    public function failForGood(OsvScan $scan, string $message, ?string $scannerScanId = null): void
    {
        $query = $this->scanQuery()->whereKey($scan->id)->where('status', OsvScanStatus::Pending);

        if ($scannerScanId !== null) {
            $query->where('scanner_scan_id', $scannerScanId);
        }

        if ($query->update(['status' => OsvScanStatus::Processing]) === 0) {
            return;
        }

        $this->markFailed($scan->refresh(), $message);
    }

    private function claim(OsvScan $scan, string $scannerScanId): bool
    {
        return $this->scanQuery()
            ->whereKey($scan->id)
            ->where('scanner_scan_id', $scannerScanId)
            ->where('status', OsvScanStatus::Pending)
            ->update(['status' => OsvScanStatus::Processing]) === 1;
    }

    private function recordLocks(OsvScan $scan, ScanResponse $result): void
    {
        $environment = $scan->environment;

        if ($environment) {
            $models = $this->recorder->upsertVulnerabilities($result->vulnerabilities, $result->maxSeverityById());
            $auditRun = $this->recorder->record($environment, $result->findingsByPackageKey(), $models, $result->vulnerabilities, $scan->trigger);

            SendAuditAlertsJob::dispatch($auditRun);
        }

        $this->finish($scan, OsvScanStatus::Completed);
    }

    private function completeBatch(OsvScan $scan): void
    {
        // Committed on its own (no wrapping transaction) so concurrent batch
        // completions see each other in the count below.
        $this->finish($scan, OsvScanStatus::Completed);

        $sweep = $scan->nightlySweep;

        if (! $sweep || $sweep->scans()->where('status', '!=', OsvScanStatus::Completed)->exists()) {
            return;
        }

        $claimed = $this->sweepQuery()
            ->whereKey($sweep->id)
            ->where('status', NightlySweepStatus::Scanning)
            ->update(['status' => NightlySweepStatus::Recording]);

        if ($claimed === 1) {
            RecordNightlySweepJob::dispatch($sweep->refresh());
        }
    }

    private function markFailed(OsvScan $scan, string $message): void
    {
        $this->finish($scan, OsvScanStatus::Failed, $message);

        if ($scan->type === OsvScanType::Locks) {
            if ($scan->environment_id !== null) {
                $this->recorder->recordFailedForEnvironments([$scan->environment_id], $scan->trigger, $message);
            }

            return;
        }

        $sweep = $scan->nightlySweep;

        if (! $sweep) {
            return;
        }

        // Only the first failing batch fails the sweep and records the failed runs.
        $claimed = $this->sweepQuery()
            ->whereKey($sweep->id)
            ->where('status', NightlySweepStatus::Scanning)
            ->update(['status' => NightlySweepStatus::Failed, 'completed_at' => now()]);

        if ($claimed === 1) {
            $this->recorder->recordFailedForEnvironments($sweep->environment_ids, AuditRunTrigger::Nightly, 'Nightly scan batch failed: ' . $message);
        }
    }

    private function canResubmit(OsvScan $scan): bool
    {
        return $scan->type === OsvScanType::Locks
            || $scan->nightlySweep?->status === NightlySweepStatus::Scanning;
    }

    private function finish(OsvScan $scan, OsvScanStatus $status, ?string $message = null): void
    {
        if ($scan->input_path !== null) {
            Storage::disk(FilamentVoight::config()->getLockfilesDisk())->delete($scan->input_path);
        }

        $scan->update([
            'status' => $status,
            'error_message' => $message === null ? null : mb_substr($message, 0, 500),
            'input_path' => null,
        ]);
    }

    /**
     * @return Builder<OsvScan>
     */
    private function scanQuery(): Builder
    {
        /** @var class-string<OsvScan> $model */
        $model = FilamentVoight::config()->getOsvScanModel();

        return $model::query();
    }

    /**
     * @return Builder<NightlySweep>
     */
    private function sweepQuery(): Builder
    {
        /** @var class-string<NightlySweep> $model */
        $model = FilamentVoight::config()->getNightlySweepModel();

        return $model::query();
    }
}
```

- [ ] **Step 7: Implement the three jobs**

`src/Jobs/SubmitOsvScanJob.php`:

```php
<?php

namespace Statikbe\FilamentVoight\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Storage;
use RuntimeException;
use Statikbe\FilamentVoight\Enums\OsvScanStatus;
use Statikbe\FilamentVoight\Facades\FilamentVoight;
use Statikbe\FilamentVoight\Models\OsvScan;
use Statikbe\FilamentVoight\Services\OsvScannerClient;
use Statikbe\FilamentVoight\Services\OsvScanResultHandler;
use Throwable;

class SubmitOsvScanJob implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    public int $tries = 3;

    /** @var array<int> */
    public array $backoff = [10, 60, 180];

    public bool $deleteWhenMissingModels = true;

    public function __construct(public OsvScan $scan) {}

    public function handle(OsvScannerClient $client): void
    {
        $scan = $this->scan->refresh();

        if ($scan->status !== OsvScanStatus::Pending) {
            return;
        }

        $input = Storage::disk(FilamentVoight::config()->getLockfilesDisk())->get((string) $scan->input_path);

        if ($input === null) {
            throw new RuntimeException("Scan input {$scan->input_path} is missing");
        }

        $created = $client->createScan($scan->type, $scan->meta, FilamentVoight::config()->getScannerCallbackUrl());

        // Stored before the upload: the upload starts the scan, and its callback
        // must be able to find this row.
        $scan->update(['scanner_scan_id' => $created->scanId]);

        $client->upload($created, $input);

        PollOsvScanJob::dispatch($scan, $created->scanId)
            ->delay(now()->addSeconds(FilamentVoight::config()->getScannerPollInterval()));
    }

    public function failed(Throwable $e): void
    {
        app(OsvScanResultHandler::class)->failForGood($this->scan, 'Submitting the scan failed: ' . $e->getMessage());
    }
}
```

`src/Jobs/PollOsvScanJob.php`:

```php
<?php

namespace Statikbe\FilamentVoight\Jobs;

use DateTimeInterface;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Statikbe\FilamentVoight\Enums\OsvScanStatus;
use Statikbe\FilamentVoight\Facades\FilamentVoight;
use Statikbe\FilamentVoight\Models\OsvScan;
use Statikbe\FilamentVoight\Services\OsvScannerClient;
use Statikbe\FilamentVoight\Services\OsvScanResultHandler;
use Throwable;

/**
 * The polling safety net: checks the scan every poll_interval seconds until it
 * is terminal or the 30-minute deadline passes. Bound to one submission.
 */
class PollOsvScanJob implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    /** Unlimited attempts: retryUntil() bounds polling, not a try count. */
    public int $tries = 0;

    public bool $deleteWhenMissingModels = true;

    public function __construct(public OsvScan $scan, public string $scannerScanId) {}

    public function retryUntil(): DateTimeInterface
    {
        return now()->addMinutes(30);
    }

    public function backoff(): int
    {
        return FilamentVoight::config()->getScannerPollInterval();
    }

    public function handle(OsvScannerClient $client, OsvScanResultHandler $handler): void
    {
        $scan = $this->scan->refresh();

        // Finished elsewhere (callback), or a resubmit replaced this submission.
        if ($scan->status !== OsvScanStatus::Pending || $scan->scanner_scan_id !== $this->scannerScanId) {
            return;
        }

        $status = $client->getScan($this->scannerScanId);

        if ($status->isTerminal()) {
            $handler->handleTerminal($scan, $this->scannerScanId, $status);

            return;
        }

        $this->release(FilamentVoight::config()->getScannerPollInterval());
    }

    public function failed(Throwable $e): void
    {
        app(OsvScanResultHandler::class)->failForGood($this->scan, 'Polling the scan failed: ' . $e->getMessage(), $this->scannerScanId);
    }
}
```

`src/Jobs/CompleteOsvScanJob.php`:

```php
<?php

namespace Statikbe\FilamentVoight\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Statikbe\FilamentVoight\Enums\OsvScanStatus;
use Statikbe\FilamentVoight\Models\OsvScan;
use Statikbe\FilamentVoight\Services\OsvScannerClient;
use Statikbe\FilamentVoight\Services\OsvScanResultHandler;

/**
 * Queued by the scanner callback. The callback only says "look now": the status
 * is always re-read from the authenticated API, so a forged callback is harmless.
 */
class CompleteOsvScanJob implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    public int $tries = 3;

    /** @var array<int> */
    public array $backoff = [10, 30, 60];

    public bool $deleteWhenMissingModels = true;

    public function __construct(public OsvScan $scan, public string $scannerScanId) {}

    public function handle(OsvScannerClient $client, OsvScanResultHandler $handler): void
    {
        $scan = $this->scan->refresh();

        if ($scan->status !== OsvScanStatus::Pending || $scan->scanner_scan_id !== $this->scannerScanId) {
            return;
        }

        $status = $client->getScan($this->scannerScanId);

        // Not terminal yet: the poller keeps watching.
        if ($status->isTerminal()) {
            $handler->handleTerminal($scan, $this->scannerScanId, $status);
        }
    }
}
```

- [ ] **Step 8: Run tests**

Run: `php -d memory_limit=1G vendor/bin/pest --no-coverage tests/Services/OsvScanResultHandlerTest.php tests/Services/OsvScanSubmitterTest.php tests/Jobs/OsvScanLifecycleJobsTest.php tests/Unit/FilamentVoightConfigTest.php`
Expected: PASS. (If `withFakeQueueInteractions`/`assertReleased` are unavailable in the installed Laravel, stop and report — Laravel ≥ 11 provides them.)

- [ ] **Step 9: Full suite, static analysis, format, commit**

Run: `php -d memory_limit=1G vendor/bin/pest --no-coverage && composer analyse && vendor/bin/pint --dirty`
Expected: only the 9 pre-existing failures; PHPStan clean.

```bash
git add -A
git commit -m "feat: add the async scan lifecycle with single-winner completion

Submit/poll/complete jobs hand terminal statuses to OsvScanResultHandler,
which claims the scan atomically, records it, resubmits retryable failures
and records exactly one failed run when a scan fails for good.

Co-Authored-By: Claude Opus 5.5 <noreply@anthropic.com>"
```

---

### Task 5: `RunOsvScanJob` submits a locks scan

**Files:**
- Rewrite: `src/Jobs/RunOsvScanJob.php`
- Rewrite: `tests/Jobs/RunOsvScanJobTest.php`

**Interfaces:**
- Consumes: `OsvScanSubmitter::start` (Task 4), `fakeScanner` (Task 2).
- Produces: `RunOsvScanJob(Environment $environment, AuditRunTrigger $trigger = Manual)` — unchanged constructor (callers: `RunOsvScanCommand`, `ProcessLockFilesJob`, `RunNightlyOsvScanJob`).

- [ ] **Step 1: Write the failing tests**

Replace `tests/Jobs/RunOsvScanJobTest.php`:

```php
<?php

use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Statikbe\FilamentVoight\Enums\AuditRunStatus;
use Statikbe\FilamentVoight\Enums\AuditRunTrigger;
use Statikbe\FilamentVoight\Enums\DependencySyncStatus;
use Statikbe\FilamentVoight\Enums\OsvScanStatus;
use Statikbe\FilamentVoight\Enums\OsvScanType;
use Statikbe\FilamentVoight\Jobs\RunOsvScanJob;
use Statikbe\FilamentVoight\Jobs\SendAuditAlertsJob;
use Statikbe\FilamentVoight\Jobs\SubmitOsvScanJob;
use Statikbe\FilamentVoight\Models\AuditFinding;
use Statikbe\FilamentVoight\Models\AuditRun;
use Statikbe\FilamentVoight\Models\DependencySync;
use Statikbe\FilamentVoight\Models\Environment;
use Statikbe\FilamentVoight\Models\EnvironmentPackage;
use Statikbe\FilamentVoight\Models\OsvScan;
use Statikbe\FilamentVoight\Models\Package;

beforeEach(function () {
    Storage::fake('voight-lockfiles');
    config()->set('filament-voight.scanner.url', 'https://scanner.test/scans');
    config()->set('filament-voight.scanner.token', 'secret');
    config()->set('filament-voight.scanner.callback_url', '');
});

function environmentWithLockfiles(array $files = ['p/production/composer.lock' => '{"packages":[]}']): Environment
{
    $environment = Environment::factory()->create();

    foreach ($files as $path => $contents) {
        Storage::disk('voight-lockfiles')->put($path, $contents);
    }

    DependencySync::factory()->for($environment)->create([
        'lockfile_paths' => array_keys($files),
        'status' => DependencySyncStatus::Completed,
    ]);

    return $environment;
}

it('starts a locks scan with the environment\'s lockfiles base64-encoded', function () {
    Bus::fake([SubmitOsvScanJob::class]);
    $environment = environmentWithLockfiles([
        'p/production/composer.lock' => '{"packages":[]}',
        'p/production/composer.json' => '{}', // not a lockfile: never sent
        'p/production/yarn.lock' => 'lockfile v1',
    ]);

    RunOsvScanJob::dispatchSync($environment, AuditRunTrigger::PostSync);

    $scan = OsvScan::sole();
    expect($scan->type)->toBe(OsvScanType::Locks)
        ->and($scan->environment_id)->toBe($environment->id)
        ->and($scan->trigger)->toBe(AuditRunTrigger::PostSync)
        ->and($scan->meta)->toBe(['project_code' => $environment->project->project_code, 'environment' => $environment->name]);

    $input = json_decode(Storage::disk('voight-lockfiles')->get($scan->input_path), true);
    expect($input['files'])->toBe([
        ['name' => 'composer.lock', 'content_base64' => base64_encode('{"packages":[]}')],
        ['name' => 'yarn.lock', 'content_base64' => base64_encode('lockfile v1')],
    ]);
    Bus::assertDispatched(SubmitOsvScanJob::class);
});

it('defaults the trigger to manual', function () {
    Bus::fake([SubmitOsvScanJob::class]);

    RunOsvScanJob::dispatchSync(environmentWithLockfiles());

    expect(OsvScan::sole()->trigger)->toBe(AuditRunTrigger::Manual);
});

it('fails without starting a scan when there is no completed sync', function () {
    Bus::fake([SubmitOsvScanJob::class]);

    RunOsvScanJob::dispatchSync(Environment::factory()->create());

    expect(OsvScan::count())->toBe(0);
    Bus::assertNotDispatched(SubmitOsvScanJob::class);
});

it('records findings end to end through submit, poll and completion', function () {
    Bus::fake([SendAuditAlertsJob::class]);
    $laravel = Package::factory()->composer()->create(['name' => 'laravel/framework']);
    $environment = environmentWithLockfiles();
    EnvironmentPackage::factory()->create(['environment_id' => $environment->id, 'package_id' => $laravel->id, 'version' => '10.9.0']);
    fakeScanner([
        'findings' => [['ecosystem' => 'Packagist', 'name' => 'laravel/framework', 'version' => 'v10.9.0', 'vulnerability_id' => 'GHSA-5vg9', 'max_severity' => '9.1']],
        'vulnerabilities' => ['GHSA-5vg9' => ['id' => 'GHSA-5vg9', 'summary' => 's', 'database_specific' => ['severity' => 'HIGH'],
            'affected' => [['package' => ['name' => 'laravel/framework'], 'ranges' => [['events' => [['introduced' => '0'], ['fixed' => 'v10.48.29']]]]]]]],
        'summary' => [],
    ]);

    // The sync queue runs submit → poll → completion inline (delays are ignored).
    RunOsvScanJob::dispatchSync($environment, AuditRunTrigger::PostSync);

    $run = AuditRun::sole();
    expect($run->status)->toBe(AuditRunStatus::Completed)
        ->and($run->trigger)->toBe(AuditRunTrigger::PostSync)
        ->and(AuditFinding::where('audit_run_id', $run->id)->count())->toBe(1)
        ->and(OsvScan::sole()->status)->toBe(OsvScanStatus::Completed);
    Bus::assertDispatched(SendAuditAlertsJob::class, fn ($job) => $job->auditRun->is($run));
    Http::assertSent(fn ($r) => $r->method() === 'POST' && $r['callback_url'] === null);
});

it('records one failed run when the scanner rejects the input', function () {
    fakeScanner(status: 'failed', error: ['code' => 'invalid_input', 'message' => 'unsupported lockfile name: x', 'retryable' => false]);

    RunOsvScanJob::dispatchSync(environmentWithLockfiles());

    $run = AuditRun::sole();
    expect($run->status)->toBe(AuditRunStatus::Failed)
        ->and($run->error_message)->toBe('invalid_input: unsupported lockfile name: x');
});
```

- [ ] **Step 2: Run to verify failure**

Run: `php -d memory_limit=1G vendor/bin/pest --no-coverage tests/Jobs/RunOsvScanJobTest.php`
Expected: FAIL — the job still calls `scanLockfiles` against `/scans`.

- [ ] **Step 3: Implement**

Replace the body of `RunOsvScanJob` (keep `resolveLockfileContents()` exactly as it is; delete `recordFailedRun()` and the `try/catch`):

```php
<?php

namespace Statikbe\FilamentVoight\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Storage;
use RuntimeException;
use Statikbe\FilamentVoight\Enums\AuditRunTrigger;
use Statikbe\FilamentVoight\Enums\DependencySyncStatus;
use Statikbe\FilamentVoight\Enums\OsvScanType;
use Statikbe\FilamentVoight\Facades\FilamentVoight;
use Statikbe\FilamentVoight\Models\Environment;
use Statikbe\FilamentVoight\Services\OsvScanSubmitter;

/**
 * Starts an asynchronous lockfile scan for one environment. The outcome is
 * recorded later by OsvScanResultHandler, once the scanner has finished.
 */
class RunOsvScanJob implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    public int $tries = 3;

    /** @var array<int> */
    public array $backoff = [10, 60, 180];

    public function __construct(
        public Environment $environment,
        public AuditRunTrigger $trigger = AuditRunTrigger::Manual,
    ) {}

    public function handle(OsvScanSubmitter $submitter): void
    {
        $files = $this->resolveLockfileContents();

        if ($files === []) {
            $this->fail(new RuntimeException(
                "No completed dependency sync found for environment '{$this->environment->name}'. Run a lock file sync first."
            ));

            return;
        }

        $input = json_encode([
            'files' => collect($files)
                ->map(fn (string $contents, string $name): array => ['name' => $name, 'content_base64' => base64_encode($contents)])
                ->values()
                ->all(),
        ], JSON_THROW_ON_ERROR);

        $submitter->start(
            OsvScanType::Locks,
            $input,
            ['project_code' => $this->environment->project->project_code, 'environment' => $this->environment->name],
            $this->trigger,
            environmentId: $this->environment->id,
        );
    }

    // resolveLockfileContents() unchanged from the current file.
}
```

- [ ] **Step 4: Run tests**

Run: `php -d memory_limit=1G vendor/bin/pest --no-coverage tests/Jobs/RunOsvScanJobTest.php tests/Commands/RunOsvScanCommandTest.php`
Expected: PASS.

- [ ] **Step 5: Full suite, analyse, format, commit**

Run: `php -d memory_limit=1G vendor/bin/pest --no-coverage && composer analyse && vendor/bin/pint --dirty`

```bash
git add -A
git commit -m "feat: start post-sync and manual scans asynchronously

Co-Authored-By: Claude Opus 5.5 <noreply@anthropic.com>"
```

---

### Task 6: Signed callback endpoint

**Files:**
- Create: `src/Http/Middleware/VerifyScannerCallbackSignature.php`, `src/Http/Controllers/ScanCallbackController.php`
- Modify: `routes/api.php`
- Test: `tests/Http/ScanCallbackControllerTest.php`, `tests/Unit/FilamentVoightConfigTest.php`

**Interfaces:**
- Consumes: `CompleteOsvScanJob` (Task 4), `getScannerCallbackSecret()` (Task 1).
- Produces: route `POST api/voight/scans/callback` named `voight.scans.callback`.

- [ ] **Step 1: Write the failing tests**

`tests/Http/ScanCallbackControllerTest.php`:

```php
<?php

use Illuminate\Support\Facades\Bus;
use Statikbe\FilamentVoight\Jobs\CompleteOsvScanJob;
use Statikbe\FilamentVoight\Models\OsvScan;

beforeEach(function () {
    config()->set('filament-voight.scanner.callback_secret', 'cb-secret');
    Bus::fake([CompleteOsvScanJob::class]);
});

/**
 * @param  array<string, mixed>  $payload
 */
function signedCallback(array $payload, ?int $timestamp = null, string $secret = 'cb-secret')
{
    $timestamp ??= time();
    $body = json_encode($payload);

    return test()->withHeaders([
        'X-Voight-Timestamp' => (string) $timestamp,
        'X-Voight-Signature' => 'sha256=' . hash_hmac('sha256', $timestamp . '.' . $body, $secret),
    ])->postJson('/api/voight/scans/callback', $payload);
}

it('queues completion for a correctly signed callback', function () {
    $scan = OsvScan::factory()->create();

    signedCallback(['scan_id' => $scan->scanner_scan_id, 'status' => 'done'])->assertStatus(202);

    Bus::assertDispatched(CompleteOsvScanJob::class, fn ($job) => $job->scan->is($scan) && $job->scannerScanId === $scan->scanner_scan_id);
});

it('rejects a wrong signature', function () {
    $scan = OsvScan::factory()->create();

    signedCallback(['scan_id' => $scan->scanner_scan_id, 'status' => 'done'], secret: 'wrong')->assertStatus(401);

    Bus::assertNotDispatched(CompleteOsvScanJob::class);
});

it('rejects a timestamp outside five minutes', function () {
    $scan = OsvScan::factory()->create();

    signedCallback(['scan_id' => $scan->scanner_scan_id, 'status' => 'done'], timestamp: time() - 301)->assertStatus(401);
    signedCallback(['scan_id' => $scan->scanner_scan_id, 'status' => 'done'], timestamp: time() + 301)->assertStatus(401);
});

it('rejects every callback when no secret is configured', function () {
    config()->set('filament-voight.scanner.callback_secret', null);
    $scan = OsvScan::factory()->create();

    signedCallback(['scan_id' => $scan->scanner_scan_id, 'status' => 'done'])->assertStatus(401);
});

it('rejects a callback without signature headers', function () {
    $this->postJson('/api/voight/scans/callback', ['scan_id' => 'x'])->assertStatus(401);
});

it('returns 404 for an unknown scan', function () {
    signedCallback(['scan_id' => '01J9ZK3M4N5P6R7S8T9V0WNONE', 'status' => 'done'])->assertStatus(404);

    Bus::assertNotDispatched(CompleteOsvScanJob::class);
});

it('does not require the project token API middleware', function () {
    // TestCase sets api.middleware to ['auth']; an unauthenticated signed call must still pass.
    $scan = OsvScan::factory()->create();

    signedCallback(['scan_id' => $scan->scanner_scan_id, 'status' => 'failed'])->assertStatus(202);
});
```

Append to `tests/Unit/FilamentVoightConfigTest.php`:

```php
it('defaults the callback url to the package route', function () {
    config()->set('filament-voight.scanner.callback_url', null);

    expect(FilamentVoight::config()->getScannerCallbackUrl())->toBe(url('/api/voight/scans/callback'));
});
```

- [ ] **Step 2: Run to verify failure**

Run: `php -d memory_limit=1G vendor/bin/pest --no-coverage tests/Http/ScanCallbackControllerTest.php tests/Unit/FilamentVoightConfigTest.php`
Expected: FAIL — route missing (404 everywhere; default callback URL is `null`).

- [ ] **Step 3: Implement**

`src/Http/Middleware/VerifyScannerCallbackSignature.php`:

```php
<?php

namespace Statikbe\FilamentVoight\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Statikbe\FilamentVoight\Facades\FilamentVoight;
use Symfony\Component\HttpFoundation\Response;

/**
 * Verifies the scanner's HMAC signature over "timestamp.raw_body" and rejects
 * timestamps older or newer than five minutes (replay window).
 */
class VerifyScannerCallbackSignature
{
    private const MAX_SKEW_SECONDS = 300;

    public function handle(Request $request, Closure $next): Response
    {
        $secret = FilamentVoight::config()->getScannerCallbackSecret();
        $timestamp = (string) $request->header('X-Voight-Timestamp', '');
        $signature = (string) $request->header('X-Voight-Signature', '');

        if ($secret === null || ! ctype_digit($timestamp) || abs(time() - (int) $timestamp) > self::MAX_SKEW_SECONDS) {
            return response()->json(['error' => 'Invalid signature'], 401);
        }

        $expected = 'sha256=' . hash_hmac('sha256', $timestamp . '.' . $request->getContent(), $secret);

        if (! hash_equals($expected, $signature)) {
            return response()->json(['error' => 'Invalid signature'], 401);
        }

        return $next($request);
    }
}
```

`src/Http/Controllers/ScanCallbackController.php`:

```php
<?php

namespace Statikbe\FilamentVoight\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Statikbe\FilamentVoight\Facades\FilamentVoight;
use Statikbe\FilamentVoight\Jobs\CompleteOsvScanJob;
use Statikbe\FilamentVoight\Models\OsvScan;

class ScanCallbackController extends Controller
{
    public function __invoke(Request $request): JsonResponse
    {
        $scannerScanId = (string) $request->json('scan_id', '');

        /** @var class-string<OsvScan> $scanModel */
        $scanModel = FilamentVoight::config()->getOsvScanModel();
        $scan = $scannerScanId === '' ? null : $scanModel::query()->where('scanner_scan_id', $scannerScanId)->first();

        if (! $scan) {
            return response()->json(['error' => 'Unknown scan'], 404);
        }

        // Results are never read from the callback; the job re-reads the status from the scanner.
        CompleteOsvScanJob::dispatch($scan, $scannerScanId);

        return response()->json(['status' => 'queued'], 202);
    }
}
```

`routes/api.php` — add below the existing group:

```php
// Outside the project-token middleware: the scanner authenticates with an HMAC signature.
Route::post('api/voight/scans/callback', ScanCallbackController::class)
    ->middleware(VerifyScannerCallbackSignature::class)
    ->name('voight.scans.callback');
```

with `use Statikbe\FilamentVoight\Http\Controllers\ScanCallbackController;` and `use Statikbe\FilamentVoight\Http\Middleware\VerifyScannerCallbackSignature;`.

- [ ] **Step 4: Run tests**

Run: `php -d memory_limit=1G vendor/bin/pest --no-coverage tests/Http tests/Unit/FilamentVoightConfigTest.php`
Expected: PASS (including the existing `LockFileControllerTest`).

- [ ] **Step 5: Full suite, analyse, format, commit**

Run: `php -d memory_limit=1G vendor/bin/pest --no-coverage && composer analyse && vendor/bin/pint --dirty`

```bash
git add -A
git commit -m "feat: accept signed scanner completion callbacks

Co-Authored-By: Claude Opus 5.5 <noreply@anthropic.com>"
```

---

### Task 7: Nightly sweep submits batches atomically

**Files:**
- Rewrite: `src/Jobs/RunNightlyOsvScanJob.php`
- Rewrite: `tests/Jobs/RunNightlyOsvScanJobTest.php`

**Interfaces:**
- Consumes: `OsvScanSubmitter` (Task 4), `NightlySweep` (Task 1), `RecordNightlySweepJob` (Task 3), `fakeScanner` (Task 2).
- Produces: `RunNightlyOsvScanJob::handle(OsvScanSubmitter $submitter)`; `failed()` kept (the job itself failing before any scan exists).

- [ ] **Step 1: Write the failing tests**

Replace `tests/Jobs/RunNightlyOsvScanJobTest.php`:

```php
<?php

use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Storage;
use Statikbe\FilamentVoight\Enums\AuditRunStatus;
use Statikbe\FilamentVoight\Enums\AuditRunTrigger;
use Statikbe\FilamentVoight\Enums\NightlySweepStatus;
use Statikbe\FilamentVoight\Enums\OsvScanType;
use Statikbe\FilamentVoight\Jobs\RunNightlyOsvScanJob;
use Statikbe\FilamentVoight\Jobs\RunOsvScanJob;
use Statikbe\FilamentVoight\Jobs\SubmitOsvScanJob;
use Statikbe\FilamentVoight\Models\AuditFinding;
use Statikbe\FilamentVoight\Models\AuditRun;
use Statikbe\FilamentVoight\Models\Environment;
use Statikbe\FilamentVoight\Models\EnvironmentPackage;
use Statikbe\FilamentVoight\Models\NightlySweep;
use Statikbe\FilamentVoight\Models\OsvScan;
use Statikbe\FilamentVoight\Models\Package;
use Statikbe\FilamentVoight\Services\OsvScanSubmitter;

beforeEach(function () {
    Storage::fake('voight-lockfiles');
    config()->set('filament-voight.scanner.url', 'https://scanner.test/scans');
    config()->set('filament-voight.scanner.token', 'secret');
    config()->set('filament-voight.scanner.callback_url', '');
});

function inputOf(OsvScan $scan): array
{
    return json_decode(Storage::disk('voight-lockfiles')->get($scan->input_path), true);
}

it('creates one sweep over the scan_nightly environments with one batch per chunk', function () {
    Bus::fake([SubmitOsvScanJob::class]);
    config()->set('filament-voight.scanner.batch_size', 1);
    $console = Package::factory()->composer()->create(['name' => 'symfony/console']);
    $lodash = Package::factory()->npm()->create(['name' => 'lodash']);
    [$a, $b] = Environment::factory()->count(2)->create()->all();
    $optedOut = Environment::factory()->notNightly()->create();
    EnvironmentPackage::factory()->create(['environment_id' => $a->id, 'package_id' => $console->id, 'version' => '7.1.5']);
    EnvironmentPackage::factory()->create(['environment_id' => $b->id, 'package_id' => $console->id, 'version' => '7.1.5']);
    EnvironmentPackage::factory()->create(['environment_id' => $b->id, 'package_id' => $lodash->id, 'version' => '4.17.15']);
    EnvironmentPackage::factory()->create(['environment_id' => $optedOut->id, 'package_id' => $lodash->id, 'version' => '4.17.20']);

    RunNightlyOsvScanJob::dispatchSync();

    $sweep = NightlySweep::sole();
    expect($sweep->status)->toBe(NightlySweepStatus::Scanning)
        ->and(collect($sweep->environment_ids)->sort()->values()->all())->toBe(collect([$a->id, $b->id])->sort()->values()->all());

    $scans = OsvScan::all();
    expect($scans)->toHaveCount(2) // 2 distinct tuples, batch_size 1
        ->and($scans->every(fn (OsvScan $s) => $s->type === OsvScanType::Packages && $s->nightly_sweep_id === $sweep->id && $s->trigger === AuditRunTrigger::Nightly))->toBeTrue();

    $packages = $scans->flatMap(fn (OsvScan $s) => inputOf($s)['packages'])->sortBy('name')->values()->all();
    expect($packages)->toBe([
        ['ecosystem' => 'npm', 'name' => 'lodash', 'version' => '4.17.15'],
        ['ecosystem' => 'Packagist', 'name' => 'symfony/console', 'version' => '7.1.5'],
    ]);
    expect(inputOf($scans->first())['batch_id'])->toBe($scans->first()->meta['batch_id']);
    Bus::assertDispatchedTimes(SubmitOsvScanJob::class, 2);
});

it('routes environments with commit-pinned packages to the lockfile scan and out of the sweep', function () {
    Bus::fake([RunOsvScanJob::class, SubmitOsvScanJob::class]);
    $pinned = Package::factory()->composer()->create(['name' => 'league/commonmark']);
    $normal = Package::factory()->composer()->create(['name' => 'symfony/console']);
    $pinnedEnv = Environment::factory()->create();
    $dedupeEnv = Environment::factory()->create();
    EnvironmentPackage::factory()->create(['environment_id' => $pinnedEnv->id, 'package_id' => $pinned->id, 'version' => 'dev-main']);
    EnvironmentPackage::factory()->create(['environment_id' => $dedupeEnv->id, 'package_id' => $normal->id, 'version' => '7.1.5']);

    RunNightlyOsvScanJob::dispatchSync();

    Bus::assertDispatched(RunOsvScanJob::class, fn ($job) => $job->environment->id === $pinnedEnv->id && $job->trigger === AuditRunTrigger::Nightly);
    Bus::assertNotDispatched(RunOsvScanJob::class, fn ($job) => $job->environment->id === $dedupeEnv->id);
    expect(NightlySweep::sole()->environment_ids)->toBe([$dedupeEnv->id])
        ->and(inputOf(OsvScan::sole())['packages'])->toBe([['ecosystem' => 'Packagist', 'name' => 'symfony/console', 'version' => '7.1.5']]);
});

it('does nothing when there are no scan_nightly environments', function () {
    Bus::fake([SubmitOsvScanJob::class]);
    Environment::factory()->notNightly()->create();

    RunNightlyOsvScanJob::dispatchSync();

    expect(NightlySweep::count())->toBe(0)->and(OsvScan::count())->toBe(0);
});

it('leaves no partial sweep when creating a batch fails halfway', function () {
    Bus::fake([SubmitOsvScanJob::class]);
    config()->set('filament-voight.scanner.batch_size', 1);
    foreach (['a/one', 'b/two'] as $name) {
        $package = Package::factory()->composer()->create(['name' => $name]);
        EnvironmentPackage::factory()->create(['environment_id' => Environment::factory()->create()->id, 'package_id' => $package->id, 'version' => '1.0.0']);
    }

    $calls = 0;
    $real = app(OsvScanSubmitter::class);
    app()->instance(OsvScanSubmitter::class, Mockery::mock(OsvScanSubmitter::class, function ($mock) use (&$calls, $real) {
        $mock->shouldReceive('start')->andReturnUsing(function (...$args) use (&$calls, $real) {
            if (++$calls === 2) {
                throw new RuntimeException('disk full');
            }

            return $real->start(...$args);
        });
    }));

    expect(fn () => app()->call([new RunNightlyOsvScanJob, 'handle']))->toThrow(RuntimeException::class, 'disk full');

    expect(NightlySweep::count())->toBe(0)
        ->and(OsvScan::count())->toBe(0);
    Bus::assertNotDispatched(SubmitOsvScanJob::class);
});

it('records every sweep environment end to end through submit, poll, completion and recording', function () {
    $laravel = Package::factory()->composer()->create(['name' => 'laravel/framework']);
    $nightly = Environment::factory()->create();
    EnvironmentPackage::factory()->create(['environment_id' => $nightly->id, 'package_id' => $laravel->id, 'version' => '10.9.0']);
    fakeScanner([
        'findings' => [['ecosystem' => 'Packagist', 'name' => 'laravel/framework', 'version' => '10.9.0', 'vulnerability_id' => 'GHSA-5vg9', 'max_severity' => '9.1']],
        'vulnerabilities' => ['GHSA-5vg9' => ['id' => 'GHSA-5vg9', 'summary' => 's', 'database_specific' => ['severity' => 'HIGH'],
            'affected' => [['package' => ['name' => 'laravel/framework'], 'ranges' => [['events' => [['introduced' => '0'], ['fixed' => 'v10.48.29']]]]]]]],
        'summary' => ['skipped_packages' => []],
    ]);

    RunNightlyOsvScanJob::dispatchSync();

    $run = AuditRun::where('environment_id', $nightly->id)->sole();
    expect($run->trigger)->toBe(AuditRunTrigger::Nightly)
        ->and($run->status)->toBe(AuditRunStatus::Completed)
        ->and(AuditFinding::where('audit_run_id', $run->id)->count())->toBe(1)
        ->and(NightlySweep::sole()->status)->toBe(NightlySweepStatus::Completed);
});

it('records a failed nightly run for every scan_nightly environment when the job itself fails', function () {
    $nightly = Environment::factory()->count(2)->create();
    Environment::factory()->notNightly()->create();

    (new RunNightlyOsvScanJob)->failed(new RuntimeException('database unavailable'));

    expect(AuditRun::count())->toBe(2)
        ->and(AuditRun::all()->every(fn (AuditRun $run) => $run->status === AuditRunStatus::Failed && $run->error_message === 'database unavailable'))->toBeTrue();
});
```

- [ ] **Step 2: Run to verify failure**

Run: `php -d memory_limit=1G vendor/bin/pest --no-coverage tests/Jobs/RunNightlyOsvScanJobTest.php`
Expected: FAIL — the job still posts to `packages_url`.

- [ ] **Step 3: Implement**

In `RunNightlyOsvScanJob`:
- `handle()` signature becomes `handle(OsvScanSubmitter $submitter): void` and calls `$this->scanDeduplicated($dedupeEnvironments, $submitter);`.
- Keep `failed()`, `partitionEnvironments()`, `scanCommitPinned()` and `ecosystem()` unchanged.
- Delete `mergeFindings()`, `mergeMaxSeverity()` and the `OsvScannerClient`/`RecordEnvironmentAuditRunsService` imports.
- Set `public int $timeout = 120;`.
- Replace `scanDeduplicated()` with:

```php
    /**
     * Create the sweep and all of its batches in one transaction: a sweep with
     * missing batches could otherwise "complete" and record a false clean result.
     * The submissions are queued after commit (see OsvScanSubmitter).
     *
     * @param  Collection<int, Environment>  $environments
     */
    private function scanDeduplicated(Collection $environments, OsvScanSubmitter $submitter): void
    {
        if ($environments->isEmpty()) {
            return;
        }

        /** @var class-string<EnvironmentPackage> $environmentPackageModel */
        $environmentPackageModel = FilamentVoight::config()->getEnvironmentPackageModel();
        $packages = $environmentPackageModel::distinctPackageSetForEnvironments($environments);

        if ($packages->isEmpty()) {
            return;
        }

        /** @var class-string<NightlySweep> $sweepModel */
        $sweepModel = FilamentVoight::config()->getNightlySweepModel();
        $batchSize = FilamentVoight::config()->getScannerBatchSize();

        DB::transaction(function () use ($environments, $packages, $sweepModel, $batchSize, $submitter): void {
            $sweep = $sweepModel::create([
                'status' => NightlySweepStatus::Scanning,
                'environment_ids' => $environments->pluck('id')->values()->all(),
                'started_at' => now(),
            ]);

            foreach ($packages->chunk($batchSize) as $chunk) {
                $batchId = (string) Str::ulid();
                $input = json_encode([
                    'batch_id' => $batchId,
                    'packages' => $chunk->map(fn (array $package): array => [
                        'ecosystem' => $this->ecosystem($package['type']),
                        'name' => $package['name'],
                        'version' => $package['version'],
                    ])->values()->all(),
                ], JSON_THROW_ON_ERROR);

                $submitter->start(OsvScanType::Packages, $input, ['batch_id' => $batchId], AuditRunTrigger::Nightly, nightlySweepId: $sweep->id);
            }
        });
    }
```

New imports: `Illuminate\Support\Facades\DB`, `Statikbe\FilamentVoight\Enums\NightlySweepStatus`, `Statikbe\FilamentVoight\Enums\OsvScanType`, `Statikbe\FilamentVoight\Models\NightlySweep`, `Statikbe\FilamentVoight\Services\OsvScanSubmitter`.

- [ ] **Step 4: Run tests**

Run: `php -d memory_limit=1G vendor/bin/pest --no-coverage tests/Jobs tests/Commands tests/Feature/NightlyScheduleTest.php`
Expected: PASS except the pre-existing `ProcessLockFilesJobTest` fixture failures.

- [ ] **Step 5: Full suite, analyse, format, commit**

Run: `php -d memory_limit=1G vendor/bin/pest --no-coverage && composer analyse && vendor/bin/pint --dirty`

```bash
git add -A
git commit -m "feat: submit nightly batches as one atomic sweep

Each batch is its own async scan, so a failing batch retries alone; the
sweep is recorded once its last batch completes.

Co-Authored-By: Claude Opus 5.5 <noreply@anthropic.com>"
```

---

### Task 8: Remove the synchronous client path; docs

**Files:**
- Modify: `src/Services/OsvScannerClient.php` (delete `scanPackages`, `scanLockfiles`), `tests/Services/OsvScannerClientTest.php` (delete their tests)
- Modify: `config/filament-voight.php` (delete `packages_url`), `src/FilamentVoightConfig.php` (delete `getScannerPackagesUrl()`)
- Create: `specs/10-async-osv-scans.md`
- Modify: `specs/09-nightly-deduplicated-osv-scan.md`, `README.md`, `CHANGELOG.md`
- Modify: the design spec (lambda worktree) — §5 refinements below

- [ ] **Step 1: Delete the old code and tests**

Remove `scanPackages()` and `scanLockfiles()` from `OsvScannerClient`, the tests that call them (`posts packages as json…`, `throws on a non-2xx package scan response`, and any `scanLockfiles` tests) from `tests/Services/OsvScannerClientTest.php`, the `scanner.packages_url` config line, and `getScannerPackagesUrl()`.

Then verify nothing references them:

Run: `grep -rn "scanPackages\|scanLockfiles\|packages_url\|PackagesUrl" src tests config`
Expected: no output.

- [ ] **Step 2: Full suite and analyse**

Run: `php -d memory_limit=1G vendor/bin/pest --no-coverage && composer analyse && vendor/bin/pint --dirty`
Expected: only the 9 pre-existing failures; PHPStan clean.

- [ ] **Step 3: Package docs**

Create `specs/10-async-osv-scans.md`:

```markdown
# 10 — Asynchronous OSV scans

The scanner contract and the full design live in the lambda repo:
`voight-osv-scanner-lambda/docs/superpowers/specs/2026-10-05-async-scans-design.md`.

Voight side, in one page:

- Every scan is an `OsvScan` (`voight_osv_scans`); nightly batches also belong to a
  `NightlySweep` (`voight_nightly_sweeps`), which snapshots its environment ids.
- `OsvScanSubmitter::start()` saves the input to `voight/scans/{id}/input.json` on the
  lockfiles disk and queues `SubmitOsvScanJob` (after commit), which calls
  `POST /scans`, uploads to the presigned URL and queues `PollOsvScanJob`.
- Completion: the signed callback (`POST api/voight/scans/callback` →
  `CompleteOsvScanJob`) or `PollOsvScanJob` (every `poll_interval` s, 30-minute
  deadline). Both end in `OsvScanResultHandler`, whose
  `UPDATE … WHERE status='pending' AND scanner_scan_id=?` claim picks one winner.
- Retryable failures are resubmitted up to `max_resubmits`; a scan that fails for good
  records one failed `AuditRun` (a sweep: one per environment, once).
- `RecordNightlySweepJob` merges batch results and records the sweep when its last
  batch completes.

Config: `VOIGHT_SCANNER_URL` (…/scans), `VOIGHT_SCANNER_TOKEN`,
`VOIGHT_SCANNER_CALLBACK_SECRET`, `VOIGHT_SCANNER_CALLBACK_URL` (empty = polling only),
`VOIGHT_SCANNER_POLL_INTERVAL` (15), `VOIGHT_SCANNER_MAX_RESUBMITS` (2),
`VOIGHT_SCANNER_BATCH_SIZE` (5000).
```

In `specs/09-nightly-deduplicated-osv-scan.md`, add at the top:

```markdown
> **Transport superseded (2026-10-05):** batches are now submitted as async scans;
> see [10 — Asynchronous OSV scans](10-async-osv-scans.md). Partitioning,
> deduplication and fan-out below are unchanged.
```

In `README.md`, replace any mention of `VOIGHT_SCANNER_PACKAGES_URL` and the synchronous `/packages`/`/locks` endpoints with the config list above, and note the new migrations must be published (`php artisan vendor:publish --tag=filament-voight-migrations && php artisan migrate`).

In `CHANGELOG.md`, add an `Unreleased` entry:

```markdown
## Unreleased

### Changed
- **Breaking:** OSV scans are asynchronous. Requires the matching
  `voight-osv-scanner-lambda` release; `VOIGHT_SCANNER_URL` must point to `…/scans`
  and `VOIGHT_SCANNER_PACKAGES_URL` is removed.
- A failed nightly batch is retried on its own instead of re-running the whole sweep.
- A failing scan records one failed audit run instead of one per attempt.

### Added
- `voight_osv_scans` and `voight_nightly_sweeps` tables (publish and run migrations).
- Signed scanner callback route `voight.scans.callback`
  (`VOIGHT_SCANNER_CALLBACK_SECRET`, `VOIGHT_SCANNER_CALLBACK_URL`).
```

- [ ] **Step 4: Record the plan's refinements in the design spec**

In `/Users/zias/dev/voight-osv-scanner-lambda/.worktrees/async-scans/docs/superpowers/specs/2026-10-05-async-scans-design.md` §5:
- §5.1 `voight_osv_scans`: add row `| meta | json | echoed to the scanner; kept for resubmits |`.
- §5.2: `OsvScanSubmitter::start()` (not `SubmitOsvScanJob`) saves the input; `PollOsvScanJob` calls `OsvScanResultHandler::handleTerminal()` directly instead of `CompleteOsvScanJob::dispatchSync`; all lifecycle jobs set `deleteWhenMissingModels`.
- §5.3: `RunNightlyOsvScanJob::failed()` is kept for the job itself failing before any batch exists.

Commit that file in the lambda worktree:

```bash
git -C /Users/zias/dev/voight-osv-scanner-lambda/.worktrees/async-scans commit -am "docs: record package plan refinements in the async scans spec

Co-Authored-By: Claude Opus 5.5 <noreply@anthropic.com>"
```

- [ ] **Step 5: Commit the package**

```bash
git add -A
git commit -m "refactor: remove the synchronous scanner client and document async scans

Co-Authored-By: Claude Opus 5.5 <noreply@anthropic.com>"
```

---

## Done when

- `php -d memory_limit=1G vendor/bin/pest --no-coverage` shows only the 9 pre-existing fixture failures.
- `composer analyse` and `vendor/bin/pint --test` are clean.
- `grep -rn "scanPackages\|scanLockfiles\|packages_url" src tests config` returns nothing.
- Rolling out to the `voight` app (publish migrations, env changes, secret) follows spec §8 and is **not** part of this plan.
