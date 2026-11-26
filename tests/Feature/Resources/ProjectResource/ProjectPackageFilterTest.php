<?php

use Illuminate\Foundation\Auth\User;
use Livewire\Features\SupportTesting\Testable;
use Livewire\Livewire;
use Statikbe\FilamentVoight\Enums\PackageType;
use Statikbe\FilamentVoight\Enums\Severity;
use Statikbe\FilamentVoight\Models\AuditFinding;
use Statikbe\FilamentVoight\Models\AuditRun;
use Statikbe\FilamentVoight\Models\Environment;
use Statikbe\FilamentVoight\Models\EnvironmentPackage;
use Statikbe\FilamentVoight\Models\Package;
use Statikbe\FilamentVoight\Models\Project;
use Statikbe\FilamentVoight\Models\Vulnerability;
use Statikbe\FilamentVoight\Resources\ProjectResource\Pages\ListProjects;

beforeEach(function () {
    $this->actingAs(new User);

    $this->package = Package::factory()->create(['name' => 'acme/widgets', 'type' => PackageType::Composer]);
    $this->old = projectWithPackage($this->package, '1.2.0');
    $this->new = projectWithPackage($this->package, '2.1.0');
    $this->without = Project::factory()->create();
});

function projectWithPackage(Package $package, string $version): Project
{
    $project = Project::factory()->create();
    EnvironmentPackage::factory()->for(Environment::factory()->for($project)->create())->for($package)->create(['version' => $version]);

    return $project;
}

function filterPackage(string $operator, array $settings): Testable
{
    return Livewire::test(ListProjects::class)
        ->set('tableDeferredFilters.queryBuilder.rules', queryBuilderRule('installed_package', $operator, $settings))
        ->call('applyTableFilters');
}

it('filters projects on an installed package alone', function () {
    filterPackage('installed', ['package_id' => [$this->package->id]])
        ->assertCanSeeTableRecords([$this->old, $this->new])
        ->assertCanNotSeeTableRecords([$this->without]);
});

it('narrows the installed package to a version range', function () {
    filterPackage('installed', ['package_id' => [$this->package->id], 'min_operator' => '>=', 'min_version' => '2.0.0'])
        ->assertCanSeeTableRecords([$this->new])
        ->assertCanNotSeeTableRecords([$this->old, $this->without]);

    filterPackage('installed', ['package_id' => [$this->package->id], 'max_operator' => '<', 'max_version' => '2.0.0'])
        ->assertCanSeeTableRecords([$this->old])
        ->assertCanNotSeeTableRecords([$this->new, $this->without]);
});

it('treats versions outside every range as not in range', function () {
    $branch = projectWithPackage($this->package, 'dev-main');

    filterPackage('installed.inverse', ['package_id' => [$this->package->id], 'min_operator' => '>=', 'min_version' => '1.0'])
        ->assertCanSeeTableRecords([$branch, $this->without])
        ->assertCanNotSeeTableRecords([$this->old, $this->new]);
});

it('inverts the package filter', function () {
    filterPackage('installed.inverse', ['package_id' => [$this->package->id]])
        ->assertCanSeeTableRecords([$this->without])
        ->assertCanNotSeeTableRecords([$this->old, $this->new]);

    filterPackage('installed.inverse', ['package_id' => [$this->package->id], 'min_operator' => '>=', 'min_version' => '2.0.0'])
        ->assertCanSeeTableRecords([$this->old, $this->without])
        ->assertCanNotSeeTableRecords([$this->new]);
});

it('matches projects with any of several selected packages', function () {
    $other = Package::factory()->create(['name' => 'acme/gadgets', 'type' => PackageType::Composer]);
    $gadgets = projectWithPackage($other, '1.0.0');

    filterPackage('installed', ['package_id' => [$this->package->id, $other->id]])
        ->assertCanSeeTableRecords([$this->old, $this->new, $gadgets])
        ->assertCanNotSeeTableRecords([$this->without]);

    filterPackage('installed', ['package_id' => [$this->package->id, $other->id], 'min_operator' => '>=', 'min_version' => '2.0.0'])
        ->assertCanSeeTableRecords([$this->new])
        ->assertCanNotSeeTableRecords([$this->old, $gadgets, $this->without]);

    filterPackage('installed.inverse', ['package_id' => [$this->package->id, $other->id]])
        ->assertCanSeeTableRecords([$this->without])
        ->assertCanNotSeeTableRecords([$this->old, $this->new, $gadgets]);
});

function currentFinding(Project $project, Package $package, string $version, Severity $severity, bool $current = true): void
{
    $environment = $project->environments()->first();
    $run = AuditRun::factory()->for($environment)->create(['started_at' => $current ? now() : now()->subDay()]);

    if (! $current) {
        AuditRun::factory()->for($environment)->create(['started_at' => now()]);
    }

    AuditFinding::factory()->for($run)->for($package)->for(Vulnerability::factory()->state(['vulnerability_score' => $severity->toRepresentativeScore()]))
        ->create(['installed_version' => $version]);
}

it('narrows the package filter to current findings of the selected severities', function () {
    currentFinding($this->old, $this->package, '1.2.0', Severity::Critical);
    currentFinding($this->new, $this->package, '2.1.0', Severity::Low);

    filterPackage('installed', ['package_id' => [$this->package->id], 'severities' => [Severity::Critical->value, Severity::High->value]])
        ->assertCanSeeTableRecords([$this->old])
        ->assertCanNotSeeTableRecords([$this->new, $this->without]);

    filterPackage('installed', ['package_id' => [$this->package->id], 'severities' => [Severity::Medium->value]])
        ->assertCanNotSeeTableRecords([$this->old, $this->new, $this->without]);

    filterPackage('installed', ['package_id' => [$this->package->id], 'severities' => [Severity::Critical->value], 'min_operator' => '>=', 'min_version' => '2.0.0'])
        ->assertCanNotSeeTableRecords([$this->old, $this->new, $this->without]);

    filterPackage('installed.inverse', ['package_id' => [$this->package->id], 'severities' => [Severity::Critical->value]])
        ->assertCanSeeTableRecords([$this->new, $this->without])
        ->assertCanNotSeeTableRecords([$this->old]);
});

it('ignores findings of superseded audit runs and of other packages', function () {
    $other = Package::factory()->create(['name' => 'acme/gadgets', 'type' => PackageType::Composer]);
    currentFinding($this->old, $this->package, '1.2.0', Severity::Critical, current: false);
    currentFinding($this->new, $other, '1.0.0', Severity::Critical);

    filterPackage('installed', ['package_id' => [$this->package->id], 'severities' => [Severity::Critical->value]])
        ->assertCanNotSeeTableRecords([$this->old, $this->new, $this->without]);
});
