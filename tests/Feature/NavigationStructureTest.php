<?php

use Filament\Navigation\NavigationGroup;
use Statikbe\FilamentVoight\Pages\McpAccess;
use Statikbe\FilamentVoight\Resources\AuditFindingResource;
use Statikbe\FilamentVoight\Resources\AuditRunResource;
use Statikbe\FilamentVoight\Resources\CustomerResource;
use Statikbe\FilamentVoight\Resources\EnvironmentResource\Pages\ListEnvironments;
use Statikbe\FilamentVoight\Resources\PackageResource;
use Statikbe\FilamentVoight\Resources\ProjectResource;
use Statikbe\FilamentVoight\Resources\TeamResource;
use Statikbe\FilamentVoight\Resources\VulnerabilityResource;

it('places resources in the expected navigation groups', function () {
    expect(AuditFindingResource::getNavigationGroup())->toBeNull()
        ->and(AuditRunResource::getNavigationGroup())->toBe(voightTrans('navigation.monitoring'))
        ->and(ProjectResource::getNavigationGroup())->toBe(voightTrans('navigation.monitoring'))
        ->and(ListEnvironments::getNavigationGroup())->toBe(voightTrans('navigation.monitoring'))
        ->and(VulnerabilityResource::getNavigationGroup())->toBe(voightTrans('navigation.security'))
        ->and(PackageResource::getNavigationGroup())->toBe(voightTrans('navigation.security'))
        ->and(CustomerResource::getNavigationGroup())->toBe(voightTrans('navigation.organisation'))
        ->and(TeamResource::getNavigationGroup())->toBe(voightTrans('navigation.organisation'))
        ->and(McpAccess::getNavigationGroup())->toBe(voightTrans('navigation.settings'));
});

it('registers the groups in security, monitoring, organisation, settings order', function () {
    $labels = collect(filament()->getCurrentOrDefaultPanel()->getNavigationGroups())
        ->map(fn (NavigationGroup | string $group): string => $group instanceof NavigationGroup ? $group->getLabel() : $group)
        ->values()->all();

    expect($labels)->toBe([
        voightTrans('navigation.security'),
        voightTrans('navigation.monitoring'),
        voightTrans('navigation.organisation'),
        voightTrans('navigation.settings'),
    ]);
});

it('orders the top navigation items', function () {
    expect(ProjectResource::getNavigationSort())->toBeLessThan(ListEnvironments::getNavigationSort())
        ->and(ListEnvironments::getNavigationSort())->toBeLessThan(AuditRunResource::getNavigationSort());
});
