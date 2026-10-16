<?php

use Illuminate\Foundation\Auth\User;
use Livewire\Livewire;
use Statikbe\FilamentVoight\Models\AuditRun;
use Statikbe\FilamentVoight\Models\Customer;
use Statikbe\FilamentVoight\Models\Environment;
use Statikbe\FilamentVoight\Models\Package;
use Statikbe\FilamentVoight\Models\Project;
use Statikbe\FilamentVoight\Models\Team;
use Statikbe\FilamentVoight\Models\Vulnerability;
use Statikbe\FilamentVoight\Resources\AuditRunResource;
use Statikbe\FilamentVoight\Resources\AuditRunResource\Pages\ListAuditRuns;
use Statikbe\FilamentVoight\Resources\CustomerResource;
use Statikbe\FilamentVoight\Resources\CustomerResource\Pages\ListCustomers;
use Statikbe\FilamentVoight\Resources\EnvironmentResource;
use Statikbe\FilamentVoight\Resources\EnvironmentResource\Pages\ListEnvironments;
use Statikbe\FilamentVoight\Resources\PackageResource;
use Statikbe\FilamentVoight\Resources\PackageResource\Pages\ListPackages;
use Statikbe\FilamentVoight\Resources\ProjectResource;
use Statikbe\FilamentVoight\Resources\ProjectResource\Pages\ListProjects;
use Statikbe\FilamentVoight\Resources\TeamResource;
use Statikbe\FilamentVoight\Resources\TeamResource\Pages\ListTeams;
use Statikbe\FilamentVoight\Resources\VulnerabilityResource;
use Statikbe\FilamentVoight\Resources\VulnerabilityResource\Pages\ListVulnerabilities;

beforeEach(function () {
    $this->actingAs(new User);
});

it('opens the view page when a list row is clicked', function (string $page, string $resource, Closure $makeRecord) {
    $record = $makeRecord();

    $url = Livewire::test($page)->instance()->getTable()->getRecordUrl($record);

    $parameters = $record instanceof Environment
        ? ['project' => $record->project, 'record' => $record]
        : ['record' => $record];

    expect($url)->toBe($resource::getUrl('view', $parameters));
})->with([
    'customers' => [ListCustomers::class, CustomerResource::class, fn () => Customer::factory()->create()],
    'teams' => [ListTeams::class, TeamResource::class, fn () => Team::factory()->create()],
    'projects' => [ListProjects::class, ProjectResource::class, fn () => Project::factory()->create()],
    'packages' => [ListPackages::class, PackageResource::class, fn () => Package::factory()->create()],
    'vulnerabilities' => [ListVulnerabilities::class, VulnerabilityResource::class, fn () => Vulnerability::factory()->create()],
    'audit runs' => [ListAuditRuns::class, AuditRunResource::class, fn () => AuditRun::factory()->create()],
    'environments' => [ListEnvironments::class, EnvironmentResource::class, fn () => Environment::factory()->create()],
]);
