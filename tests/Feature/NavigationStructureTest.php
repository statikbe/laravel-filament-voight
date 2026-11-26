<?php

use Filament\Navigation\NavigationGroup;
use Statikbe\FilamentVoight\Resources\AuditFindingResource;
use Statikbe\FilamentVoight\Resources\AuditRunResource;
use Statikbe\FilamentVoight\Resources\CustomerResource;
use Statikbe\FilamentVoight\Resources\EnvironmentResource\Pages\ListEnvironments;
use Statikbe\FilamentVoight\Resources\PackageResource;
use Statikbe\FilamentVoight\Resources\ProjectResource;
use Statikbe\FilamentVoight\Resources\TeamResource;
use Statikbe\FilamentVoight\Resources\VulnerabilityResource;

it('places resources in the expected navigation groups', function () {
    expect(ProjectResource::getNavigationGroup())->toBeNull()
        ->and(VulnerabilityResource::getNavigationGroup())->toBeNull()
        ->and(PackageResource::getNavigationGroup())->toBeNull()
        ->and(CustomerResource::getNavigationGroup())->toBe(voightTrans('navigation.settings'))
        ->and(TeamResource::getNavigationGroup())->toBe(voightTrans('navigation.settings'));
});

it('registers the groups as settings only', function () {
    $labels = collect(filament()->getCurrentOrDefaultPanel()->getNavigationGroups())
        ->map(fn (NavigationGroup | string $group): string => $group instanceof NavigationGroup ? $group->getLabel() : $group)
        ->values()->all();

    expect($labels)->toBe([
        voightTrans('navigation.settings'),
    ]);
});

it('orders projects, vulnerabilities, packages', function () {
    expect(ProjectResource::getNavigationSort())->toBeLessThan(VulnerabilityResource::getNavigationSort())
        ->and(VulnerabilityResource::getNavigationSort())->toBeLessThan(PackageResource::getNavigationSort());
});

it('keeps environments, audit runs and open findings out of the navigation', function () {
    expect(ListEnvironments::shouldRegisterNavigation())->toBeFalse()
        ->and(AuditRunResource::shouldRegisterNavigation())->toBeFalse()
        ->and(AuditFindingResource::shouldRegisterNavigation())->toBeFalse();
});
