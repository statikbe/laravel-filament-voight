<?php

use Statikbe\FilamentVoight\Models\AuditRun;
use Statikbe\FilamentVoight\Models\Customer;
use Statikbe\FilamentVoight\Models\Environment;
use Statikbe\FilamentVoight\Models\EnvironmentPackage;
use Statikbe\FilamentVoight\Models\Package;
use Statikbe\FilamentVoight\Models\Project;
use Statikbe\FilamentVoight\Models\Team;
use Statikbe\FilamentVoight\Models\Vulnerability;
use Statikbe\FilamentVoight\Models\VulnerablePackageRange;
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

it('drops customer and team rows for a project without them', function () {
    $project = Project::factory()->create(['customer_id' => null, 'team_id' => null]);

    expect(ProjectResource::getGlobalSearchResultDetails($project))->toBe([]);
});

it('eager loads relations for project results', function () {
    Project::factory()->create();

    expect(ProjectResource::getGlobalSearchEloquentQuery()->first()->relationLoaded('customer'))->toBeTrue();
});

it('shows latest version on package results only when known', function () {
    $with = Package::factory()->create(['latest_version' => '1.2.3']);
    $without = Package::factory()->create(['latest_version' => null]);

    expect(PackageResource::getGlobalSearchResultDetails($with))
        ->toHaveKey(voightTrans('models.package.fields.latest_version'), '1.2.3')
        ->and(PackageResource::getGlobalSearchResultDetails($without))
        ->not->toHaveKey(voightTrans('models.package.fields.latest_version'))
        ->toHaveKey(voightTrans('models.package.fields.type'));
});

it('counts distinct projects using a package', function () {
    $package = Package::factory()->create();
    $project = Project::factory()->create();
    foreach (Environment::factory()->count(2)->for($project)->sequence(['name' => 'production'], ['name' => 'staging'])->create() as $environment) {
        EnvironmentPackage::factory()->create(['environment_id' => $environment->id, 'package_id' => $package->id]);
    }
    $other = Environment::factory()->create();
    EnvironmentPackage::factory()->create(['environment_id' => $other->id, 'package_id' => $package->id]);
    $unused = Package::factory()->create();

    $details = fn (Package $p): array => PackageResource::getGlobalSearchResultDetails(
        PackageResource::getGlobalSearchEloquentQuery()->findOrFail($p->id)
    );

    expect($details($package))->toHaveKey(voightTrans('models.project.plural'), 2)
        ->and($details($unused))->toHaveKey(voightTrans('models.project.plural'), 0);
});

it('shows id with summary, severity with score and affected packages on vulnerability results', function () {
    $vulnerability = Vulnerability::factory()->create([
        'source_id' => 'GHSA-aaaa-bbbb-cccc',
        'summary' => 'Remote code execution in parser',
        'vulnerability_score' => 7.5,
    ]);
    foreach (['a/one', 'b/two', 'c/three', 'd/four', 'e/five'] as $name) {
        VulnerablePackageRange::factory()->create([
            'vulnerability_id' => $vulnerability->id,
            'package_id' => Package::factory()->create(['name' => $name])->id,
        ]);
    }

    $record = VulnerabilityResource::getGlobalSearchEloquentQuery()->findOrFail($vulnerability->id);
    $details = VulnerabilityResource::getGlobalSearchResultDetails($record);

    expect(VulnerabilityResource::getGlobalSearchResultTitle($record))
        ->toContain('GHSA-aaaa-bbbb-cccc')->toContain('Remote code execution in parser')
        ->and($details)->not->toHaveKey(voightTrans('models.vulnerability.fields.source'))
        ->toHaveKey(voightTrans('models.vulnerability.fields.severity'), 'High (7.5)')
        ->and($details[voightTrans('models.package.plural')])->toEndWith(' +2');
});
