<?php

use Illuminate\Support\Facades\DB;
use Statikbe\FilamentVoight\Enums\EnvironmentIssueType;
use Statikbe\FilamentVoight\Enums\SyncWarning;
use Statikbe\FilamentVoight\Models\AuditRun;
use Statikbe\FilamentVoight\Models\DependencySync;
use Statikbe\FilamentVoight\Models\Environment;
use Statikbe\FilamentVoight\Models\Project;
use Statikbe\FilamentVoight\Services\EnvironmentHealthService;
use Statikbe\FilamentVoight\Support\EnvironmentIssue;

/**
 * @return array<int, EnvironmentIssueType>
 */
function issueTypesFor(Environment $environment): array
{
    return app(EnvironmentHealthService::class)
        ->issuesFor($environment->fresh())
        ->map(fn (EnvironmentIssue $issue): EnvironmentIssueType => $issue->type)
        ->all();
}

it('reports nothing for an environment whose latest sync and scan succeeded', function () {
    $environment = Environment::factory()->create();
    DependencySync::factory()->for($environment)->create();
    AuditRun::factory()->for($environment)->create();

    expect(issueTypesFor($environment))->toBe([]);
});

it('reports an environment that was never synced', function () {
    expect(issueTypesFor(Environment::factory()->create()))->toBe([EnvironmentIssueType::NeverSynced]);
});

it('reports a failed sync with its error message', function () {
    $environment = Environment::factory()->create();
    DependencySync::factory()->for($environment)->failed()->create(['error_message' => 'Malformed composer.lock']);

    $issues = app(EnvironmentHealthService::class)->issuesFor($environment);

    expect($issues)->toHaveCount(1)
        ->and($issues->first()->type)->toBe(EnvironmentIssueType::SyncFailed)
        ->and($issues->first()->message)->toBe('Malformed composer.lock');
});

it('reports a failed scan with its error message', function () {
    $environment = Environment::factory()->create();
    DependencySync::factory()->for($environment)->create();
    AuditRun::factory()->for($environment)->failed()->create(['error_message' => 'Scanner returned 500']);

    $issues = app(EnvironmentHealthService::class)->issuesFor($environment);

    expect($issues)->toHaveCount(1)
        ->and($issues->first()->type)->toBe(EnvironmentIssueType::ScanFailed)
        ->and($issues->first()->message)->toBe('Scanner returned 500');
});

it('falls back to the issue label when a failure has no stored message', function () {
    $environment = Environment::factory()->create();
    DependencySync::factory()->for($environment)->create();
    AuditRun::factory()->for($environment)->failed()->create(['error_message' => null]);

    expect(app(EnvironmentHealthService::class)->issuesFor($environment)->first()->message)
        ->toBe(EnvironmentIssueType::ScanFailed->getLabel());
});

it('reports one issue per warning on the latest completed sync', function () {
    $environment = Environment::factory()->create();
    DependencySync::factory()->for($environment)->create(['warnings' => [
        ['code' => SyncWarning::UnsupportedLockfile->value, 'context' => ['file' => 'pnpm-lock.yaml']],
        ['code' => SyncWarning::LockfileMissingOnDisk->value, 'context' => ['file' => 'composer.lock']],
    ]]);

    $issues = app(EnvironmentHealthService::class)->issuesFor($environment);

    expect($issues->map(fn (EnvironmentIssue $issue) => $issue->type)->all())
        ->toBe([EnvironmentIssueType::SyncWarning, EnvironmentIssueType::SyncWarning])
        ->and($issues->first()->message)->toBe(SyncWarning::UnsupportedLockfile->message(['file' => 'pnpm-lock.yaml']));
});

it('ignores warning codes that no longer exist', function () {
    $environment = Environment::factory()->create();
    DependencySync::factory()->for($environment)->create(['warnings' => [
        ['code' => 'retired_warning', 'context' => []],
    ]]);

    expect(issueTypesFor($environment))->toBe([]);
});

it('clears a failed sync once a newer sync completes', function () {
    $environment = Environment::factory()->create();
    DependencySync::factory()->for($environment)->failed()->create();
    $this->travel(1)->minutes();
    DependencySync::factory()->for($environment)->create();

    expect(issueTypesFor($environment))->toBe([]);
});

it('clears a failed scan once a newer scan completes', function () {
    $environment = Environment::factory()->create();
    DependencySync::factory()->for($environment)->create();
    AuditRun::factory()->for($environment)->failed()->create();
    $this->travel(1)->minutes();
    AuditRun::factory()->for($environment)->create();

    expect(issueTypesFor($environment))->toBe([]);
});

it('neither raises nor clears an issue while a sync or scan is still running', function () {
    $environment = Environment::factory()->create();
    DependencySync::factory()->for($environment)->failed()->create();
    AuditRun::factory()->for($environment)->failed()->create();
    $this->travel(1)->minutes();
    DependencySync::factory()->for($environment)->pending()->create();
    AuditRun::factory()->for($environment)->running()->create();

    expect(issueTypesFor($environment))->toBe([EnvironmentIssueType::SyncFailed, EnvironmentIssueType::ScanFailed]);
});

it('drops the warnings of a sync that is no longer the latest', function () {
    $environment = Environment::factory()->create();
    DependencySync::factory()->for($environment)->create(['warnings' => [
        ['code' => SyncWarning::UnsupportedLockfile->value, 'context' => ['file' => 'pnpm-lock.yaml']],
    ]]);
    $this->travel(1)->minutes();
    DependencySync::factory()->for($environment)->create();

    expect(issueTypesFor($environment))->toBe([]);
});

it('orders issues worst first', function () {
    $environment = Environment::factory()->create();
    DependencySync::factory()->for($environment)->create(['warnings' => [
        ['code' => SyncWarning::UnsupportedLockfile->value, 'context' => ['file' => 'pnpm-lock.yaml']],
    ]]);
    AuditRun::factory()->for($environment)->failed()->create();

    expect(issueTypesFor($environment))->toBe([EnvironmentIssueType::ScanFailed, EnvironmentIssueType::SyncWarning]);
});

it('derives issues for eager loaded environments without further queries', function () {
    $project = Project::factory()->create();
    foreach (Environment::factory()->count(3)->for($project)->sequence(['name' => 'production'], ['name' => 'staging'], ['name' => 'development'])->create() as $environment) {
        DependencySync::factory()->for($environment)->failed()->create();
        AuditRun::factory()->for($environment)->failed()->create();
    }

    $environments = $project->environments()->with(Environment::HEALTH_RELATIONS)->get();

    DB::enableQueryLog();
    $environments->each(fn (Environment $environment) => app(EnvironmentHealthService::class)->issuesFor($environment));

    expect(DB::getQueryLog())->toBe([]);
});
