<?php

use Illuminate\Foundation\Auth\User;
use Livewire\Livewire;
use Statikbe\FilamentVoight\Enums\Severity;
use Statikbe\FilamentVoight\Models\AuditFinding;
use Statikbe\FilamentVoight\Models\AuditRun;
use Statikbe\FilamentVoight\Models\Vulnerability;
use Statikbe\FilamentVoight\Resources\AuditRunResource\Pages\ListAuditRuns;

beforeEach(function () {
    $user = new User;
    $user->name = 'Test User';
    $user->email = 'test@example.com';
    $this->actingAs($user);
});

it('lists audit runs', function () {
    $run = AuditRun::factory()->create();

    Livewire::test(ListAuditRuns::class)
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
        ->assertSuccessful()
        ->assertTableColumnStateSet('max_severity', Severity::Critical, $run);
});

it('counts the findings in a run', function () {
    $run = AuditRun::factory()->create();
    AuditFinding::factory()->for($run, 'auditRun')->count(3)->create();

    Livewire::test(ListAuditRuns::class)
        ->assertSuccessful()
        ->assertTableColumnStateSet('audit_findings_count', 3, $run);
});

it('leaves max severity empty for a run with no findings', function () {
    $run = AuditRun::factory()->create();

    Livewire::test(ListAuditRuns::class)
        ->assertSuccessful()
        ->assertTableColumnStateSet('max_severity', null, $run);
});
