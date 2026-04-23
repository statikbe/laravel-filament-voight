<?php

use Illuminate\Foundation\Auth\User;
use Livewire\Livewire;
use Statikbe\FilamentVoight\Models\Package;
use Statikbe\FilamentVoight\Models\Vulnerability;
use Statikbe\FilamentVoight\Models\VulnerablePackageRange;
use Statikbe\FilamentVoight\Resources\PackageResource\Pages\ViewPackage;
use Statikbe\FilamentVoight\Resources\PackageResource\RelationManagers\KnownVulnerabilitiesRelationManager;

beforeEach(function () {
    $this->actingAs(new User);
});

it('lists all vulnerable package ranges for the package', function () {
    $package = Package::factory()->create();
    $vuln = Vulnerability::factory()->high()->create(['source_id' => 'GHSA-xxxx-yyyy-zzzz']);
    $range = VulnerablePackageRange::factory()
        ->for($package)->for($vuln)
        ->create(['affected_range' => '<1.2.3', 'fixed_version' => '1.2.3']);

    Livewire::test(KnownVulnerabilitiesRelationManager::class, [
        'ownerRecord' => $package,
        'pageClass' => ViewPackage::class,
    ])
        ->assertCanSeeTableRecords([$range])
        ->assertSee('GHSA-xxxx-yyyy-zzzz')
        ->assertSee('<1.2.3')
        ->assertSee('1.2.3');
});
