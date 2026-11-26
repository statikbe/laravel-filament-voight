<?php

use Illuminate\Support\Facades\DB;
use Statikbe\FilamentVoight\Models\AuditFinding;
use Statikbe\FilamentVoight\Models\EnvironmentPackage;
use Statikbe\FilamentVoight\Models\EnvironmentSystemDetail;
use Statikbe\FilamentVoight\Support\VersionKey;

it('fills missing keys', function () {
    $installed = EnvironmentPackage::factory()->create(['version' => '2.0.0']);
    $finding = AuditFinding::factory()->create(['installed_version' => '1.0.0-beta.2']);
    $details = EnvironmentSystemDetail::factory()->create(['php_version' => '8.3.12-1+ubuntu']);
    DB::table('voight_environment_packages')->update(['version_sort' => null]);
    DB::table('voight_audit_findings')->update(['installed_version_sort' => null]);
    DB::table('voight_environment_system_details')->update(['php_version_sort' => null]);

    $this->artisan('voight:rebuild-version-keys')->assertSuccessful();

    expect($installed->refresh()->version_sort)->toBe(VersionKey::for('2.0.0'))
        ->and($finding->refresh()->installed_version_sort)->toBe(VersionKey::for('1.0.0-beta.2'))
        ->and($details->refresh()->php_version_sort)->toBe(VersionKey::for('8.3.12'));
});

it('corrects stale keys and changes nothing on a second run', function () {
    $installed = EnvironmentPackage::factory()->create(['version' => '2.0.0']);
    $branch = EnvironmentPackage::factory()->create(['version' => 'dev-main']);
    DB::table('voight_environment_packages')->update(['version_sort' => 'stale']);

    $this->artisan('voight:rebuild-version-keys')
        ->expectsOutputToContain('voight_environment_packages: 2 updated')
        ->assertSuccessful();

    expect($installed->refresh()->version_sort)->toBe(VersionKey::for('2.0.0'))
        ->and($branch->refresh()->version_sort)->toBeNull();

    $this->artisan('voight:rebuild-version-keys')
        ->expectsOutputToContain('voight_environment_packages: 0 updated')
        ->expectsOutputToContain('voight_audit_findings: 0 updated')
        ->expectsOutputToContain('voight_environment_system_details: 0 updated')
        ->assertSuccessful();
});

it('fills the keys of existing rows when the migration adds the column', function () {
    $migration = require dirname(__DIR__) . '/database/migrations/0001_01_01_000030_add_version_sort_to_voight_environment_packages_table.php';
    $installed = EnvironmentPackage::factory()->create(['version' => '1.2.3']);
    $migration->down();

    $migration->up();

    expect(DB::table('voight_environment_packages')->where('id', $installed->id)->value('version_sort'))->toBe(VersionKey::for('1.2.3'));
});
