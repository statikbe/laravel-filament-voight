<?php

use Statikbe\FilamentVoight\Models\AuditFinding;
use Statikbe\FilamentVoight\Models\EnvironmentPackage;
use Statikbe\FilamentVoight\Models\EnvironmentSystemDetail;
use Statikbe\FilamentVoight\Support\VersionKey;

it('keys an installed package version on save', function () {
    $installed = EnvironmentPackage::factory()->create(['version' => 'v2.0.0']);

    expect($installed->version_sort)->toBe(VersionKey::for('2.0.0'));

    $installed->update(['version' => 'dev-main']);

    expect($installed->refresh()->version_sort)->toBeNull();
});

it('keys a finding installed version on save', function () {
    $finding = AuditFinding::factory()->create(['installed_version' => '1.0.0-beta.2']);

    expect($finding->refresh()->installed_version_sort)->toBe(VersionKey::for('1.0.0-beta.2'));
});

it('keys a php version on save without the distro suffix', function () {
    expect(EnvironmentSystemDetail::factory()->create(['php_version' => '8.3.12-1+ubuntu'])->refresh()->php_version_sort)
        ->toBe(VersionKey::for('8.3.12'))
        ->and(EnvironmentSystemDetail::factory()->create(['php_version' => null])->refresh()->php_version_sort)
        ->toBeNull();
});
