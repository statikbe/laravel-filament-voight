<?php

use Filament\Facades\Filament;
use Filament\Navigation\NavigationGroup;
use Statikbe\FilamentVoight\Models\AuditFinding;
use Statikbe\FilamentVoight\Models\AuditRun;
use Statikbe\FilamentVoight\Models\Environment;
use Statikbe\FilamentVoight\Models\Vulnerability;
use Statikbe\FilamentVoight\Pages\McpAccess;
use Statikbe\FilamentVoight\Resources\AuditRunResource;
use Statikbe\FilamentVoight\Resources\CustomerResource;
use Statikbe\FilamentVoight\Resources\EnvironmentResource\Pages\ListEnvironments;
use Statikbe\FilamentVoight\Resources\PackageResource;
use Statikbe\FilamentVoight\Resources\ProjectResource;
use Statikbe\FilamentVoight\Resources\TeamResource;
use Statikbe\FilamentVoight\Resources\VulnerabilityResource;

it('places every item in its navigation group and position', function (string $class, ?string $group, int $sort) {
    expect($class::getNavigationGroup())->toBe($group === null ? null : voightTrans("navigation.{$group}"))
        ->and($class::getNavigationSort())->toBe($sort);
})->with([
    'projects' => [ProjectResource::class, null, 1],
    'vulnerabilities' => [VulnerabilityResource::class, null, 2],
    'environments' => [ListEnvironments::class, 'management', 1],
    'packages' => [PackageResource::class, 'management', 2],
    'audit runs' => [AuditRunResource::class, 'management', 3],
    'customers' => [CustomerResource::class, 'management', 4],
    'teams' => [TeamResource::class, 'management', 5],
    'mcp access' => [McpAccess::class, 'settings', 100],
]);

it('registers the navigation groups in order', function () {
    $labels = array_map(
        fn (NavigationGroup $group): string => $group->getLabel(),
        array_values(Filament::getDefaultPanel()->getNavigationGroups()),
    );

    expect($labels)->toBe([voightTrans('navigation.management'), voightTrans('navigation.settings')]);
});

it('registers the vulnerabilities resource in the navigation', function () {
    expect(VulnerabilityResource::shouldRegisterNavigation())->toBeTrue();
});

it('has no vulnerability badge without open critical or high findings', function () {
    $run = AuditRun::factory()->create();
    AuditFinding::factory()->for($run, 'auditRun')->create([
        'vulnerability_id' => Vulnerability::factory()->create(['vulnerability_score' => 5.0])->id,
    ]);

    expect(VulnerabilityResource::getNavigationBadge())->toBeNull();
});

it('counts distinct open critical and high vulnerabilities and colours the badge', function () {
    $environment = Environment::factory()->create();
    $run = AuditRun::factory()->for($environment)->create();
    $high = Vulnerability::factory()->create(['vulnerability_score' => 7.5]);

    // Same vulnerability found in two packages counts once.
    AuditFinding::factory()->count(2)->for($run, 'auditRun')->create(['vulnerability_id' => $high->id]);

    expect(VulnerabilityResource::getNavigationBadge())->toBe('1')
        ->and(VulnerabilityResource::getNavigationBadgeColor())->toBe('warning');

    AuditFinding::factory()->for($run, 'auditRun')->create([
        'vulnerability_id' => Vulnerability::factory()->create(['vulnerability_score' => 9.8])->id,
    ]);

    expect(VulnerabilityResource::getNavigationBadge())->toBe('2')
        ->and(VulnerabilityResource::getNavigationBadgeColor())->toBe('critical');
});

it('ignores findings that only exist in superseded audit runs', function () {
    $environment = Environment::factory()->create();
    $old = AuditRun::factory()->for($environment)->create(['started_at' => now()->subDay()]);
    AuditRun::factory()->for($environment)->create(['started_at' => now()]);
    AuditFinding::factory()->for($old, 'auditRun')->create([
        'vulnerability_id' => Vulnerability::factory()->create(['vulnerability_score' => 9.8])->id,
    ]);

    expect(VulnerabilityResource::getNavigationBadge())->toBeNull();
});

it('shows no audit run badge when nothing is pending or running', function () {
    AuditRun::factory()->create();

    expect(AuditRunResource::getNavigationBadge())->toBeNull();
});

it('counts pending and running audit runs', function () {
    AuditRun::factory()->pending()->create();
    AuditRun::factory()->running()->create();
    AuditRun::factory()->create();

    expect(AuditRunResource::getNavigationBadge())->toBe('2')
        ->and(AuditRunResource::getNavigationBadgeColor())->toBe('info');
});
