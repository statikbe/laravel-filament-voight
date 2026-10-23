<?php

use Statikbe\FilamentVoight\Enums\EnvironmentIssueType;
use Statikbe\FilamentVoight\Enums\SyncWarning;
use Statikbe\FilamentVoight\Models\AuditRun;
use Statikbe\FilamentVoight\Models\DependencySync;
use Statikbe\FilamentVoight\Models\Environment;
use Statikbe\FilamentVoight\Services\EnvironmentHealthService;
use Statikbe\FilamentVoight\Support\EnvironmentIssue;

/**
 * @return array<int, EnvironmentIssueType>
 */
function environmentHealthQueryIssueTypes(Environment $environment): array
{
    return app(EnvironmentHealthService::class)
        ->issuesFor($environment->fresh())
        ->map(fn (EnvironmentIssue $issue): EnvironmentIssueType => $issue->type)
        ->all();
}

dataset('failing environments', [
    'healthy' => [function (Environment $environment): void {
        DependencySync::factory()->for($environment)->create();
        AuditRun::factory()->for($environment)->create();
    }, false],
    'never synced' => [function (Environment $environment): void {}, true],
    'sync failed' => [function (Environment $environment): void {
        DependencySync::factory()->for($environment)->failed()->create();
    }, true],
    'scan failed with finished sync' => [function (Environment $environment): void {
        DependencySync::factory()->for($environment)->create();
        AuditRun::factory()->for($environment)->failed()->create();
    }, true],
    'scan failed without finished sync' => [function (Environment $environment): void {
        DependencySync::factory()->for($environment)->pending()->create();
        AuditRun::factory()->for($environment)->failed()->create();
    }, true],
    'failed sync then newer completed sync' => [function (Environment $environment): void {
        DependencySync::factory()->for($environment)->failed()->create(['created_at' => now()->subHour()]);
        DependencySync::factory()->for($environment)->create(['created_at' => now()]);
    }, false],
]);

it('constrains to failing environments in agreement with the health service', function (Closure $arrange, bool $expectedFailing) {
    $environment = Environment::factory()->create();
    $arrange($environment);

    $matches = Environment::query()
        ->where(fn ($query) => Environment::constrainToFailing($query))
        ->whereKey($environment)
        ->exists();

    $failingTypes = [EnvironmentIssueType::NeverSynced, EnvironmentIssueType::SyncFailed, EnvironmentIssueType::ScanFailed];
    $hasFailingIssue = array_filter(environmentHealthQueryIssueTypes($environment), fn (EnvironmentIssueType $type): bool => in_array($type, $failingTypes, true)) !== [];

    expect($matches)->toBe($expectedFailing)
        ->and($matches)->toBe($hasFailingIssue);
})->with('failing environments');

it('constrains to sync warnings in agreement with the health service', function (bool $withWarning) {
    $environment = Environment::factory()->create();
    DependencySync::factory()->for($environment)->create(
        $withWarning ? ['warnings' => [['code' => SyncWarning::UnsupportedLockfile->value, 'context' => ['file' => 'pnpm-lock.yaml']]]] : [],
    );

    $matches = Environment::query()
        ->where(fn ($query) => Environment::constrainToSyncWarnings($query))
        ->whereKey($environment)
        ->exists();

    expect($matches)->toBe($withWarning)
        ->and($matches)->toBe(in_array(EnvironmentIssueType::SyncWarning, environmentHealthQueryIssueTypes($environment), true));
})->with([true, false]);

it('ignores empty warnings and failed syncs when constraining to sync warnings', function () {
    $empty = Environment::factory()->create();
    DependencySync::factory()->for($empty)->create(['warnings' => []]);
    $failed = Environment::factory()->create();
    DependencySync::factory()->for($failed)->failed()->create(['warnings' => [['code' => SyncWarning::UnsupportedLockfile->value, 'context' => []]]]);

    expect(Environment::query()->where(fn ($query) => Environment::constrainToSyncWarnings($query))->exists())->toBeFalse();
});
