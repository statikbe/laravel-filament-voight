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
