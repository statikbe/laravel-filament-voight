<?php

use Statikbe\FilamentVoight\Models\AuditFinding;
use Statikbe\FilamentVoight\Models\AuditRun;
use Statikbe\FilamentVoight\Models\Vulnerability;

it('reaches its vulnerabilities through its findings', function () {
    $run = AuditRun::factory()->create();
    $low = Vulnerability::factory()->create(['vulnerability_score' => 3.1]);
    $high = Vulnerability::factory()->create(['vulnerability_score' => 9.5]);

    AuditFinding::factory()->for($run, 'auditRun')->create(['vulnerability_id' => $low->id]);
    AuditFinding::factory()->for($run, 'auditRun')->create(['vulnerability_id' => $high->id]);

    expect((float) $run->vulnerabilities()->max('vulnerability_score'))->toBe(9.5);
});

it('does not reach vulnerabilities from another run', function () {
    $run = AuditRun::factory()->create();
    $other = AuditRun::factory()->create();

    AuditFinding::factory()->for($other, 'auditRun')->create([
        'vulnerability_id' => Vulnerability::factory()->create(['vulnerability_score' => 9.5])->id,
    ]);

    expect($run->vulnerabilities()->count())->toBe(0);
});

it('formats a duration under a minute in seconds', function () {
    $run = AuditRun::factory()->create([
        'started_at' => now(),
        'completed_at' => now()->addSeconds(45),
    ]);

    expect($run->formatted_duration)->toBe('45s');
});

it('formats a duration over a minute with minutes and seconds', function () {
    $run = AuditRun::factory()->create([
        'started_at' => now(),
        'completed_at' => now()->addSeconds(125),
    ]);

    expect($run->formatted_duration)->toBe('2m 5s');
});

it('has no duration while a run is still going', function () {
    expect(AuditRun::factory()->running()->create()->formatted_duration)->toBeNull();
});

it('has no duration for a run that never started', function () {
    expect(AuditRun::factory()->pending()->create()->formatted_duration)->toBeNull();
});
