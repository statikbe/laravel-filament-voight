<?php

use Filament\Models\Contracts\FilamentUser;
use Filament\Panel;
use Illuminate\Foundation\Auth\User;
use Livewire\Features\SupportTesting\Testable;
use Livewire\Livewire;
use Statikbe\FilamentVoight\Enums\Severity;
use Statikbe\FilamentVoight\Models\AuditFinding;
use Statikbe\FilamentVoight\Models\AuditRun;
use Statikbe\FilamentVoight\Models\Environment;
use Statikbe\FilamentVoight\Models\Package;
use Statikbe\FilamentVoight\Models\Project;
use Statikbe\FilamentVoight\Models\Vulnerability;
use Statikbe\FilamentVoight\Resources\ProjectResource;
use Statikbe\FilamentVoight\Resources\ProjectResource\Pages\ViewProject;
use Statikbe\FilamentVoight\Resources\ProjectResource\RelationManagers\AlertSettingsRelationManager;
use Statikbe\FilamentVoight\Resources\ProjectResource\RelationManagers\AuditRunsRelationManager;
use Statikbe\FilamentVoight\Resources\ProjectResource\RelationManagers\EnvironmentsRelationManager;
use Statikbe\FilamentVoight\Resources\ProjectResource\RelationManagers\VulnerabilitiesRelationManager;
use Statikbe\FilamentVoight\Resources\ProjectResource\Schemas\ProjectInfoListSchema;

beforeEach(function () {
    $this->actingAs(new User);
    $this->project = Project::factory()->create(['repo_url' => 'https://git.example.com/acme/secret-repo']);
});

function environmentWithRun(Project $project, string $name): AuditRun
{
    return AuditRun::factory()->for(Environment::factory()->for($project)->create(['name' => $name]))->create();
}

function finding(AuditRun $run, Package $package, Vulnerability $vulnerability): void
{
    AuditFinding::factory()->for($run)->for($package)->for($vulnerability)->create();
}

function viewProject(Project $project): Testable
{
    return Livewire::test(ViewProject::class, ['record' => $project->getRouteKey()]);
}

it('counts only composer vulnerabilities by default and npm after the toggle', function () {
    $run = environmentWithRun($this->project, 'production');
    finding($run, Package::factory()->composer()->create(), Vulnerability::factory()->critical()->create());
    finding($run, Package::factory()->npm()->create(), Vulnerability::factory()->critical()->create());
    finding($run, Package::factory()->npm()->create(), Vulnerability::factory()->low()->create());
    $environment = $run->environment;

    viewProject($this->project)
        ->assertCanSeeTableRecords([$environment])
        ->assertTableColumnStateSet(Severity::Critical->value, 1, $environment)
        ->assertTableColumnStateSet(Severity::Low->value, 0, $environment)
        ->filterTable('include_npm', true)
        ->assertTableColumnStateSet(Severity::Critical->value, 2, $environment)
        ->assertTableColumnStateSet(Severity::Low->value, 1, $environment);
});

it('maps the counts to a status', function (string $factoryState, string $status) {
    $run = environmentWithRun($this->project, 'production');

    if ($factoryState !== 'none') {
        finding($run, Package::factory()->composer()->create(), Vulnerability::factory()->{$factoryState}()->create());
    }

    viewProject($this->project)->assertTableColumnStateSet('status', $status, $run->environment);
})->with([
    'critical' => ['critical', 'vulnerable'],
    'high' => ['high', 'needs_update'],
    'medium only' => ['medium', 'healthy'],
    'none' => ['none', 'healthy'],
]);

it('counts a vulnerability found in several packages once', function () {
    $run = environmentWithRun($this->project, 'production');
    $vulnerability = Vulnerability::factory()->critical()->create();
    finding($run, Package::factory()->composer()->create(), $vulnerability);
    finding($run, Package::factory()->composer()->create(), $vulnerability);

    viewProject($this->project)->assertTableColumnStateSet(Severity::Critical->value, 1, $run->environment);
});

it('ignores findings of older audit runs', function () {
    $old = AuditRun::factory()->for(Environment::factory()->for($this->project)->create())->create(['started_at' => now()->subDay()]);
    finding($old, Package::factory()->composer()->create(), Vulnerability::factory()->critical()->create());
    AuditRun::factory()->for($old->environment)->create(['started_at' => now()]);

    viewProject($this->project)->assertTableColumnStateSet(Severity::Critical->value, 0, $old->environment);
});

it('does not show the repository url on the view page', function () {
    Livewire::test(ViewProject::class, ['record' => $this->project->getRouteKey()])
        ->assertSuccessful()
        ->assertDontSee('secret-repo');
});

it('hints at hidden npm findings only while npm is off', function () {
    $run = environmentWithRun($this->project, 'production');
    $critical = Vulnerability::factory()->critical()->create();
    finding($run, Package::factory()->npm()->create(), $critical);
    finding($run, Package::factory()->npm()->create(), $critical);
    finding($run, Package::factory()->npm()->create(), Vulnerability::factory()->high()->create());

    expect(ProjectInfoListSchema::npmHint($this->project, false))->toBe('npm has 1 critical, 1 high findings. Turn on Include npm to see them.')
        ->and(ProjectInfoListSchema::npmHint($this->project, true))->toBeNull();

    Livewire::test(ViewProject::class, ['record' => $this->project->getRouteKey()])
        ->assertSee('Turn on Include npm to see them.')
        ->filterTable('include_npm', true)
        ->assertDontSee('Turn on Include npm to see them.');
});

it('shows no npm hint without npm findings', function () {
    finding(environmentWithRun($this->project, 'production'), Package::factory()->composer()->create(), Vulnerability::factory()->critical()->create());

    expect(ProjectInfoListSchema::npmHint($this->project, false))->toBeNull();

    Livewire::test(ViewProject::class, ['record' => $this->project->getRouteKey()])
        ->assertDontSee('Turn on Include npm to see them.');
});

it('labels the alerts status and the empty description', function (bool $muted, string $label) {
    $project = Project::factory()->create(['is_muted' => $muted, 'description' => null]);

    Livewire::test(ViewProject::class, ['record' => $project->getRouteKey()])
        ->assertSee('Alerts')
        ->assertSee($label)
        ->assertDontSee('Notification enabled')
        ->assertSee('No description');
})->with([
    'active' => [false, 'Active'],
    'muted' => [true, 'Muted'],
]);

it('renders the full view page with the environments table', function () {
    environmentWithRun($this->project, 'production');

    viewProject($this->project)->assertSuccessful()->assertSee('production');

    // Outside the local environment Filament only lets users through that implement FilamentUser.
    $user = new class extends User implements FilamentUser
    {
        public function canAccessPanel(Panel $panel): bool
        {
            return true;
        }
    };
    $user->name = 'Test User';
    $this->actingAs($user);

    $this->get(ProjectResource::getUrl('view', ['record' => $this->project]))
        ->assertSuccessful()
        ->assertSee('production');
});

it('shows the overview as the default tab next to the relation manager tabs', function () {
    environmentWithRun($this->project, 'production');
    $page = viewProject($this->project)->assertSuccessful();

    expect($page->instance()->hasCombinedRelationManagerTabsWithContent())->toBeTrue()
        ->and($page->instance()->getContentTabLabel())->toBe('Info');

    $page->assertSeeInOrder([
        $page->instance()->getContentTabLabel(),
        EnvironmentsRelationManager::getTitle($this->project, ViewProject::class),
        AlertSettingsRelationManager::getTitle($this->project, ViewProject::class),
        VulnerabilitiesRelationManager::getTitle($this->project, ViewProject::class),
        AuditRunsRelationManager::getTitle($this->project, ViewProject::class),
    ])->assertSee('production');
});
