<?php

use Illuminate\Foundation\Auth\User;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;
use Statikbe\FilamentVoight\Enums\AuditRunStatus;
use Statikbe\FilamentVoight\Enums\EnvironmentStage;
use Statikbe\FilamentVoight\Enums\Severity;
use Statikbe\FilamentVoight\Models\AuditFinding;
use Statikbe\FilamentVoight\Models\AuditRun;
use Statikbe\FilamentVoight\Models\Environment;
use Statikbe\FilamentVoight\Models\Vulnerability;
use Statikbe\FilamentVoight\Resources\AuditRunResource\Pages\ListAuditRuns;

beforeEach(function () {
    $this->actingAs(new User);
});

it('lists audit runs', function () {
    $run = AuditRun::factory()->create();

    Livewire::test(ListAuditRuns::class)
        ->set('activeTab', 'all')
        ->assertSuccessful()
        ->assertCanSeeTableRecords([$run]);
});

it('reports the worst severity found in a run', function () {
    $run = AuditRun::factory()->create();

    foreach ([3.1, 9.5, 5.0] as $score) {
        AuditFinding::factory()->for($run, 'auditRun')->create([
            'vulnerability_id' => Vulnerability::factory()->create(['vulnerability_score' => $score])->id,
        ]);
    }

    Livewire::test(ListAuditRuns::class)
        ->set('activeTab', 'all')
        ->assertSuccessful()
        ->assertTableColumnStateSet('max_severity', Severity::Critical, $run);
});

it('counts the findings in a run', function () {
    $run = AuditRun::factory()->create();
    AuditFinding::factory()->for($run, 'auditRun')->count(3)->create();

    Livewire::test(ListAuditRuns::class)
        ->set('activeTab', 'all')
        ->assertSuccessful()
        ->assertTableColumnStateSet('audit_findings_count', 3, $run);
});

it('leaves max severity empty for a run with no findings', function () {
    $run = AuditRun::factory()->create();

    Livewire::test(ListAuditRuns::class)
        ->set('activeTab', 'all')
        ->assertSuccessful()
        ->assertTableColumnStateSet('max_severity', null, $run);
});

/**
 * Findings count and worst severity are aggregated in the list query rather than
 * per row. A page of runs currently costs 5 queries; the ceiling here leaves a
 * little headroom while still failing loudly if someone adds a column that
 * queries per record.
 *
 * Note this asserts an absolute budget, not a 2-vs-6 row comparison: the count
 * is already constant across row counts, so a comparison could never fail and
 * would guard nothing.
 */
it('renders the run list within a small fixed query budget', function () {
    foreach (range(1, 6) as $ignored) {
        AuditFinding::factory()->for(AuditRun::factory()->create(), 'auditRun')->create();
    }

    $queries = 0;
    DB::listen(function () use (&$queries): void {
        $queries++;
    });

    Livewire::test(ListAuditRuns::class)->assertSuccessful();

    expect($queries)->toBeLessThanOrEqual(8);
});

it('filters runs by status through the query builder', function () {
    $completed = AuditRun::factory()->create(['status' => AuditRunStatus::Completed]);
    $failed = AuditRun::factory()->create(['status' => AuditRunStatus::Failed]);

    Livewire::test(ListAuditRuns::class)
        ->set('activeTab', 'all')
        ->set('tableDeferredFilters.queryBuilder.rules', queryBuilderRule('status', 'is', ['values' => [AuditRunStatus::Failed->value]]))
        ->call('applyTableFilters')
        ->assertCanSeeTableRecords([$failed])
        ->assertCanNotSeeTableRecords([$completed]);
});

it('filters runs by project through the environment', function () {
    $wanted = AuditRun::factory()->create();
    $other = AuditRun::factory()->create();

    Livewire::test(ListAuditRuns::class)
        ->set('activeTab', 'all')
        ->set('tableDeferredFilters.queryBuilder.rules', queryBuilderRule('project', 'is', ['values' => [$wanted->environment->project_id]]))
        ->call('applyTableFilters')
        ->assertCanSeeTableRecords([$wanted])
        ->assertCanNotSeeTableRecords([$other]);
});

it('filters runs by findings count through the query builder', function () {
    $busy = AuditRun::factory()->create();
    AuditFinding::factory()->for($busy, 'auditRun')->count(3)->create();
    $quiet = AuditRun::factory()->create();
    AuditFinding::factory()->for($quiet, 'auditRun')->create();

    Livewire::test(ListAuditRuns::class)
        ->set('activeTab', 'all')
        ->set('tableDeferredFilters.queryBuilder.rules', queryBuilderRule('auditFindings', 'hasMin', ['count' => 2]))
        ->call('applyTableFilters')
        ->assertCanSeeTableRecords([$busy])
        ->assertCanNotSeeTableRecords([$quiet]);
});

it('filters runs by environment stage through the query builder', function (string $operator, bool $productionVisible) {
    $production = AuditRun::factory()->for(Environment::factory()->state(['name' => 'production']), 'environment')->create();
    $staging = AuditRun::factory()->for(Environment::factory()->state(['name' => 'staging']), 'environment')->create();

    Livewire::test(ListAuditRuns::class)
        ->set('activeTab', 'all')
        ->set('tableDeferredFilters.queryBuilder.rules', queryBuilderRule('environment_stage', $operator, ['values' => [EnvironmentStage::Production->value]]))
        ->call('applyTableFilters')
        ->assertCanSeeTableRecords($productionVisible ? [$production] : [$staging])
        ->assertCanNotSeeTableRecords($productionVisible ? [$staging] : [$production]);
})->with([
    'is' => ['is', true],
    'is not' => ['is.inverse', false],
]);

function auditRunWithScores(float ...$scores): AuditRun
{
    $run = AuditRun::factory()->create();

    foreach ($scores as $score) {
        AuditFinding::factory()->for($run, 'auditRun')->create([
            'vulnerability_id' => Vulnerability::factory()->create(['vulnerability_score' => $score])->id,
        ]);
    }

    return $run;
}

it('filters runs by their max severity through the query builder', function (string $operator, array $severities, array $visible, array $hidden) {
    $runs = [
        'critical' => auditRunWithScores(3.1, 9.5),
        'high' => auditRunWithScores(2.0, 7.0),
        'low' => auditRunWithScores(3.9),
        'none' => auditRunWithScores(0.0),
        'empty' => AuditRun::factory()->create(),
    ];

    Livewire::test(ListAuditRuns::class)
        ->set('activeTab', 'all')
        ->set('tableDeferredFilters.queryBuilder.rules', queryBuilderRule('max_severity', $operator, ['values' => array_map(fn (Severity $severity): string => $severity->value, $severities)]))
        ->call('applyTableFilters')
        ->assertCanSeeTableRecords(array_map(fn (string $key): AuditRun => $runs[$key], $visible))
        ->assertCanNotSeeTableRecords(array_map(fn (string $key): AuditRun => $runs[$key], $hidden));
})->with([
    'is high ignores runs with a worse finding' => ['is', [Severity::High], ['high'], ['critical', 'low', 'none', 'empty']],
    'is high or low' => ['is', [Severity::High, Severity::Low], ['high', 'low'], ['critical', 'none', 'empty']],
    'is none means a max score of zero' => ['is', [Severity::None], ['none'], ['critical', 'high', 'low', 'empty']],
    'is not critical' => ['is.inverse', [Severity::Critical], ['high', 'low', 'none', 'empty'], ['critical']],
]);

it('shows only the latest run of each environment on the latest per environment tab', function () {
    $environment = Environment::factory()->create();
    $old = AuditRun::factory()->for($environment, 'environment')->create(['started_at' => now()->subDay()]);
    $latest = AuditRun::factory()->for($environment, 'environment')->create(['started_at' => now()]);

    Livewire::test(ListAuditRuns::class)
        ->assertSet('activeTab', 'latest_per_environment')
        ->assertCanSeeTableRecords([$latest])
        ->assertCanNotSeeTableRecords([$old]);
});

it('shows only failed runs on the failed tab', function () {
    $failed = AuditRun::factory()->failed()->create();
    $completed = AuditRun::factory()->create();

    Livewire::test(ListAuditRuns::class)
        ->set('activeTab', 'failed')
        ->assertCanSeeTableRecords([$failed])
        ->assertCanNotSeeTableRecords([$completed]);
});

it('lists every run on the all tab', function () {
    $failed = AuditRun::factory()->failed()->create();
    $completed = AuditRun::factory()->create();

    Livewire::test(ListAuditRuns::class)
        ->set('activeTab', 'all')
        ->assertCanSeeTableRecords([$failed, $completed]);
});

it('counts the records of each tab in its badge', function () {
    $environment = Environment::factory()->create();
    AuditRun::factory()->for($environment, 'environment')->create(['started_at' => now()->subDay()]);
    AuditRun::factory()->for($environment, 'environment')->failed()->create(['started_at' => now()]);

    $tabs = Livewire::test(ListAuditRuns::class)->instance()->getCachedTabs();

    expect((int) $tabs['latest_per_environment']->getBadge())->toBe(1)
        ->and((int) $tabs['failed']->getBadge())->toBe(1)
        ->and((int) $tabs['all']->getBadge())->toBe(2);
});
