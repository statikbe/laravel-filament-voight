<?php

use Illuminate\Foundation\Auth\User;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;
use Statikbe\FilamentVoight\Enums\Severity;
use Statikbe\FilamentVoight\Models\AuditFinding;
use Statikbe\FilamentVoight\Models\AuditRun;
use Statikbe\FilamentVoight\Models\Customer;
use Statikbe\FilamentVoight\Models\Environment;
use Statikbe\FilamentVoight\Models\Package;
use Statikbe\FilamentVoight\Models\Project;
use Statikbe\FilamentVoight\Models\Team;
use Statikbe\FilamentVoight\Models\Vulnerability;
use Statikbe\FilamentVoight\Resources\AuditRunResource\Pages\ListAuditRuns;
use Statikbe\FilamentVoight\Resources\CustomerResource\Pages\ListCustomers;
use Statikbe\FilamentVoight\Resources\EnvironmentResource\Pages\ListEnvironments;
use Statikbe\FilamentVoight\Resources\PackageResource\Pages\ListPackages;
use Statikbe\FilamentVoight\Resources\ProjectResource\Pages\ListProjects;
use Statikbe\FilamentVoight\Resources\TeamResource\Pages\ListTeams;
use Statikbe\FilamentVoight\Resources\VulnerabilityResource\Pages\ListVulnerabilities;

beforeEach(function () {
    $this->actingAs(new User);
});

it('shows an empty state on every list', function (string $page, string $heading) {
    Livewire::test($page)
        ->assertSuccessful()
        ->assertSee($heading);
})->with([
    'customers' => [ListCustomers::class, 'No customers yet'],
    'teams' => [ListTeams::class, 'No teams yet'],
    'projects' => [ListProjects::class, 'No projects yet'],
    'packages' => [ListPackages::class, 'No packages yet'],
    'audit runs' => [ListAuditRuns::class, 'No audit runs yet'],
    'environments' => [ListEnvironments::class, 'No environments yet'],
]);

it('offers a create action in the empty state of customers, teams and projects', function (string $page, string $heading) {
    // The header action also reads "New ...", so assert the empty-state action itself.
    Livewire::test($page)
        ->assertSee($heading)
        ->assertTableEmptyStateActionsExistInOrder(['create']);
})->with([
    'customers' => [ListCustomers::class, 'No customers yet'],
    'teams' => [ListTeams::class, 'No teams yet'],
    'projects' => [ListProjects::class, 'No projects yet'],
]);

it('shows the empty state of the vulnerability list', function () {
    Livewire::test(ListVulnerabilities::class)
        ->assertSuccessful()
        ->assertSee('No vulnerabilities found');
});

it('filters customers by having projects', function () {
    $with = Customer::factory()->create();
    Project::factory()->for($with)->create();
    $without = Customer::factory()->create();

    Livewire::test(ListCustomers::class)
        ->assertCanSeeTableRecords([$with, $without])
        ->filterTable('has_projects', true)
        ->assertCanSeeTableRecords([$with])
        ->assertCanNotSeeTableRecords([$without])
        ->filterTable('has_projects', false)
        ->assertCanSeeTableRecords([$without])
        ->assertCanNotSeeTableRecords([$with]);
});

it('filters teams by having members and counts members and projects', function () {
    $with = Team::factory()->create();
    $with->users()->attach(User::query()->forceCreate(['name' => 'A', 'email' => 'a@example.com', 'password' => 'x']));
    Project::factory()->for($with)->create();
    $without = Team::factory()->create();

    Livewire::test(ListTeams::class)
        ->assertTableColumnStateSet('users_count', 1, $with)
        ->assertTableColumnStateSet('projects_count', 1, $with)
        ->filterTable('has_members', true)
        ->assertCanSeeTableRecords([$with])
        ->assertCanNotSeeTableRecords([$without]);
});

it('shows the worst open severity and open findings count per project', function () {
    $project = Project::factory()->create();
    $environment = Environment::factory()->for($project)->create();
    $run = AuditRun::factory()->for($environment)->create(['started_at' => now()]);
    $old = AuditRun::factory()->for($environment)->create(['started_at' => now()->subDay()]);

    AuditFinding::factory()->for($run, 'auditRun')->for(Vulnerability::factory()->medium())->create();
    AuditFinding::factory()->for($run, 'auditRun')->for(Vulnerability::factory()->critical())->create();
    AuditFinding::factory()->for($old, 'auditRun')->for(Vulnerability::factory()->critical())->create();
    $clean = Project::factory()->create();

    Livewire::test(ListProjects::class)
        ->assertTableColumnStateSet('open_severity', Severity::Critical, $project)
        ->assertTableColumnStateSet('open_findings_count', 2, $project)
        ->assertTableColumnStateSet('open_severity', null, $clean)
        ->assertTableColumnStateSet('open_findings_count', 0, $clean);
});

it('keeps the project list within a bounded query budget', function () {
    foreach (range(1, 6) as $ignored) {
        $environment = Environment::factory()->for(Project::factory()->for(Team::factory()))->create();
        AuditFinding::factory()->for(AuditRun::factory()->for($environment), 'auditRun')->create();
    }

    $queries = 0;
    DB::listen(function () use (&$queries): void {
        $queries++;
    });

    Livewire::test(ListProjects::class)->assertSuccessful();

    expect($queries)->toBeLessThanOrEqual(10);
});

it('counts open findings per package', function () {
    $package = Package::factory()->create();
    $run = AuditRun::factory()->create(['started_at' => now()]);
    AuditFinding::factory()->for($run, 'auditRun')->for($package)->count(2)->create();
    $other = Package::factory()->create();

    Livewire::test(ListPackages::class)
        ->assertTableColumnStateSet('open_findings_count', 2, $package)
        ->assertTableColumnStateSet('open_findings_count', 0, $other);
});

it('lists only open vulnerabilities by default and filters by severity', function () {
    $open = Vulnerability::factory()->critical()->create();
    $closed = Vulnerability::factory()->medium()->create();
    AuditFinding::factory()->for(AuditRun::factory(), 'auditRun')->for($open)->create();

    Livewire::test(ListVulnerabilities::class)
        ->assertCanSeeTableRecords([$open])
        ->assertCanNotSeeTableRecords([$closed])
        ->filterTable('open', null)
        ->assertCanSeeTableRecords([$open, $closed])
        ->filterTable('severity', [Severity::Medium->value])
        ->assertCanSeeTableRecords([$closed])
        ->assertCanNotSeeTableRecords([$open])
        ->removeTableFilter('severity')
        ->filterTable('open', false)
        ->assertCanSeeTableRecords([$closed])
        ->assertCanNotSeeTableRecords([$open]);
});

it('lists environments with their project', function () {
    $environment = Environment::factory()->create();

    Livewire::test(ListEnvironments::class)
        ->removeTableFilter('server_reported')
        ->assertCanSeeTableRecords([$environment]);
});
