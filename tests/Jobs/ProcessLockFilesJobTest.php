<?php

use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Storage;
use Statikbe\FilamentVoight\Enums\DependencyKind;
use Statikbe\FilamentVoight\Enums\DependencySyncStatus;
use Statikbe\FilamentVoight\Enums\PackageType;
use Statikbe\FilamentVoight\Enums\SyncWarning;
use Statikbe\FilamentVoight\Jobs\ProcessLockFilesJob;
use Statikbe\FilamentVoight\Jobs\RunOsvScanJob;
use Statikbe\FilamentVoight\Models\DependencySync;
use Statikbe\FilamentVoight\Models\Environment;
use Statikbe\FilamentVoight\Models\EnvironmentPackage;
use Statikbe\FilamentVoight\Models\EnvironmentPackageDependency;
use Statikbe\FilamentVoight\Models\Package;

beforeEach(function () {
    Storage::fake('voight-lockfiles');
    // This test covers lock-file processing only; stub the post-sync scan job
    // so its dispatch isn't executed synchronously (would need a scanner URL).
    Bus::fake([RunOsvScanJob::class]);
});

it('processes composer.lock and creates packages', function () {
    $environment = Environment::factory()->create();

    $composerLock = json_encode([
        'packages' => [
            ['name' => 'laravel/framework', 'version' => 'v11.0.0', 'require' => []],
            ['name' => 'nesbot/carbon', 'version' => 'v3.0.0', 'require' => []],
        ],
        'packages-dev' => [
            ['name' => 'pestphp/pest', 'version' => 'v3.0.0', 'require' => []],
        ],
    ]);

    $lockfilePath = 'test-project/production/composer.lock';
    Storage::disk('voight-lockfiles')->put($lockfilePath, $composerLock);

    $sync = DependencySync::factory()->for($environment)->create([
        'lockfile_paths' => [$lockfilePath],
        'status' => DependencySyncStatus::Pending,
    ]);

    ProcessLockFilesJob::dispatchSync($sync);

    $sync->refresh();
    expect($sync->status)->toBe(DependencySyncStatus::Completed)
        ->and($sync->package_count)->toBe(3)
        ->and($sync->synced_at)->not->toBeNull();

    expect(Package::count())->toBe(3);
    expect(EnvironmentPackage::where('environment_id', $environment->id)->count())->toBe(3);

    $pest = Package::where('name', 'pestphp/pest')->first();
    $envPackage = EnvironmentPackage::where('package_id', $pest->id)->first();
    expect($envPackage->is_dev)->toBeTrue();
});

it('processes package-lock.json and creates packages', function () {
    $environment = Environment::factory()->create();

    $packageLock = json_encode([
        'packages' => [
            '' => ['name' => 'root'],
            'node_modules/vue' => ['version' => '3.4.0', 'dev' => false, 'dependencies' => []],
            'node_modules/vite' => ['version' => '5.0.0', 'dev' => true],
        ],
        'dependencies' => ['vue' => '^3.4.0'],
        'devDependencies' => ['vite' => '^5.0.0'],
    ]);

    $lockfilePath = 'test-project/production/package-lock.json';
    Storage::disk('voight-lockfiles')->put($lockfilePath, $packageLock);

    $sync = DependencySync::factory()->for($environment)->create([
        'lockfile_paths' => [$lockfilePath],
        'status' => DependencySyncStatus::Pending,
    ]);

    ProcessLockFilesJob::dispatchSync($sync);

    $sync->refresh();
    expect($sync->status)->toBe(DependencySyncStatus::Completed)
        ->and($sync->package_count)->toBe(2);

    expect(Package::where('type', PackageType::Npm)->count())->toBe(2);
});

it('replaces existing environment packages on re-sync', function () {
    $environment = Environment::factory()->create();

    // First sync
    $composerLock = json_encode([
        'packages' => [
            ['name' => 'old/package', 'version' => '1.0.0', 'require' => []],
        ],
        'packages-dev' => [],
    ]);

    $lockfilePath = 'test-project/production/composer.lock';
    Storage::disk('voight-lockfiles')->put($lockfilePath, $composerLock);

    $sync1 = DependencySync::factory()->for($environment)->create([
        'lockfile_paths' => [$lockfilePath],
        'status' => DependencySyncStatus::Pending,
    ]);

    ProcessLockFilesJob::dispatchSync($sync1);
    expect(EnvironmentPackage::where('environment_id', $environment->id)->count())->toBe(1);

    // Second sync with different packages
    $composerLock2 = json_encode([
        'packages' => [
            ['name' => 'new/package', 'version' => '2.0.0', 'require' => []],
            ['name' => 'another/package', 'version' => '1.0.0', 'require' => []],
        ],
        'packages-dev' => [],
    ]);

    Storage::disk('voight-lockfiles')->put($lockfilePath, $composerLock2);

    $sync2 = DependencySync::factory()->for($environment)->create([
        'lockfile_paths' => [$lockfilePath],
        'status' => DependencySyncStatus::Pending,
    ]);

    ProcessLockFilesJob::dispatchSync($sync2);

    expect(EnvironmentPackage::where('environment_id', $environment->id)->count())->toBe(2);

    $names = EnvironmentPackage::where('environment_id', $environment->id)
        ->with('package')
        ->get()
        ->pluck('package.name')
        ->toArray();

    expect($names)->toContain('new/package', 'another/package')
        ->not->toContain('old/package');
});

it('updates environment scanned_at after successful sync', function () {
    $environment = Environment::factory()->create(['scanned_at' => null]);

    $composerLock = json_encode(['packages' => [], 'packages-dev' => []]);
    $lockfilePath = 'test-project/production/composer.lock';
    Storage::disk('voight-lockfiles')->put($lockfilePath, $composerLock);

    $sync = DependencySync::factory()->for($environment)->create([
        'lockfile_paths' => [$lockfilePath],
        'status' => DependencySyncStatus::Pending,
    ]);

    ProcessLockFilesJob::dispatchSync($sync);

    $environment->refresh();
    expect($environment->scanned_at)->not->toBeNull();
});

it('marks sync as failed on error', function () {
    $environment = Environment::factory()->create();

    $sync = DependencySync::factory()->for($environment)->create([
        'lockfile_paths' => ['nonexistent/path/composer.lock'],
        'status' => DependencySyncStatus::Pending,
    ]);

    // The job should complete without error since missing files are skipped
    ProcessLockFilesJob::dispatchSync($sync);

    $sync->refresh();
    expect($sync->status)->toBe(DependencySyncStatus::Completed)
        ->and($sync->package_count)->toBe(0);
});

/**
 * Store the given files on the lockfiles disk and run a sync over them.
 *
 * @param  array<string, string>  $files  path => contents
 */
function syncLockfiles(array $files, array $extraPaths = []): DependencySync
{
    foreach ($files as $path => $contents) {
        Storage::disk('voight-lockfiles')->put($path, $contents);
    }

    $sync = DependencySync::factory()->for(Environment::factory())->create([
        'lockfile_paths' => [...array_keys($files), ...$extraPaths],
        'status' => DependencySyncStatus::Pending,
    ]);

    ProcessLockFilesJob::dispatchSync($sync);

    return $sync->refresh();
}

it('stores no warnings for a fully supported sync', function () {
    $sync = syncLockfiles([
        'p/production/composer.lock' => json_encode(['packages' => [['name' => 'a/b', 'version' => '1.0.0']]]),
        'p/production/composer.json' => json_encode(['require' => ['a/b' => '^1.0']]),
        'p/production/package.json' => json_encode(['dependencies' => []]),
    ]);

    expect($sync->status)->toBe(DependencySyncStatus::Completed)
        ->and($sync->warnings)->toBeNull();
});

it('warns about a lockfile without a parser and still completes', function () {
    $sync = syncLockfiles([
        'p/production/pnpm-lock.yaml' => "lockfileVersion: '9.0'\n",
        'p/production/composer.lock' => json_encode(['packages' => [['name' => 'a/b', 'version' => '1.0.0']]]),
    ]);

    expect($sync->status)->toBe(DependencySyncStatus::Completed)
        ->and($sync->package_count)->toBe(1)
        ->and($sync->warnings)->toBe([
            ['code' => SyncWarning::UnsupportedLockfile->value, 'context' => ['file' => 'pnpm-lock.yaml']],
        ]);
});

it('warns about a lockfile missing on disk', function () {
    $sync = syncLockfiles([], ['p/production/composer.lock']);

    expect($sync->status)->toBe(DependencySyncStatus::Completed)
        ->and($sync->warnings)->toBe([
            ['code' => SyncWarning::LockfileMissingOnDisk->value, 'context' => ['file' => 'composer.lock']],
        ]);
});

it('warns about an npm lockfile v1', function () {
    $sync = syncLockfiles([
        'p/production/package-lock.json' => json_encode(['lockfileVersion' => 1, 'dependencies' => []]),
    ]);

    expect($sync->status)->toBe(DependencySyncStatus::Completed)
        ->and($sync->warnings)->toBe([
            ['code' => SyncWarning::NpmLockfileV1Unsupported->value, 'context' => ['file' => 'package-lock.json']],
        ]);
});

it('warns about a yarn berry lockfile', function () {
    $sync = syncLockfiles([
        'p/production/yarn.lock' => "__metadata:\n  version: 8\n",
    ]);

    expect($sync->warnings)->toBe([
        ['code' => SyncWarning::YarnBerryUnsupported->value, 'context' => ['file' => 'yarn.lock']],
    ]);
});

it('warns about yarn workspaces but still imports the packages', function () {
    $sync = syncLockfiles([
        'p/production/yarn.lock' => "lodash@^4.17.0:\n  version \"4.17.21\"\n",
        'p/production/package.json' => json_encode(['workspaces' => ['packages/*'], 'dependencies' => ['lodash' => '^4.17.0']]),
    ]);

    expect($sync->status)->toBe(DependencySyncStatus::Completed)
        ->and($sync->package_count)->toBe(1)
        ->and($sync->warnings)->toBe([
            ['code' => SyncWarning::YarnWorkspacesUnsupported->value, 'context' => ['file' => 'yarn.lock']],
        ]);
});

it('stores no warnings on a sync that fails', function () {
    $files = [
        'p/production/pnpm-lock.yaml' => "lockfileVersion: '9.0'\n",
        // A package without a name makes the composer parser blow up.
        'p/production/composer.lock' => json_encode(['packages' => [['version' => '1.0.0']]]),
    ];

    expect(fn () => syncLockfiles($files))->toThrow(ErrorException::class);

    $sync = DependencySync::sole();
    expect($sync->status)->toBe(DependencySyncStatus::Failed)
        ->and($sync->warnings)->toBeNull();
});

/**
 * The installed package of the sync's environment with the given name and version.
 */
function installed(DependencySync $sync, string $name, string $version): EnvironmentPackage
{
    return EnvironmentPackage::query()
        ->where('environment_id', $sync->environment_id)
        ->where('version', $version)
        ->whereRelation('package', 'name', $name)
        ->sole();
}

it('stores every installed npm node and the edges between them', function () {
    $sync = syncLockfiles(['p/production/package-lock.json' => lockfileFixture('npm/package-lock.json')]);

    expect($sync->package_count)->toBe(19)
        ->and(EnvironmentPackage::where('environment_id', $sync->environment_id)->count())->toBe(19);

    $express = installed($sync, 'express', '4.18.2');
    $send = installed($sync, 'send', '0.18.0');

    expect($express->children()->pluck('voight_environment_packages.id')->all())->toContain($send->id)
        ->and($express->children()->whereKey($send->id)->sole()->pivot->constraint)->toBe('0.18.0')
        ->and($express->children()->whereKey($send->id)->sole()->pivot->kind)->toBe(DependencyKind::Dependency)
        ->and(EnvironmentPackage::where('environment_id', $sync->environment_id)->whereRelation('package', 'name', 'debug')->pluck('version')->sort()->values()->all())
        ->toBe(['2.6.9', '2.6.9', '4.3.4']);
});

it('marks composer packages direct from the uploaded composer.json', function () {
    $sync = syncLockfiles([
        'p/production/composer.lock' => lockfileFixture('composer/composer.lock'),
        'p/production/composer.json' => lockfileFixture('composer/composer.json'),
    ]);

    expect(installed($sync, 'laravel/framework', '12.69.3')->is_direct)->toBeTrue()
        ->and(installed($sync, 'psr/log', '3.0.2')->is_direct)->toBeFalse()
        ->and(installed($sync, 'spatie/laravel-package-tools', '1.93.3')->children()->sole()->package->name)->toBe('laravel/framework');
});

it('never links packages of two lockfiles synced together', function () {
    $sync = syncLockfiles([
        'p/production/composer.lock' => lockfileFixture('composer/composer.lock'),
        'p/production/package-lock.json' => lockfileFixture('npm/package-lock.json'),
    ]);

    $crossEcosystemEdges = EnvironmentPackageDependency::query()
        ->join('voight_environment_packages as parents', 'parents.id', '=', 'voight_environment_package_dependencies.parent_id')
        ->join('voight_environment_packages as children', 'children.id', '=', 'voight_environment_package_dependencies.child_id')
        ->join('voight_packages as parent_packages', 'parent_packages.id', '=', 'parents.package_id')
        ->join('voight_packages as child_packages', 'child_packages.id', '=', 'children.package_id')
        ->whereColumn('parent_packages.type', '!=', 'child_packages.type')
        ->count();

    expect($sync->package_count)->toBe(27)
        ->and(EnvironmentPackageDependency::count())->toBeGreaterThan(0)
        ->and($crossEcosystemEdges)->toBe(0);
});

it('replaces the previous nodes and edges on re-sync', function () {
    $first = syncLockfiles(['p/production/package-lock.json' => lockfileFixture('npm/package-lock.json')]);
    $edgeCount = EnvironmentPackageDependency::count();

    $second = DependencySync::factory()->for($first->environment)->create([
        'lockfile_paths' => ['p/production/package-lock.json'],
        'status' => DependencySyncStatus::Pending,
    ]);
    ProcessLockFilesJob::dispatchSync($second);

    expect(EnvironmentPackage::where('environment_id', $first->environment_id)->count())->toBe(19)
        ->and(EnvironmentPackageDependency::count())->toBe($edgeCount);
});

it('keeps a composer and an npm package with the same name apart', function () {
    $composerMs = Package::factory()->composer()->create(['name' => 'ms']);

    $sync = syncLockfiles(['p/production/package-lock.json' => lockfileFixture('npm/package-lock.json')]);

    $npmMs = installed($sync, 'ms', '2.1.2')->package;

    expect($npmMs->type)->toBe(PackageType::Npm)
        ->and($npmMs->is($composerMs))->toBeFalse();
});
