<?php

use Illuminate\Foundation\Auth\User;
use Livewire\Livewire;
use Statikbe\FilamentVoight\Models\AuditRun;
use Statikbe\FilamentVoight\Models\Environment;
use Statikbe\FilamentVoight\Models\Project;
use Statikbe\FilamentVoight\Widgets\RecentAuditRunsWidget;

beforeEach(function () {
    $this->actingAs(new User);
});

it('shows only the globally most recent audit run per project across environments', function () {
    $project = Project::factory()->create(['project_code' => 'PRJ-ACROSS']);
    $envA = Environment::factory()->for($project)->create(['name' => 'production']);
    $envB = Environment::factory()->for($project)->create(['name' => 'staging']);

    $oldRunA = AuditRun::factory()->for($envA)->create(['started_at' => now()->subDays(5)]);
    $oldRunB = AuditRun::factory()->for($envB)->create(['started_at' => now()->subDays(3)]);
    $latestRun = AuditRun::factory()->for($envA)->create(['started_at' => now()->subHour()]);
    $olderRunB = AuditRun::factory()->for($envB)->create(['started_at' => now()->subDays(2)]);

    Livewire::test(RecentAuditRunsWidget::class)
        ->assertCanSeeTableRecords([$latestRun])
        ->assertCanNotSeeTableRecords([$oldRunA, $oldRunB, $olderRunB]);
});

it('shows exactly one most-recent row per project ordered most-recent-first', function () {
    $projectRecent = Project::factory()->create(['project_code' => 'PRJ-RECENT']);
    $projectMiddle = Project::factory()->create(['project_code' => 'PRJ-MIDDLE']);
    $projectOldest = Project::factory()->create(['project_code' => 'PRJ-OLDEST']);

    $envRecent = Environment::factory()->for($projectRecent)->create(['name' => 'production']);
    $envMiddle = Environment::factory()->for($projectMiddle)->create(['name' => 'production']);
    $envOldest = Environment::factory()->for($projectOldest)->create(['name' => 'production']);

    $recentOld = AuditRun::factory()->for($envRecent)->create(['started_at' => now()->subDays(10)]);
    $recentLatest = AuditRun::factory()->for($envRecent)->create(['started_at' => now()->subHour()]);

    $middleOld = AuditRun::factory()->for($envMiddle)->create(['started_at' => now()->subDays(9)]);
    $middleLatest = AuditRun::factory()->for($envMiddle)->create(['started_at' => now()->subDay()]);

    $oldestOld = AuditRun::factory()->for($envOldest)->create(['started_at' => now()->subDays(20)]);
    $oldestLatest = AuditRun::factory()->for($envOldest)->create(['started_at' => now()->subDays(3)]);

    Livewire::test(RecentAuditRunsWidget::class)
        ->assertCanSeeTableRecords([$recentLatest, $middleLatest, $oldestLatest], inOrder: true)
        ->assertCanNotSeeTableRecords([$recentOld, $middleOld, $oldestOld]);
});
