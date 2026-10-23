<?php

use Illuminate\Foundation\Auth\User;
use Illuminate\Support\Facades\Gate;
use Livewire\Livewire;
use Statikbe\FilamentVoight\Models\AuditFinding;
use Statikbe\FilamentVoight\Models\AuditRun;
use Statikbe\FilamentVoight\Models\Environment;
use Statikbe\FilamentVoight\Models\Vulnerability;
use Statikbe\FilamentVoight\Resources\AuditFindingResource;
use Statikbe\FilamentVoight\Resources\AuditFindingResource\Pages\ListOpenFindings;
use Statikbe\FilamentVoight\Tests\Support\DenyAllVulnerabilitiesPolicy;

beforeEach(function () {
    $this->actingAs(new User);
});

function openFindingsPageFinding(Environment $environment, Vulnerability $vulnerability): AuditFinding
{
    return AuditFinding::factory()
        ->for($environment->auditRuns()->latest('started_at')->first() ?? AuditRun::factory()->for($environment)->create())
        ->for($vulnerability)
        ->create();
}

function openFindingsPageFixture(): array
{
    $production = Environment::factory()->create(['name' => 'production']);
    $staging = Environment::factory()->create(['name' => 'staging']);

    $current = [
        'prodCritical' => openFindingsPageFinding($production, Vulnerability::factory()->critical()->create()),
        'prodHigh' => openFindingsPageFinding($production, Vulnerability::factory()->high()->create()),
        'prodMedium' => openFindingsPageFinding($production, Vulnerability::factory()->medium()->create()),
        'stagingCritical' => openFindingsPageFinding($staging, Vulnerability::factory()->critical()->create()),
    ];

    // Older run on production: superseded, so it is never current.
    $old = AuditRun::factory()->for($production)->create(['started_at' => now()->subDay()]);
    $oldFinding = AuditFinding::factory()->for($old)->for(Vulnerability::factory()->critical())->create();

    return $current + ['old' => $oldFinding];
}

it('defaults to production critical and high', function () {
    $f = openFindingsPageFixture();

    Livewire::test(ListOpenFindings::class)
        ->assertSuccessful()
        ->assertCanSeeTableRecords([$f['prodCritical'], $f['prodHigh']])
        ->assertCanNotSeeTableRecords([$f['prodMedium'], $f['stagingCritical'], $f['old']]);
});

it('scopes each tab', function (string $tab, array $visible, array $hidden) {
    $f = openFindingsPageFixture();

    Livewire::test(ListOpenFindings::class)
        ->set('activeTab', $tab)
        ->assertCanSeeTableRecords(array_map(fn (string $key) => $f[$key], $visible))
        ->assertCanNotSeeTableRecords(array_map(fn (string $key) => $f[$key], $hidden));
})->with([
    'production' => ['production', ['prodCritical', 'prodHigh', 'prodMedium'], ['stagingCritical', 'old']],
    'other stages' => ['other_stages', ['stagingCritical'], ['prodCritical', 'prodHigh', 'prodMedium', 'old']],
    'all' => ['all', ['prodCritical', 'prodHigh', 'prodMedium', 'stagingCritical'], ['old']],
]);

it('orders the tabs with production critical and high first', function () {
    $tabs = Livewire::test(ListOpenFindings::class)->instance()->getTabs();

    expect(array_keys($tabs))->toBe(['production_critical_high', 'production', 'other_stages', 'all']);
});

it('groups by vulnerability by default', function () {
    $component = Livewire::test(ListOpenFindings::class);

    expect($component->instance()->getTable()->getGrouping()?->getId())->toBe('vulnerability_id');
});

it('orders groups by score then vulnerability', function () {
    $production = Environment::factory()->create(['name' => 'production']);
    $other = Environment::factory()->create(['name' => 'production-eu']);

    $low = Vulnerability::factory()->create(['vulnerability_score' => 9.1]);
    $equalA = Vulnerability::factory()->create(['vulnerability_score' => 9.5]);
    $equalB = Vulnerability::factory()->create(['vulnerability_score' => 9.5]);
    $top = Vulnerability::factory()->create(['vulnerability_score' => 9.9]);

    // Interleaved creation order: rows of one vulnerability must still stay together.
    openFindingsPageFinding($production, $low);
    openFindingsPageFinding($production, $equalA);
    openFindingsPageFinding($production, $equalB);
    openFindingsPageFinding($other, $equalA);
    openFindingsPageFinding($other, $top);
    openFindingsPageFinding($other, $equalB);

    $first = collect([$equalA, $equalB])->sortBy('id')->pluck('id')->all();
    $groupOrder = array_merge([$top->id], $first, [$low->id]);

    $component = Livewire::test(ListOpenFindings::class)->set('activeTab', 'production');
    $ids = $component->instance()->getTableRecords()->pluck('vulnerability_id')->all();

    expect(array_values(array_unique($ids)))->toBe($groupOrder)
        ->and($ids)->toHaveCount(6);

    // Same vulnerability is contiguous.
    $runs = collect($ids)->chunkWhile(fn ($id, $key, $chunk) => $id === $chunk->last())->count();
    expect($runs)->toBe(4);
});

it('does not show the latest only toggle', function () {
    expect(Livewire::test(ListOpenFindings::class)->instance()->getTable()->getFilter('latest_only'))->toBeNull();
});

it('counts distinct critical vulnerabilities on production', function () {
    expect(AuditFindingResource::getNavigationBadge())->toBeNull();

    $critical = Vulnerability::factory()->critical()->create();
    foreach (['production', 'production-eu', 'live-1'] as $name) {
        openFindingsPageFinding(Environment::factory()->create(['name' => $name]), $critical);
    }
    openFindingsPageFinding(Environment::factory()->create(['name' => 'staging']), Vulnerability::factory()->critical()->create());
    openFindingsPageFinding(Environment::factory()->create(['name' => 'production-b']), Vulnerability::factory()->high()->create());

    expect(AuditFindingResource::getNavigationBadge())->toBe('1')
        ->and(AuditFindingResource::getNavigationBadgeColor())->toBe('danger');
});

it('counts the records of each tab in its badge', function () {
    openFindingsPageFixture();

    $tabs = Livewire::test(ListOpenFindings::class)->instance()->getCachedTabs();

    expect((int) $tabs['production_critical_high']->getBadge())->toBe(2)
        ->and((int) $tabs['production']->getBadge())->toBe(3)
        ->and((int) $tabs['other_stages']->getBadge())->toBe(1)
        ->and((int) $tabs['all']->getBadge())->toBe(4);
});

it('is not accessible, so it is left out of the navigation, when vulnerabilities cannot be listed', function () {
    expect(AuditFindingResource::canAccess())->toBeTrue();

    Gate::policy(Vulnerability::class, DenyAllVulnerabilitiesPolicy::class);

    expect(AuditFindingResource::canViewAny())->toBeFalse()
        ->and(AuditFindingResource::canAccess())->toBeFalse();
});

it('shows the project code, searchable by code and name', function () {
    $finding = openFindingsPageFixture()['prodCritical'];
    $project = $finding->auditRun->environment->project;

    Livewire::test(ListOpenFindings::class)
        ->assertTableColumnStateSet('auditRun.environment.project.project_code', $project->project_code, $finding)
        ->searchTable($project->project_code)
        ->assertCanSeeTableRecords([$finding])
        ->searchTable($project->name)
        ->assertCanSeeTableRecords([$finding]);
});
