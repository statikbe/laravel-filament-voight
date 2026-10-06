<?php

use Filament\Facades\Filament;
use Illuminate\Foundation\Auth\User;
use Livewire\Livewire;
use Statikbe\FilamentVoight\Models\AuditFinding;
use Statikbe\FilamentVoight\Models\AuditRun;
use Statikbe\FilamentVoight\Models\Customer;
use Statikbe\FilamentVoight\Models\Environment;
use Statikbe\FilamentVoight\Models\EnvironmentPackage;
use Statikbe\FilamentVoight\Models\Package;
use Statikbe\FilamentVoight\Models\Project;
use Statikbe\FilamentVoight\Models\Team;
use Statikbe\FilamentVoight\Models\Vulnerability;
use Statikbe\FilamentVoight\Resources\AuditRunResource\Pages\ListAuditRuns;
use Statikbe\FilamentVoight\Resources\CustomerResource\Pages\ListCustomers;
use Statikbe\FilamentVoight\Resources\PackageResource\Pages\ListPackages;
use Statikbe\FilamentVoight\Resources\ProjectResource\Pages\CreateProject;
use Statikbe\FilamentVoight\Resources\ProjectResource\Pages\ListProjects;
use Statikbe\FilamentVoight\Resources\TeamResource\Pages\ListTeams;
use Statikbe\FilamentVoight\Resources\VulnerabilityResource\Pages\ListVulnerabilities;
use Statikbe\FilamentVoight\Widgets\ActiveFindingsWidget;
use Statikbe\FilamentVoight\Widgets\MostVulnerableProjectsWidget;
use Statikbe\FilamentVoight\Widgets\RecentAuditRunsWidget;

beforeEach(function () {
    $this->actingAs(new User);

    $this->teamA = Team::factory()->create();
    $this->teamB = Team::factory()->create();

    $this->seedTeam = function (Team $team): array {
        $project = Project::factory()->for($team)->create();
        $environment = Environment::factory()->for($project)->create();
        $run = AuditRun::factory()->for($environment)->create(['started_at' => now()->subHour()]);
        $package = Package::factory()->create();
        $vulnerability = Vulnerability::factory()->create();
        $finding = AuditFinding::factory()->for($run, 'auditRun')->for($package, 'package')->for($vulnerability, 'vulnerability')->create();
        EnvironmentPackage::factory()->for($environment)->for($package)->create();

        return compact('project', 'run', 'package', 'vulnerability', 'finding');
    };

    $this->a = ($this->seedTeam)($this->teamA);
    $this->b = ($this->seedTeam)($this->teamB);

    Filament::setCurrentPanel('tenant');
    Filament::bootCurrentPanel();
    Filament::setTenant($this->teamA);
});

it('only lists projects of the active tenant', function () {
    Livewire::test(ListProjects::class)
        ->assertCanSeeTableRecords([$this->a['project']])
        ->assertCanNotSeeTableRecords([$this->b['project']]);
});

it('only lists audit runs of the active tenant', function () {
    Livewire::test(ListAuditRuns::class)
        ->assertCanSeeTableRecords([$this->a['run']])
        ->assertCanNotSeeTableRecords([$this->b['run']]);
});

it('only lists packages and vulnerabilities used by the active tenant', function () {
    Livewire::test(ListPackages::class)
        ->assertCanSeeTableRecords([$this->a['package']])
        ->assertCanNotSeeTableRecords([$this->b['package']]);

    Livewire::test(ListVulnerabilities::class)
        ->assertCanSeeTableRecords([$this->a['vulnerability']])
        ->assertCanNotSeeTableRecords([$this->b['vulnerability']]);
});

it('only lists customers with projects of the tenant or without any project', function () {
    $orphan = Customer::factory()->create();

    Livewire::test(ListCustomers::class)
        ->assertCanSeeTableRecords([$this->a['project']->customer, $orphan])
        ->assertCanNotSeeTableRecords([$this->b['project']->customer]);
});

it('lists every team', function () {
    Livewire::test(ListTeams::class)
        ->assertCanSeeTableRecords([$this->teamA, $this->teamB]);
});

it('filters widgets to the active tenant', function () {
    Livewire::test(ActiveFindingsWidget::class)
        ->assertCanSeeTableRecords([$this->a['finding']])
        ->assertCanNotSeeTableRecords([$this->b['finding']]);

    Livewire::test(RecentAuditRunsWidget::class)
        ->assertCanSeeTableRecords([$this->a['run']])
        ->assertCanNotSeeTableRecords([$this->b['run']]);

    Livewire::test(MostVulnerableProjectsWidget::class)
        ->assertCanSeeTableRecords([$this->a['project']])
        ->assertCanNotSeeTableRecords([$this->b['project']]);
});

it('assigns the active tenant to projects created in it', function () {
    $customer = Customer::factory()->create();

    Livewire::test(CreateProject::class)
        ->fillForm([
            'project_code' => 'TEN-1',
            'name' => 'Tenant project',
            'repo_url' => 'https://example.com/repo.git',
            'customer_id' => $customer->id,
        ])
        ->call('create')
        ->assertHasNoFormErrors();

    expect(Project::query()->withoutGlobalScopes()->where('project_code', 'TEN-1')->value('team_id'))
        ->toBe($this->teamA->id);
});

it('does not scope anything on a panel without tenancy', function () {
    Filament::setTenant(null);
    Filament::setCurrentPanel('voight');

    Livewire::test(ListProjects::class)
        ->assertCanSeeTableRecords([$this->a['project'], $this->b['project']]);

    Livewire::test(ListAuditRuns::class)
        ->assertCanSeeTableRecords([$this->a['run'], $this->b['run']]);
});
