<?php

use Statikbe\FilamentVoight\Models\AuditFinding;
use Statikbe\FilamentVoight\Models\AuditRun;
use Statikbe\FilamentVoight\Models\Environment;
use Statikbe\FilamentVoight\Models\Project;
use Statikbe\FilamentVoight\Models\Vulnerability;
use Statikbe\FilamentVoight\Notifications\AuditSummary;

function addFindingWithScore(AuditRun $run, float $score): AuditFinding
{
    return AuditFinding::factory()->for($run, 'auditRun')->create([
        'vulnerability_id' => Vulnerability::factory()->create(['vulnerability_score' => $score])->id,
    ]);
}

it('filters findings by threshold and buckets severities at the boundaries', function () {
    $project = Project::factory()->create();
    $environment = Environment::factory()->for($project)->create(['name' => 'production']);
    $run = AuditRun::factory()->for($environment)->create();

    foreach ([9.0, 7.0, 4.0, 3.9] as $score) {
        addFindingWithScore($run, $score);
    }

    $summary = AuditSummary::fromAuditRun($run, 4.0);

    expect($summary->totalFindings)->toBe(3)
        ->and($summary->severityCounts)->toBe([
            'critical' => 1,
            'high' => 1,
            'medium' => 1,
        ])
        ->and($summary->environmentNames)->toBe(['production']);
});

it('caps top findings at five, sorted by score descending', function () {
    $run = AuditRun::factory()->create();

    foreach ([1.0, 6.0, 3.0, 5.0, 2.0, 4.0] as $score) {
        addFindingWithScore($run, $score);
    }

    $summary = AuditSummary::fromAuditRun($run, 0.0);

    expect($summary->totalFindings)->toBe(6)
        ->and($summary->topFindings)->toHaveCount(5)
        ->and(array_column($summary->topFindings, 'score'))->toBe([6.0, 5.0, 4.0, 3.0, 2.0]);
});

it('exposes package and version details on top findings', function () {
    $run = AuditRun::factory()->create();
    $finding = addFindingWithScore($run, 9.5);

    $summary = AuditSummary::fromAuditRun($run, 7.0);

    expect($summary->topFindings[0]['package'])->toBe($finding->package->name)
        ->and($summary->topFindings[0]['installed_version'])->toBe('1.0.0')
        ->and($summary->topFindings[0]['fixed_version'])->toBe('2.0.0');
});

it('only counts findings from the latest run per environment for outstanding summaries', function () {
    $project = Project::factory()->create();
    $production = Environment::factory()->for($project)->create(['name' => 'production']);
    $staging = Environment::factory()->for($project)->create(['name' => 'staging']);

    $oldRun = AuditRun::factory()->for($production)->create(['started_at' => now()->subDays(2)]);
    $latestProductionRun = AuditRun::factory()->for($production)->create(['started_at' => now()->subHour()]);
    $latestStagingRun = AuditRun::factory()->for($staging)->create(['started_at' => now()->subHour()]);

    addFindingWithScore($oldRun, 9.5);
    addFindingWithScore($latestProductionRun, 9.5);
    addFindingWithScore($latestStagingRun, 8.0);

    $otherProjectRun = AuditRun::factory()->create(['started_at' => now()->subHour()]);
    addFindingWithScore($otherProjectRun, 9.5);

    $summary = AuditSummary::fromProjectOutstanding($project, 7.0);

    expect($summary->totalFindings)->toBe(2)
        ->and($summary->severityCounts)->toBe(['critical' => 1, 'high' => 1])
        ->and($summary->environmentNames)->toContain('production', 'staging');
});

it('builds an absolute detail url containing the project code', function () {
    $project = Project::factory()->create();
    $environment = Environment::factory()->for($project)->create();
    $run = AuditRun::factory()->for($environment)->create();

    $summary = AuditSummary::fromAuditRun($run, 0.0);

    expect($summary->detailUrl)->toStartWith('http')
        ->and($summary->detailUrl)->toContain($project->project_code);
});

it('reports no findings when nothing matches the threshold', function () {
    $run = AuditRun::factory()->create();
    addFindingWithScore($run, 5.0);

    $summary = AuditSummary::fromAuditRun($run, 7.0);

    expect($summary->hasFindings())->toBeFalse()
        ->and($summary->totalFindings)->toBe(0)
        ->and($summary->severityCounts)->toBe([])
        ->and($summary->topFindings)->toBe([]);
});
