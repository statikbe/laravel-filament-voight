<?php

use Filament\Navigation\NavigationGroup;
use Statikbe\FilamentVoight\Pages\McpAccess;
use Statikbe\FilamentVoight\Resources\AuditRunResource;
use Statikbe\FilamentVoight\Resources\CustomerResource;
use Statikbe\FilamentVoight\Resources\EnvironmentResource\Pages\ListEnvironments;
use Statikbe\FilamentVoight\Resources\PackageResource;
use Statikbe\FilamentVoight\Resources\ProjectResource;
use Statikbe\FilamentVoight\Resources\TeamResource;
use Statikbe\FilamentVoight\Resources\VulnerabilityResource;

it('places resources in the expected navigation groups', function () {
    expect(AuditRunResource::getNavigationGroup())->toBeNull()
        ->and(ProjectResource::getNavigationGroup())->toBe(voightTrans('navigation.monitoring'))
        ->and(ListEnvironments::getNavigationGroup())->toBe(voightTrans('navigation.monitoring'))
        ->and(VulnerabilityResource::getNavigationGroup())->toBe(voightTrans('navigation.security'))
        ->and(PackageResource::getNavigationGroup())->toBe(voightTrans('navigation.security'))
        ->and(CustomerResource::getNavigationGroup())->toBe(voightTrans('navigation.organisation'))
        ->and(TeamResource::getNavigationGroup())->toBe(voightTrans('navigation.organisation'))
        ->and(McpAccess::getNavigationGroup())->toBe(voightTrans('navigation.settings'));
});

it('registers the groups in monitoring, security, organisation, settings order', function () {
    $labels = collect(filament()->getCurrentOrDefaultPanel()->getNavigationGroups())
        ->map(fn (NavigationGroup | string $group): string => $group instanceof NavigationGroup ? $group->getLabel() : $group)
        ->values()->all();

    expect($labels)->toBe([
        voightTrans('navigation.monitoring'),
        voightTrans('navigation.security'),
        voightTrans('navigation.organisation'),
        voightTrans('navigation.settings'),
    ]);
});
