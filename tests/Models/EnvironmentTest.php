<?php

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\QueryException;
use Statikbe\FilamentVoight\Enums\EnvironmentStage;
use Statikbe\FilamentVoight\Models\AuditRun;
use Statikbe\FilamentVoight\Models\DependencySync;
use Statikbe\FilamentVoight\Models\Environment;
use Statikbe\FilamentVoight\Models\EnvironmentPackage;
use Statikbe\FilamentVoight\Models\Project;

it('can be created via factory', function () {
    $environment = Environment::factory()->create();

    expect($environment)->toBeInstanceOf(Environment::class)
        ->and($environment->name)->toBeString();
});

it('belongs to a project', function () {
    $environment = Environment::factory()->create();

    expect($environment->project)->toBeInstanceOf(Project::class);
});

it('enforces unique constraint on project_id and name', function () {
    $project = Project::factory()->create();
    Environment::factory()->for($project)->create(['name' => 'production']);

    Environment::factory()->for($project)->create(['name' => 'production']);
})->throws(QueryException::class);

it('has many environment packages', function () {
    $environment = Environment::factory()->create();
    EnvironmentPackage::factory()->for($environment)->create();

    expect($environment->environmentPackages)->toHaveCount(1);
});

it('has many dependency syncs', function () {
    $environment = Environment::factory()->create();
    DependencySync::factory()->for($environment)->create();

    expect($environment->dependencySyncs)->toHaveCount(1);
});

it('has many audit runs', function () {
    $environment = Environment::factory()->create();
    AuditRun::factory()->for($environment)->create();

    expect($environment->auditRuns)->toHaveCount(1);
});

it('casts scanned_at to datetime', function () {
    $environment = Environment::factory()->scanned()->create();

    expect($environment->scanned_at)->toBeInstanceOf(Carbon\Carbon::class);
});

it('scopes environments by stage like EnvironmentStage::fromName', function (string $name, EnvironmentStage $stage) {
    $environment = Environment::factory()->create(['name' => $name]);

    foreach (EnvironmentStage::cases() as $candidate) {
        expect(staged($candidate)->whereKey($environment->id)->exists())
            ->toBe($candidate === $stage, "{$name} vs {$candidate->value}");
    }
})->with([
    ['Production', EnvironmentStage::Production],
    ['  live2 ', EnvironmentStage::Production],
    ['uat', EnvironmentStage::Acceptance],
    ['testing', EnvironmentStage::Staging],
    ['feat-search', EnvironmentStage::Feature],
    ['development', EnvironmentStage::Local],
    ['demo', EnvironmentStage::Other],
]);

it('gives the first stage in config order priority when prefixes overlap', function () {
    config()->set('filament-voight.environments.stages', [
        'staging' => ['pre'],
        'production' => ['pre', 'prod'],
    ]);
    $environment = Environment::factory()->create(['name' => 'preprod']);

    expect(EnvironmentStage::fromName('preprod'))->toBe(EnvironmentStage::Staging)
        ->and(staged(EnvironmentStage::Staging)->whereKey($environment->id)->exists())->toBeTrue()
        ->and(staged(EnvironmentStage::Production)->whereKey($environment->id)->exists())->toBeFalse();
});

it('matches several stages and treats like wildcards in prefixes literally', function () {
    config()->set('filament-voight.environments.stages', ['production' => ['p_od']]);
    $literal = Environment::factory()->create(['name' => 'p_od1']);
    $wildcard = Environment::factory()->create(['name' => 'prod1']);

    expect(staged(EnvironmentStage::Production)->pluck('id')->all())->toBe([$literal->id])
        ->and(staged(EnvironmentStage::Production, EnvironmentStage::Other)->count())->toBe(2);
});

function staged(EnvironmentStage ...$stages): Builder
{
    $query = Environment::query();
    Environment::constrainToStages($query, ...$stages);

    return $query;
}
