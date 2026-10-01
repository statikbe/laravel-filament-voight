<?php

use Statikbe\FilamentVoight\Models\AuditRun;
use Statikbe\FilamentVoight\Models\Customer;
use Statikbe\FilamentVoight\Models\Environment;
use Statikbe\FilamentVoight\Models\Package;
use Statikbe\FilamentVoight\Models\Project;
use Statikbe\FilamentVoight\Models\Team;
use Statikbe\FilamentVoight\Models\Vulnerability;
use Statikbe\FilamentVoight\Resources\AuditRunResource;
use Statikbe\FilamentVoight\Resources\CustomerResource;
use Statikbe\FilamentVoight\Resources\PackageResource;
use Statikbe\FilamentVoight\Resources\ProjectResource;
use Statikbe\FilamentVoight\Resources\TeamResource;
use Statikbe\FilamentVoight\Resources\VulnerabilityResource;

it('exposes searchable attributes', function (string $resource, array $attributes) {
    expect($resource::getGloballySearchableAttributes())->toBe($attributes);
})->with([
    'project' => [ProjectResource::class, ['name', 'project_code', 'customer.name', 'team.name']],
    'customer' => [CustomerResource::class, ['name', 'slug']],
    'team' => [TeamResource::class, ['name']],
    'package' => [PackageResource::class, ['name']],
    'vulnerability' => [VulnerabilityResource::class, ['source_id', 'summary']],
]);

it('searches audit runs by project name, project code and environment name', function () {
    expect(AuditRunResource::getGloballySearchableAttributes())->toBe(['environment.project.name', 'environment.project.project_code', 'environment.name']);
});

it('titles audit run results with project and environment and eager loads them', function () {
    $project = Project::factory()->create(['name' => 'Shop']);
    $environment = Environment::factory()->for($project)->create(['name' => 'production']);
    AuditRun::factory()->for($environment)->create(['started_at' => now()]);

    $record = AuditRunResource::getGlobalSearchEloquentQuery()->first();

    expect($record->relationLoaded('environment'))->toBeTrue()
        ->and($record->environment->relationLoaded('project'))->toBeTrue()
        ->and(AuditRunResource::getGlobalSearchResultTitle($record))->toBe('Shop — production')
        ->and(AuditRunResource::getGlobalSearchResultDetails($record))
        ->toHaveKey(voightTrans('models.audit_run.fields.status'), $record->status->label());
});

it('only returns the latest audit run per environment', function () {
    $environment = Environment::factory()->create();
    AuditRun::factory()->for($environment)->create(['started_at' => now()->subDay()]);
    $latest = AuditRun::factory()->for($environment)->create(['started_at' => now()]);

    expect(AuditRunResource::getGlobalSearchEloquentQuery()->pluck('id')->all())->toBe([$latest->id]);
});

it('shows customer and team on project results', function () {
    $project = Project::factory()
        ->for(Customer::factory()->create(['name' => 'Acme']), 'customer')
        ->for(Team::factory()->create(['name' => 'Red']), 'team')
        ->create();

    expect(ProjectResource::getGlobalSearchResultDetails($project))->toBe([
        voightTrans('models.project.fields.customer') => 'Acme',
        voightTrans('models.project.fields.team') => 'Red',
    ]);
});

it('falls back to a dash for a project without customer or team', function () {
    $project = Project::factory()->create(['customer_id' => null, 'team_id' => null]);

    expect(ProjectResource::getGlobalSearchResultDetails($project))->each->toBe('-');
});

it('eager loads relations for project results', function () {
    Project::factory()->create();

    expect(ProjectResource::getGlobalSearchEloquentQuery()->first()->relationLoaded('customer'))->toBeTrue();
});

it('shows type and latest version on package results', function () {
    $package = Package::factory()->create(['latest_version' => '1.2.3']);

    expect(PackageResource::getGlobalSearchResultDetails($package))
        ->toHaveKey(voightTrans('models.package.fields.latest_version'), '1.2.3');
});

it('shows severity and source on vulnerability results', function () {
    $vulnerability = Vulnerability::factory()->create(['vulnerability_score' => 9.8]);

    expect(VulnerabilityResource::getGlobalSearchResultDetails($vulnerability))
        ->toHaveKey(voightTrans('models.vulnerability.fields.severity'), 'Critical');
});
