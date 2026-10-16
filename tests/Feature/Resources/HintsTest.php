<?php

use Filament\Actions\Testing\TestAction;
use Filament\Forms\Components\Field;
use Illuminate\Foundation\Auth\User;
use Livewire\Livewire;
use Statikbe\FilamentVoight\Models\Package;
use Statikbe\FilamentVoight\Models\Project;
use Statikbe\FilamentVoight\Models\Vulnerability;
use Statikbe\FilamentVoight\Resources\PackageResource\Pages\ViewPackage;
use Statikbe\FilamentVoight\Resources\PackageResource\RelationManagers\ActiveFindingsRelationManager;
use Statikbe\FilamentVoight\Resources\PackageResource\RelationManagers\InstallationsRelationManager;
use Statikbe\FilamentVoight\Resources\PackageResource\RelationManagers\KnownVulnerabilitiesRelationManager;
use Statikbe\FilamentVoight\Resources\ProjectResource\Pages\EditProject;
use Statikbe\FilamentVoight\Resources\ProjectResource\Pages\ViewProject;
use Statikbe\FilamentVoight\Resources\ProjectResource\RelationManagers\AlertSettingsRelationManager;
use Statikbe\FilamentVoight\Resources\ProjectResource\RelationManagers\EnvironmentsRelationManager;
use Statikbe\FilamentVoight\Resources\ProjectResource\RelationManagers\VulnerabilitiesRelationManager;
use Statikbe\FilamentVoight\Resources\VulnerabilityResource\Pages\ViewVulnerability;
use Statikbe\FilamentVoight\Resources\VulnerabilityResource\RelationManagers\AffectedPackagesRelationManager;
use Statikbe\FilamentVoight\Resources\VulnerabilityResource\RelationManagers\AffectedProjectsRelationManager;

beforeEach(function () {
    $this->actingAs(new User);
});

it('explains the stat tiles on the view pages', function () {
    $project = Project::factory()->create();
    $package = Package::factory()->create();
    $vulnerability = Vulnerability::factory()->create();

    Livewire::test(ViewProject::class, ['record' => $project->getRouteKey()])
        ->assertSee(voightTrans('hints.open_findings'))
        ->assertSee(voightTrans('hints.last_audit'));

    Livewire::test(ViewPackage::class, ['record' => $package->getRouteKey()])
        ->assertSee(voightTrans('hints.open_findings'))
        ->assertSee(voightTrans('hints.installed_summary'));

    Livewire::test(ViewVulnerability::class, ['record' => $vulnerability->getRouteKey()])
        ->assertSee(voightTrans('hints.cvss'));
});

it('explains the relation manager badges', function (string $manager, string $key) {
    expect($manager::getBadgeTooltip(Project::factory()->create(), ViewProject::class))
        ->toBe(voightTrans("hints.badges.{$key}"));
})->with([
    [VulnerabilitiesRelationManager::class, 'project_vulnerabilities'],
    [EnvironmentsRelationManager::class, 'project_environments'],
    [ActiveFindingsRelationManager::class, 'package_active_findings'],
    [InstallationsRelationManager::class, 'package_installations'],
    [KnownVulnerabilitiesRelationManager::class, 'package_known_vulnerabilities'],
    [AffectedProjectsRelationManager::class, 'vulnerability_affected_projects'],
    [AffectedPackagesRelationManager::class, 'vulnerability_affected_packages'],
]);

it('explains the alert setting fields', function () {
    Livewire::test(AlertSettingsRelationManager::class, [
        'ownerRecord' => Project::factory()->create(),
        'pageClass' => EditProject::class,
    ])
        ->mountAction(TestAction::make('create')->table())
        ->assertSchemaComponentExists(
            'severity_threshold',
            checkComponentUsing: fn (Field $component): bool => $component->getChildSchema(Field::BELOW_CONTENT_SCHEMA_KEY)?->getComponents()[0]->getContent() === voightTrans('hints.alert.severity_threshold'),
        )
        ->assertSchemaComponentExists(
            'frequency',
            checkComponentUsing: fn (Field $component): bool => $component->getChildSchema(Field::BELOW_CONTENT_SCHEMA_KEY)?->getComponents()[0]->getContent() === voightTrans('hints.alert.frequency'),
        );
});
