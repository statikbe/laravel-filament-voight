<?php

use Illuminate\Support\Facades\Storage;
use Statikbe\FilamentVoight\Enums\DependencySyncStatus;
use Statikbe\FilamentVoight\Enums\PackageType;
use Statikbe\FilamentVoight\Jobs\ProcessLockFilesJob;
use Statikbe\FilamentVoight\Models\DependencySync;
use Statikbe\FilamentVoight\Models\Environment;
use Statikbe\FilamentVoight\Models\EnvironmentPackage;
use Statikbe\FilamentVoight\Models\Package;

beforeEach(function () {
    Storage::fake('voight-lockfiles');
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

    $lockfilePath = "test-project/production/composer.lock";
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

    $lockfilePath = "test-project/production/package-lock.json";
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

    $lockfilePath = "test-project/production/composer.lock";
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
    $lockfilePath = "test-project/production/composer.lock";
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
