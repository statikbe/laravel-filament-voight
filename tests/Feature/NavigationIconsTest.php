<?php

use Filament\Support\Facades\FilamentIcon;
use Statikbe\FilamentVoight\Enums\VoightIcon;
use Statikbe\FilamentVoight\Models\Project;
use Statikbe\FilamentVoight\Resources\AuditFindingResource;
use Statikbe\FilamentVoight\Resources\AuditRunResource;
use Statikbe\FilamentVoight\Resources\AuditRunResource\RelationManagers\FindingsRelationManager;
use Statikbe\FilamentVoight\Resources\CustomerResource;
use Statikbe\FilamentVoight\Resources\EnvironmentResource\Pages\ListEnvironments;
use Statikbe\FilamentVoight\Resources\PackageResource;
use Statikbe\FilamentVoight\Resources\PackageResource\RelationManagers\ActiveFindingsRelationManager;
use Statikbe\FilamentVoight\Resources\PackageResource\RelationManagers\InstallationsRelationManager;
use Statikbe\FilamentVoight\Resources\PackageResource\RelationManagers\KnownVulnerabilitiesRelationManager;
use Statikbe\FilamentVoight\Resources\ProjectResource;
use Statikbe\FilamentVoight\Resources\ProjectResource\Pages\ViewProject;
use Statikbe\FilamentVoight\Resources\ProjectResource\RelationManagers\AlertSettingsRelationManager;
use Statikbe\FilamentVoight\Resources\ProjectResource\RelationManagers\AuditRunsRelationManager;
use Statikbe\FilamentVoight\Resources\ProjectResource\RelationManagers\EnvironmentsRelationManager;
use Statikbe\FilamentVoight\Resources\ProjectResource\RelationManagers\VulnerabilitiesRelationManager;
use Statikbe\FilamentVoight\Resources\TeamResource;
use Statikbe\FilamentVoight\Resources\TeamResource\RelationManagers\ProjectsRelationManager;
use Statikbe\FilamentVoight\Resources\TeamResource\RelationManagers\UsersRelationManager;
use Statikbe\FilamentVoight\Resources\VulnerabilityResource;
use Statikbe\FilamentVoight\Resources\VulnerabilityResource\RelationManagers\AffectedPackagesRelationManager;
use Statikbe\FilamentVoight\Resources\VulnerabilityResource\RelationManagers\AffectedProjectsRelationManager;

it('resolves navigation icons through VoightIcon', function (string $navigable, VoightIcon $icon) {
    expect($navigable::getNavigationIcon())->toBe($icon->default());

    FilamentIcon::register([$icon->alias() => 'heroicon-o-star']);

    expect($navigable::getNavigationIcon())->toBe('heroicon-o-star');
})->with([
    [AuditFindingResource::class, VoightIcon::NavigationAuditFindings],
    [PackageResource::class, VoightIcon::NavigationPackages],
    [AuditRunResource::class, VoightIcon::NavigationAuditRuns],
    [TeamResource::class, VoightIcon::NavigationTeams],
    [ProjectResource::class, VoightIcon::NavigationProjects],
    [VulnerabilityResource::class, VoightIcon::NavigationVulnerabilities],
    [CustomerResource::class, VoightIcon::NavigationCustomers],
    [ListEnvironments::class, VoightIcon::NavigationEnvironments],
]);

it('resolves relation manager tab icons through VoightIcon', function (string $relationManager, VoightIcon $icon) {
    $owner = new Project;

    expect($relationManager::getIcon($owner, ViewProject::class))->toBe($icon->default());

    FilamentIcon::register([$icon->alias() => 'heroicon-o-star']);

    expect($relationManager::getIcon($owner, ViewProject::class))->toBe('heroicon-o-star');
})->with([
    [FindingsRelationManager::class, VoightIcon::TabAuditRunFindings],
    [AuditRunsRelationManager::class, VoightIcon::TabProjectAuditRuns],
    [EnvironmentsRelationManager::class, VoightIcon::TabProjectEnvironments],
    [VulnerabilitiesRelationManager::class, VoightIcon::TabProjectVulnerabilities],
    [AlertSettingsRelationManager::class, VoightIcon::TabProjectAlertSettings],
    [UsersRelationManager::class, VoightIcon::TabTeamUsers],
    [ProjectsRelationManager::class, VoightIcon::TabTeamProjects],
    [AffectedPackagesRelationManager::class, VoightIcon::TabVulnerabilityAffectedPackages],
    [AffectedProjectsRelationManager::class, VoightIcon::TabVulnerabilityAffectedProjects],
    [ActiveFindingsRelationManager::class, VoightIcon::TabPackageActiveFindings],
    [InstallationsRelationManager::class, VoightIcon::TabPackageInstallations],
    [KnownVulnerabilitiesRelationManager::class, VoightIcon::TabPackageKnownVulnerabilities],
]);
