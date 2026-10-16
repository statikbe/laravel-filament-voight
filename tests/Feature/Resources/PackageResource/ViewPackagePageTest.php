<?php

use Illuminate\Foundation\Auth\User;
use Livewire\Livewire;
use Statikbe\FilamentVoight\Enums\PackageType;
use Statikbe\FilamentVoight\Models\AuditFinding;
use Statikbe\FilamentVoight\Models\AuditRun;
use Statikbe\FilamentVoight\Models\Environment;
use Statikbe\FilamentVoight\Models\EnvironmentPackage;
use Statikbe\FilamentVoight\Models\Package;
use Statikbe\FilamentVoight\Models\Vulnerability;
use Statikbe\FilamentVoight\Models\VulnerablePackageRange;
use Statikbe\FilamentVoight\Resources\PackageResource;
use Statikbe\FilamentVoight\Resources\PackageResource\Pages\ViewPackage;
use Statikbe\FilamentVoight\Resources\PackageResource\RelationManagers\ActiveFindingsRelationManager;
use Statikbe\FilamentVoight\Resources\PackageResource\RelationManagers\InstallationsRelationManager;
use Statikbe\FilamentVoight\Resources\PackageResource\RelationManagers\KnownVulnerabilitiesRelationManager;

beforeEach(function () {
    $user = new User;
    $user->name = 'Test User';
    $user->email = 'test@example.com';
    $this->actingAs($user);
});

it("shows the package's name in the view page", function () {
    $package = Package::factory()->create(['name' => 'test-vendor/unique-package-name']);
    $env = Environment::factory()->scanned()->create();
    EnvironmentPackage::factory()->for($env)->for($package)->create();

    Livewire::test(ViewPackage::class, ['record' => $package->getRouteKey()])
        ->assertSuccessful()
        ->assertSee('test-vendor/unique-package-name');
});

it('rejects a nonexistent package via HTTP', function () {
    // Newer Filament resolves the record before authorization and returns 404;
    // older 5.0.x versions authorize first and return 403. Either way the
    // request must be rejected — asserting a specific code tests Filament, not us.
    $status = $this->get(PackageResource::getUrl('view', ['record' => 'nonexistent-ulid']))->status();

    expect($status)->toBeIn([403, 404]);
});

it('renders the overview tab, the stats and the relation manager tabs', function () {
    $package = Package::factory()->composer()->create([
        'name' => 'acme/widgets',
        'latest_version' => '3.2.1',
        'latest_version_updated_at' => now()->subDays(3),
    ]);
    $environment = Environment::factory()->create();
    EnvironmentPackage::factory()->for($environment)->for($package)->create();
    $run = AuditRun::factory()->for($environment)->create();
    AuditFinding::factory()->for($run, 'auditRun')->for($package)->for(Vulnerability::factory()->high())->create();

    Livewire::test(ViewPackage::class, ['record' => $package->getRouteKey()])
        ->assertSuccessful()
        ->assertSee(voightTrans('models.package.view.overview_tab'))
        ->assertSee(voightTrans('models.package.view.active_findings_title'))
        ->assertSee(voightTrans('models.package.view.installations_title'))
        ->assertSee(voightTrans('models.package.view.known_vulnerabilities_title'))
        ->assertSee('3.2.1')
        ->assertSee('3 days ago')
        ->assertSee('acme/widgets')
        ->assertSee('https://packagist.org/packages/acme/widgets')
        ->assertSee(PackageType::Composer->label());
});

it('subheads with the type and latest version', function () {
    $package = Package::factory()->npm()->create(['latest_version' => '1.4.0']);

    $page = Livewire::test(ViewPackage::class, ['record' => $package->getRouteKey()])->instance();

    expect($page->getSubheading())->toBe('NPM · 1.4.0');
});

it('orders the tabs by relevance', function () {
    expect(PackageResource::getRelations())->toBe([
        ActiveFindingsRelationManager::class,
        InstallationsRelationManager::class,
        KnownVulnerabilitiesRelationManager::class,
    ]);
});

it('counts the open findings and colours the badge by worst severity', function () {
    $package = Package::factory()->create();
    $environment = Environment::factory()->create();
    $oldRun = AuditRun::factory()->for($environment)->create(['started_at' => now()->subDay()]);
    $latestRun = AuditRun::factory()->for($environment)->create(['started_at' => now()]);

    AuditFinding::factory()->for($oldRun, 'auditRun')->for($package)->for(Vulnerability::factory()->critical())->create();
    AuditFinding::factory()->for($latestRun, 'auditRun')->for($package)->for(Vulnerability::factory()->medium())->create();
    AuditFinding::factory()->for($latestRun, 'auditRun')->for($package)->for(Vulnerability::factory()->high())->create();

    expect(ActiveFindingsRelationManager::getBadge($package, ViewPackage::class))->toBe('2')
        ->and(ActiveFindingsRelationManager::getBadgeColor($package, ViewPackage::class))->toBe('danger');
});

it('has no badges on a package without findings, installations or known vulnerabilities', function () {
    $package = Package::factory()->create();

    expect(ActiveFindingsRelationManager::getBadge($package, ViewPackage::class))->toBeNull()
        ->and(ActiveFindingsRelationManager::getBadgeColor($package, ViewPackage::class))->toBeNull()
        ->and(InstallationsRelationManager::getBadge($package, ViewPackage::class))->toBeNull()
        ->and(KnownVulnerabilitiesRelationManager::getBadge($package, ViewPackage::class))->toBeNull();
});

it('counts installations and known vulnerabilities in gray badges', function () {
    $package = Package::factory()->create();
    EnvironmentPackage::factory()->count(2)->for($package)->create();
    VulnerablePackageRange::factory()->count(3)->for($package)->create();

    expect(InstallationsRelationManager::getBadge($package, ViewPackage::class))->toBe('2')
        ->and(InstallationsRelationManager::getBadgeColor($package, ViewPackage::class))->toBe('gray')
        ->and(KnownVulnerabilitiesRelationManager::getBadge($package, ViewPackage::class))->toBe('3')
        ->and(KnownVulnerabilitiesRelationManager::getBadgeColor($package, ViewPackage::class))->toBe('gray');
});
