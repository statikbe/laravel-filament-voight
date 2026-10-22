<?php

use Illuminate\Foundation\Auth\User;
use Livewire\Livewire;
use Statikbe\FilamentVoight\Models\Environment;
use Statikbe\FilamentVoight\Models\EnvironmentPackage;
use Statikbe\FilamentVoight\Models\Package;
use Statikbe\FilamentVoight\Models\Project;
use Statikbe\FilamentVoight\Models\Team;
use Statikbe\FilamentVoight\Resources\ProjectResource\Pages\ListProjects;

beforeEach(function () {
    $this->actingAs(new User);
});

function projectUsingPackage(Package $package, string $version, string $environmentName = 'production'): Project
{
    $project = Project::factory()->create();

    EnvironmentPackage::factory()
        ->for(Environment::factory()->for($project)->create(['name' => $environmentName]))
        ->for($package)
        ->create(['version' => $version]);

    return $project;
}

/**
 * @param  array<string, mixed>  $settings
 * @return array{rules: array<string, array<string, mixed>>}
 */
function queryBuilderRule(string $type, string $operator, array $settings = []): array
{
    return [
        'rules' => [
            'rule' => [
                'type' => $type,
                'data' => [
                    'operator' => $operator,
                    'settings' => $settings,
                ],
            ],
        ],
    ];
}

it('lists only projects with a package version inside the range', function () {
    $laravel = Package::factory()->composer()->create(['name' => 'laravel/framework']);

    $vulnerable = projectUsingPackage($laravel, 'v10.48.28');
    $patched = projectUsingPackage($laravel, 'v10.48.29');
    $unrelated = Project::factory()->create();

    Livewire::test(ListProjects::class)
        ->filterTable('queryBuilder', queryBuilderRule('package_version', 'satisfies', [
            'package_id' => $laravel->getKey(),
            'range' => '>=10.0.0,<10.48.29',
        ]))
        ->assertCanSeeTableRecords([$vulnerable])
        ->assertCanNotSeeTableRecords([$patched, $unrelated]);
});

it('matches when any environment of the project is inside the range', function () {
    $axios = Package::factory()->npm()->create(['name' => 'axios']);

    $project = projectUsingPackage($axios, '1.7.0', 'production');
    EnvironmentPackage::factory()
        ->for(Environment::factory()->for($project)->create(['name' => 'staging']))
        ->for($axios)
        ->create(['version' => '1.6.3']);

    Livewire::test(ListProjects::class)
        ->filterTable('queryBuilder', queryBuilderRule('package_version', 'satisfies', [
            'package_id' => $axios->getKey(),
            'range' => '<1.6.4',
        ]))
        ->assertCanSeeTableRecords([$project]);
});

it('only looks at the selected package', function () {
    $axios = Package::factory()->npm()->create(['name' => 'axios']);
    $lodash = Package::factory()->npm()->create(['name' => 'lodash']);

    $usesLodash = projectUsingPackage($lodash, '1.0.0');

    Livewire::test(ListProjects::class)
        ->filterTable('queryBuilder', queryBuilderRule('package_version', 'satisfies', [
            'package_id' => $axios->getKey(),
            'range' => '<2.0.0',
        ]))
        ->assertCanNotSeeTableRecords([$usesLodash]);
});

it('lists projects outside the range with the inverse operator', function () {
    $laravel = Package::factory()->composer()->create(['name' => 'laravel/framework']);

    $vulnerable = projectUsingPackage($laravel, '10.48.28');
    $patched = projectUsingPackage($laravel, '10.48.29');

    Livewire::test(ListProjects::class)
        ->filterTable('queryBuilder', queryBuilderRule('package_version', 'satisfies.inverse', [
            'package_id' => $laravel->getKey(),
            'range' => '<10.48.29',
        ]))
        ->assertCanSeeTableRecords([$patched])
        ->assertCanNotSeeTableRecords([$vulnerable]);
});

it('ignores a rule with an invalid range', function () {
    $laravel = Package::factory()->composer()->create(['name' => 'laravel/framework']);

    $project = projectUsingPackage($laravel, '10.48.28');
    $other = Project::factory()->create();

    Livewire::test(ListProjects::class)
        ->filterTable('queryBuilder', queryBuilderRule('package_version', 'satisfies', [
            'package_id' => $laravel->getKey(),
            'range' => '>>> nope',
        ]))
        ->assertCanSeeTableRecords([$project, $other]);
});

it('filters on other project attributes and relationships', function () {
    $rhino = Team::factory()->create(['name' => 'Rhino']);
    $ofRhino = Project::factory()->for($rhino)->create(['name' => 'Rhino site']);
    $other = Project::factory()->create(['name' => 'Other site']);

    Livewire::test(ListProjects::class)
        ->filterTable('queryBuilder', queryBuilderRule('name', 'contains', ['text' => 'Rhino']))
        ->assertCanSeeTableRecords([$ofRhino])
        ->assertCanNotSeeTableRecords([$other]);

    Livewire::test(ListProjects::class)
        ->filterTable('queryBuilder', queryBuilderRule('team', 'isRelatedTo', ['value' => $rhino->getKey()]))
        ->assertCanSeeTableRecords([$ofRhino])
        ->assertCanNotSeeTableRecords([$other]);
});

it('filters on packages used by the project', function () {
    $axios = Package::factory()->npm()->create(['name' => 'axios']);

    $usesAxios = projectUsingPackage($axios, '1.0.0');
    $other = Project::factory()->create();

    Livewire::test(ListProjects::class)
        ->filterTable('queryBuilder', queryBuilderRule('package_name', 'equals', ['text' => 'axios']))
        ->assertCanSeeTableRecords([$usesAxios])
        ->assertCanNotSeeTableRecords([$other]);
});
