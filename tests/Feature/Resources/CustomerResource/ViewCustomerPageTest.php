<?php

use Illuminate\Foundation\Auth\User;
use Livewire\Livewire;
use Statikbe\FilamentVoight\Models\AuditFinding;
use Statikbe\FilamentVoight\Models\AuditRun;
use Statikbe\FilamentVoight\Models\Customer;
use Statikbe\FilamentVoight\Models\Environment;
use Statikbe\FilamentVoight\Models\Project;
use Statikbe\FilamentVoight\Models\Vulnerability;
use Statikbe\FilamentVoight\Resources\CustomerResource\Pages\ViewCustomer;
use Statikbe\FilamentVoight\Resources\CustomerResource\RelationManagers\ProjectRelationManager;

beforeEach(function () {
    $this->actingAs(new User);
});

it('renders the customer stats', function () {
    $customer = Customer::factory()->create(['name' => 'Acme Corp']);
    $projects = Project::factory()->count(2)->for($customer)->create();
    Environment::factory()->count(3)->for($projects[0])->create();
    Environment::factory()->for($projects[1])->create();
    // Another customer's data must not leak in.
    AuditFinding::factory()->for(AuditRun::factory(), 'auditRun')->for(Vulnerability::factory()->critical())->create();

    $page = Livewire::test(ViewCustomer::class, ['record' => $customer->getRouteKey()])
        ->assertSuccessful()
        ->assertSee('Acme Corp')
        ->assertSee(voightTrans('models.project.plural'))
        ->assertSee(voightTrans('models.environment.plural'))
        ->assertSee(voightTrans('models.customer.fields.open_findings'));

    expect(entryState($page, 'stat_projects'))->toBe(2)
        ->and(entryState($page, 'stat_environments'))->toBe(4)
        ->and(entryState($page, 'stat_open_findings'))->toBe(voightTrans('fields.none'));
});

it('summarises the open findings of the latest runs across the projects of the customer', function () {
    $customer = Customer::factory()->create();
    $environment = Environment::factory()->for(Project::factory()->for($customer))->create();
    $oldRun = AuditRun::factory()->for($environment)->create(['started_at' => now()->subDay()]);
    $latestRun = AuditRun::factory()->for($environment)->create(['started_at' => now()]);

    AuditFinding::factory()->for($oldRun, 'auditRun')->for(Vulnerability::factory()->critical())->create();
    AuditFinding::factory()->for($latestRun, 'auditRun')->for(Vulnerability::factory()->medium())->create();
    AuditFinding::factory()->for($latestRun, 'auditRun')->for(Vulnerability::factory()->high())->create();

    $summary = $customer->openFindingsSummary();

    expect($summary['count'])->toBe(2)
        ->and($summary['severity']->color())->toBe('danger');
});

it('hides the redundant customer column and filter in the projects tab', function () {
    $customer = Customer::factory()->create();
    Project::factory()->for($customer)->create();

    Livewire::test(ProjectRelationManager::class, ['ownerRecord' => $customer, 'pageClass' => ViewCustomer::class])
        ->assertTableColumnHidden('customer.name')
        ->assertTableColumnVisible('project_code')
        ->assertTableFilterHidden('customer_id');
});
