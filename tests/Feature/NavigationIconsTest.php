<?php

use Filament\Support\Facades\FilamentIcon;
use Statikbe\FilamentVoight\Enums\VoightIcon;
use Statikbe\FilamentVoight\Models\Project;
use Statikbe\FilamentVoight\Pages\McpAccess;
use Statikbe\FilamentVoight\Resources\AuditRunResource\RelationManagers\FindingsRelationManager;
use Statikbe\FilamentVoight\Resources\CustomerResource;
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
    [PackageResource::class, VoightIcon::NavigationPackages],
    [TeamResource::class, VoightIcon::NavigationTeams],
    [ProjectResource::class, VoightIcon::NavigationProjects],
    [VulnerabilityResource::class, VoightIcon::NavigationVulnerabilities],
    [CustomerResource::class, VoightIcon::NavigationCustomers],
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
    [CustomerResource\RelationManagers\ProjectRelationManager::class, VoightIcon::TabCustomerProjects],
    [AffectedPackagesRelationManager::class, VoightIcon::TabVulnerabilityAffectedPackages],
    [AffectedProjectsRelationManager::class, VoightIcon::TabVulnerabilityAffectedProjects],
    [ActiveFindingsRelationManager::class, VoightIcon::TabPackageActiveFindings],
    [InstallationsRelationManager::class, VoightIcon::TabPackageInstallations],
    [KnownVulnerabilitiesRelationManager::class, VoightIcon::TabPackageKnownVulnerabilities],
]);

it('resolves the project info tab icon through VoightIcon', function () {
    $page = new ViewProject;

    expect($page->getContentTabIcon())->toBe(VoightIcon::TabProjectInfo->default());

    FilamentIcon::register([VoightIcon::TabProjectInfo->alias() => 'heroicon-o-star']);

    expect($page->getContentTabIcon())->toBe('heroicon-o-star');
});

it('resolves the MCP access navigation icon at render time, so host overrides made after the panel registers apply', function () {
    $this->actingAs(mcpUser());
    FilamentIcon::register([VoightIcon::NavigationMcpAccess->alias() => 'heroicon-o-star']);

    $item = McpAccess::getNavigationItems()[0];

    expect($item->getIcon())->toBe('heroicon-o-star');
});
