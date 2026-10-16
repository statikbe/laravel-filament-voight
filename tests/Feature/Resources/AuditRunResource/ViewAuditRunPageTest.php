<?php

use Illuminate\Foundation\Auth\User;
use Livewire\Livewire;
use Statikbe\FilamentVoight\Enums\Severity;
use Statikbe\FilamentVoight\Models\AuditFinding;
use Statikbe\FilamentVoight\Models\AuditRun;
use Statikbe\FilamentVoight\Models\Environment;
use Statikbe\FilamentVoight\Models\Project;
use Statikbe\FilamentVoight\Models\Vulnerability;
use Statikbe\FilamentVoight\Resources\AuditRunResource;
use Statikbe\FilamentVoight\Resources\AuditRunResource\Pages\ViewAuditRun;

beforeEach(function () {
    $this->actingAs(new User);
});

it('shows an audit run', function () {
    $run = AuditRun::factory()->create();

    Livewire::test(ViewAuditRun::class, ['record' => $run->getRouteKey()])
        ->assertSuccessful();
});

it('rejects a nonexistent audit run via HTTP', function () {
    // Filament returns 404 or 403 depending on version; either is a rejection.
    $status = $this->get(AuditRunResource::getUrl('view', ['record' => 'nonexistent-ulid']))->status();

    expect($status)->toBeIn([403, 404]);
});

it('titles the run by environment and start, and subheads with project and environment', function () {
    $environment = Environment::factory()
        ->for(Project::factory()->create(['name' => 'Okrala']))
        ->create(['name' => 'production']);
    $run = AuditRun::factory()->for($environment)->create(['started_at' => now()]);

    $page = Livewire::test(ViewAuditRun::class, ['record' => $run->getRouteKey()])->instance();

    expect($page->getTitle())->toBe(voightTrans('models.audit_run.view.title', [
        'environment' => 'production',
        'started' => $run->started_at->format('d/m/Y H:i'),
    ]))->and($page->getSubheading())->toBe('Okrala · production');
});

it('shows the status, findings count, worst severity and duration', function () {
    $run = AuditRun::factory()->create([
        'started_at' => now()->subSeconds(95),
        'completed_at' => now(),
    ]);
    AuditFinding::factory()->count(2)->for($run, 'auditRun')->for(Vulnerability::factory()->high())->create();
    AuditFinding::factory()->for($run, 'auditRun')->for(Vulnerability::factory()->low())->create();

    $page = Livewire::test(ViewAuditRun::class, ['record' => $run->getRouteKey()])
        ->assertSuccessful()
        ->assertSee(voightTrans('models.audit_run.fields.max_severity'))
        ->assertSee(voightTrans('enums.severity.high'))
        ->assertSee('1m 35s');

    expect(entryState($page, 'stat_findings_count'))->toBe(3)
        ->and(entryState($page, 'stat_max_severity'))->toBe(Severity::High);
});

it('shows no severity for a run without findings and the error of a failed run', function () {
    $run = AuditRun::factory()->failed()->create(['error_message' => 'Scanner exploded']);

    Livewire::test(ViewAuditRun::class, ['record' => $run->getRouteKey()])
        ->assertSee(voightTrans('models.audit_run.view.no_findings'))
        ->assertSee(voightTrans('models.audit_run.view.error'))
        ->assertSee('Scanner exploded');
});

it('keeps four stat tiles and shows the duration in the details section', function () {
    $run = AuditRun::factory()->create(['started_at' => now()->subSeconds(95), 'completed_at' => now()]);

    $schema = Livewire::test(ViewAuditRun::class, ['record' => $run->getRouteKey()])->instance()->getSchema('infolist');

    expect($schema->getComponent('stat_findings_count'))->not->toBeNull()
        ->and($schema->getComponent('formatted_duration'))->not->toBeNull()
        ->and($schema->getComponent('formatted_duration')->getContainer()->getParentComponent()->getHeading())
        ->toBe(voightTrans('models.audit_run.sections.details'));
});
