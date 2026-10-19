<?php

use Statikbe\FilamentVoight\Enums\PackageType;
use Statikbe\FilamentVoight\Models\AuditFinding;
use Statikbe\FilamentVoight\Models\AuditRun;
use Statikbe\FilamentVoight\Models\Environment;
use Statikbe\FilamentVoight\Models\Package;
use Statikbe\FilamentVoight\Models\Project;
use Statikbe\FilamentVoight\Models\Vulnerability;
use Statikbe\FilamentVoight\Notifications\AuditSummary;

function addFindingWithScore(AuditRun $run, float $score, PackageType $type = PackageType::Composer): AuditFinding
{
    return AuditFinding::factory()->for($run, 'auditRun')->for(Package::factory()->state(['type' => $type]), 'package')->create([
        'vulnerability_id' => Vulnerability::factory()->create(['vulnerability_score' => $score])->id,
    ]);
}

/**
 * @return array<string, int>
 */
function allSeverityCounts(AuditSummary $summary): array
{
    $counts = [];

    foreach ($summary->sections as $section) {
        foreach ($section['severityCounts'] as $severity => $count) {
            $counts[$severity] = ($counts[$severity] ?? 0) + $count;
        }
    }

    return $counts;
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
        ->and(allSeverityCounts($summary))->toEqual([
            'critical' => 1,
            'high' => 1,
            'medium' => 1,
        ])
        ->and($summary->environmentNames)->toBe(['production']);
});

it('caps top findings at three per type, sorted by score descending', function () {
    $run = AuditRun::factory()->create();

    foreach ([1.0, 6.0, 3.0, 5.0, 2.0, 4.0] as $score) {
        addFindingWithScore($run, $score);
    }

    $summary = AuditSummary::fromAuditRun($run, 0.0);
    $section = $summary->sections[PackageType::Composer->value];

    expect($summary->totalFindings)->toBe(6)
        ->and($section['total'])->toBe(6)
        ->and($section['topFindings'])->toHaveCount(3)
        ->and(array_column($section['topFindings'], 'score'))->toBe([6.0, 5.0, 4.0]);
});

it('splits findings into one section per package type in enum order', function () {
    $run = AuditRun::factory()->create();

    foreach ([[PackageType::Npm, 5.0], [PackageType::Composer, 8.0], [PackageType::Npm, 9.0]] as [$type, $score]) {
        AuditFinding::factory()->for($run, 'auditRun')->for(Package::factory()->state(['type' => $type]), 'package')->create([
            'vulnerability_id' => Vulnerability::factory()->create(['vulnerability_score' => $score])->id,
        ]);
    }

    $summary = AuditSummary::fromAuditRun($run, 0.0);

    expect(array_keys($summary->sections))->toBe(['composer', 'npm'])
        ->and($summary->sections['composer']['total'])->toBe(1)
        ->and($summary->sections['composer']['severityCounts'])->toBe(['high' => 1])
        ->and($summary->sections['npm']['total'])->toBe(2)
        ->and($summary->sections['npm']['severityCounts'])->toBe(['critical' => 1, 'medium' => 1])
        ->and(array_column($summary->sections['npm']['topFindings'], 'score'))->toBe([9.0, 5.0]);
});

it('omits package types without findings', function () {
    $run = AuditRun::factory()->create();
    AuditFinding::factory()->for($run, 'auditRun')->for(Package::factory()->composer(), 'package')->create([
        'vulnerability_id' => Vulnerability::factory()->create(['vulnerability_score' => 8.0])->id,
    ]);

    expect(array_keys(AuditSummary::fromAuditRun($run, 0.0)->sections))->toBe(['composer']);
});

it('exposes package and version details on top findings', function () {
    $run = AuditRun::factory()->create();
    $finding = addFindingWithScore($run, 9.5);

    $top = array_values(AuditSummary::fromAuditRun($run, 7.0)->sections)[0]['topFindings'][0];

    expect($top['package'])->toBe($finding->package->name)
        ->and($top['installed_version'])->toBe('1.0.0')
        ->and($top['fixed_version'])->toBe('2.0.0');
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
        ->and(allSeverityCounts($summary))->toEqual(['critical' => 1, 'high' => 1])
        ->and($summary->environmentNames)->toContain('production', 'staging');
});

it('builds absolute detail urls, since they are generated off-request', function () {
    $project = Project::factory()->create();
    $environment = Environment::factory()->for($project)->create();
    $run = AuditRun::factory()->for($environment)->create();

    expect(AuditSummary::fromAuditRun($run, 0.0)->detailUrl)->toStartWith('http')
        ->and(AuditSummary::fromProjectOutstanding($project, 0.0)->detailUrl)->toStartWith('http');
});

it('reports no findings when nothing matches the threshold', function () {
    $run = AuditRun::factory()->create();
    addFindingWithScore($run, 5.0);

    $summary = AuditSummary::fromAuditRun($run, 7.0);

    expect($summary->hasFindings())->toBeFalse()
        ->and($summary->totalFindings)->toBe(0)
        ->and($summary->sections)->toBe([]);
});

it('links an immediate summary to the audit run that produced it', function () {
    $run = AuditRun::factory()->create();
    $summary = AuditSummary::fromAuditRun($run, 0.0);

    expect($summary->detailUrl)->toContain($run->getRouteKey())
        ->and($summary->detailLabel)->toBe(voightTrans('notifications.common.view_audit_run'));
});

it('links a digest to the project because it spans several runs', function () {
    $project = Project::factory()->create();
    $summary = AuditSummary::fromProjectOutstanding($project, 0.0);

    expect($summary->detailUrl)->toContain($project->getRouteKey())
        ->and($summary->detailLabel)->toBe(voightTrans('notifications.common.view_project'));
});

it('counts findings with a fixed version as fixable and exposes the project url', function () {
    $run = AuditRun::factory()->create();
    addFindingWithScore($run, 9.5);
    $unfixed = addFindingWithScore($run, 8.0);
    $unfixed->update(['fixed_version' => null]);

    $summary = AuditSummary::fromAuditRun($run, 0.0);

    expect(array_sum(array_column($summary->sections, 'fixableCount')))->toBe(1)
        ->and($summary->projectUrl)->toContain($run->environment->project->getRouteKey());
});
