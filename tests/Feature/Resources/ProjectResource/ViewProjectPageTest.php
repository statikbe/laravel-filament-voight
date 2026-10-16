<?php

use Filament\Actions\Testing\TestAction;
use Illuminate\Foundation\Auth\User;
use Livewire\Livewire;
use Statikbe\FilamentVoight\Enums\AlertChannel;
use Statikbe\FilamentVoight\Enums\AlertFrequency;
use Statikbe\FilamentVoight\Models\AuditFinding;
use Statikbe\FilamentVoight\Models\AuditRun;
use Statikbe\FilamentVoight\Models\Customer;
use Statikbe\FilamentVoight\Models\DependencySync;
use Statikbe\FilamentVoight\Models\Environment;
use Statikbe\FilamentVoight\Models\Project;
use Statikbe\FilamentVoight\Models\Team;
use Statikbe\FilamentVoight\Models\Vulnerability;
use Statikbe\FilamentVoight\Resources\ProjectResource\Pages\ViewProject;
use Statikbe\FilamentVoight\Resources\ProjectResource\RelationManagers\AlertSettingsRelationManager;
use Statikbe\FilamentVoight\Resources\ProjectResource\RelationManagers\AuditRunsRelationManager;
use Statikbe\FilamentVoight\Resources\ProjectResource\RelationManagers\EnvironmentsRelationManager;
use Statikbe\FilamentVoight\Resources\ProjectResource\RelationManagers\VulnerabilitiesRelationManager;

beforeEach(function () {
    $this->actingAs(new User);
});

it('renders the overview tab, the stats and the relation manager tabs', function () {
    $customer = Customer::factory()->create(['name' => 'Acme Corp']);
    $team = Team::factory()->create(['name' => 'Blue Team']);
    $project = Project::factory()->for($customer)->for($team)->create(['project_code' => 'ACME-42']);

    Livewire::test(ViewProject::class, ['record' => $project->getRouteKey()])
        ->assertSuccessful()
        ->assertSee(voightTrans('models.project.view.overview_tab'))
        ->assertSee(voightTrans('models.vulnerability.plural'))
        ->assertSee(voightTrans('models.environment.plural'))
        ->assertSee(voightTrans('models.audit_run.plural'))
        ->assertSee(voightTrans('models.alert_setting.plural'))
        ->assertSee(voightTrans('models.project.fields.open_findings'))
        ->assertSee(voightTrans('models.project.fields.last_audit'))
        ->assertSee('ACME-42')
        ->assertSee('Acme Corp · Blue Team');
});

it('counts the open findings of the latest runs and colours the badge by worst severity', function () {
    $project = Project::factory()->create();
    $environment = Environment::factory()->for($project)->create();

    $oldRun = AuditRun::factory()->for($environment)->create(['started_at' => now()->subDay()]);
    $latestRun = AuditRun::factory()->for($environment)->create(['started_at' => now()]);

    AuditFinding::factory()->for($oldRun, 'auditRun')->for(Vulnerability::factory()->critical())->create();
    AuditFinding::factory()->for($latestRun, 'auditRun')->for(Vulnerability::factory()->medium())->create();
    AuditFinding::factory()->for($latestRun, 'auditRun')->for(Vulnerability::factory()->high())->create();

    expect(VulnerabilitiesRelationManager::getBadge($project, ViewProject::class))->toBe('2')
        ->and(VulnerabilitiesRelationManager::getBadgeColor($project, ViewProject::class))->toBe('danger');
});

it('has no vulnerabilities badge without findings', function () {
    $project = Project::factory()->create();

    expect(VulnerabilitiesRelationManager::getBadge($project, ViewProject::class))->toBeNull()
        ->and(VulnerabilitiesRelationManager::getBadgeColor($project, ViewProject::class))->toBeNull();
});

it('colours the environments badge by health', function () {
    $project = Project::factory()->create();
    $healthy = Environment::factory()->for($project)->create();
    DependencySync::factory()->for($healthy)->create();
    AuditRun::factory()->for($healthy)->create();

    expect(EnvironmentsRelationManager::getBadge($project, ViewProject::class))->toBe('1')
        ->and(EnvironmentsRelationManager::getBadgeColor($project, ViewProject::class))->toBe('gray');

    Environment::factory()->for($project)->create();

    expect(EnvironmentsRelationManager::getBadge($project, ViewProject::class))->toBe('2')
        ->and(EnvironmentsRelationManager::getBadgeColor($project, ViewProject::class))->toBe('warning');
});

it('shows no badge on the audit runs tab', function () {
    expect(AuditRunsRelationManager::getBadge(Project::factory()->create(), ViewProject::class))->toBeNull();
});

it('lets alert settings be created from the view page', function () {
    $project = Project::factory()->create();

    Livewire::test(AlertSettingsRelationManager::class, ['ownerRecord' => $project, 'pageClass' => ViewProject::class])
        ->assertActionVisible(TestAction::make('create')->table())
        ->callAction(TestAction::make('create')->table(), [
            'channel' => AlertChannel::Slack->value,
            'severity_threshold' => 7.0,
            'frequency' => AlertFrequency::Daily->value,
            'slack_channel' => '#alerts',
        ])
        ->assertHasNoFormErrors();

    expect($project->alertSettings()->sole())
        ->channel->toBe(AlertChannel::Slack)
        ->frequency->toBe(AlertFrequency::Daily)
        ->slack_channel->toBe('#alerts');
});

it('shows the alerts tile as on or muted', function () {
    $active = Project::factory()->create(['is_muted' => false]);
    $muted = Project::factory()->create(['is_muted' => true]);

    Livewire::test(ViewProject::class, ['record' => $active->getRouteKey()])
        ->assertSee(voightTrans('models.project.fields.alerts'))
        ->assertSee(voightTrans('models.project.fields.alerts_on'))
        ->assertDontSee('Notification enabled');

    Livewire::test(ViewProject::class, ['record' => $muted->getRouteKey()])
        ->assertSee(voightTrans('models.project.fields.is_muted'));
});
