<?php

use Filament\QueryBuilder\Forms\Components\RuleBuilder;
use Filament\Tables\Enums\FiltersLayout;
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
 * @return array{type: string, data: array{operator: string, settings: array<string, mixed>}}
 */
function rule(string $type, string $operator, array $settings = []): array
{
    return [
        'type' => $type,
        'data' => [
            'operator' => $operator,
            'settings' => $settings,
        ],
    ];
}

/**
 * @return array{type: string, data: array{operator: string, settings: array<string, mixed>}}
 */
function versionRule(Package $package, string $operator, string $version): array
{
    return rule('package_version', $operator, ['package_id' => $package->getKey(), 'version' => $version]);
}

/**
 * @param  array<int, array<string, mixed>>  $rules
 * @return array{rules: array<string, array<string, mixed>>}
 */
function rules(array ...$rules): array
{
    return ['rules' => collect($rules)->mapWithKeys(fn (array $rule, int $index): array => ["rule{$index}" => $rule])->all()];
}

it('lists only projects with a package version below the given version', function () {
    $laravel = Package::factory()->composer()->create(['name' => 'laravel/framework']);

    $vulnerable = projectUsingPackage($laravel, 'v10.48.28');
    $patched = projectUsingPackage($laravel, 'v10.48.29');
    $unrelated = Project::factory()->create();

    Livewire::test(ListProjects::class)
        ->filterTable('queryBuilder', rules(versionRule($laravel, 'isBelow', '10.48.29')))
        ->assertCanSeeTableRecords([$vulnerable])
        ->assertCanNotSeeTableRecords([$patched, $unrelated]);
});

it('combines version lines into a range', function () {
    $laravel = Package::factory()->composer()->create(['name' => 'laravel/framework']);

    $tooOld = projectUsingPackage($laravel, '9.52.0');
    $inRange = projectUsingPackage($laravel, '10.47.0');
    $patched = projectUsingPackage($laravel, '10.48.29');

    Livewire::test(ListProjects::class)
        ->filterTable('queryBuilder', rules(
            versionRule($laravel, 'isAtLeast', '10.0.0'),
            versionRule($laravel, 'isBelow', '10.48.29'),
        ))
        ->assertCanSeeTableRecords([$inRange])
        ->assertCanNotSeeTableRecords([$tooOld, $patched]);
});

it('supports every comparison', function (string $operator, array $expected) {
    $axios = Package::factory()->npm()->create(['name' => 'axios']);

    $projects = collect(['1.5.0', '1.6.4', '1.7.0'])
        ->mapWithKeys(fn (string $version): array => [$version => projectUsingPackage($axios, $version)]);

    Livewire::test(ListProjects::class)
        ->filterTable('queryBuilder', rules(versionRule($axios, $operator, '1.6.4')))
        ->assertCanSeeTableRecords($projects->only($expected)->values())
        ->assertCanNotSeeTableRecords($projects->except($expected)->values());
})->with([
    'is' => ['is', ['1.6.4']],
    'is not' => ['isNot', ['1.5.0', '1.7.0']],
    'is below' => ['isBelow', ['1.5.0']],
    'is at most' => ['isAtMost', ['1.5.0', '1.6.4']],
    'is above' => ['isAbove', ['1.7.0']],
    'is at least' => ['isAtLeast', ['1.6.4', '1.7.0']],
]);

it('ignores the v prefix of composer versions', function () {
    $symfony = Package::factory()->composer()->create(['name' => 'symfony/http-kernel']);

    $project = projectUsingPackage($symfony, 'v6.4.1');

    Livewire::test(ListProjects::class)
        ->filterTable('queryBuilder', rules(versionRule($symfony, 'is', '6.4.1')))
        ->assertCanSeeTableRecords([$project]);
});

it('matches alternatives in an or group', function () {
    $axios = Package::factory()->npm()->create(['name' => 'axios']);

    $old = projectUsingPackage($axios, '1.5.0');
    $patched = projectUsingPackage($axios, '1.6.4');
    $new = projectUsingPackage($axios, '2.0.0');

    Livewire::test(ListProjects::class)
        ->filterTable('queryBuilder', rules([
            'type' => RuleBuilder::OR_BLOCK_NAME,
            'data' => [
                RuleBuilder::OR_BLOCK_GROUPS_REPEATER_NAME => [
                    'group0' => rules(versionRule($axios, 'isBelow', '1.6.4')),
                    'group1' => rules(versionRule($axios, 'isAtLeast', '2.0.0')),
                ],
            ],
        ]))
        ->assertCanSeeTableRecords([$old, $new])
        ->assertCanNotSeeTableRecords([$patched]);
});

it('only looks at the selected package', function () {
    $axios = Package::factory()->npm()->create(['name' => 'axios']);
    $lodash = Package::factory()->npm()->create(['name' => 'lodash']);

    $usesLodash = projectUsingPackage($lodash, '1.0.0');

    Livewire::test(ListProjects::class)
        ->filterTable('queryBuilder', rules(versionRule($axios, 'isBelow', '2.0.0')))
        ->assertCanNotSeeTableRecords([$usesLodash]);
});

it('ignores a line with an invalid version', function () {
    $laravel = Package::factory()->composer()->create(['name' => 'laravel/framework']);

    $project = projectUsingPackage($laravel, '10.48.28');
    $other = Project::factory()->create();

    Livewire::test(ListProjects::class)
        ->filterTable('queryBuilder', rules(versionRule($laravel, 'isBelow', '10.0 || 11')))
        ->assertCanSeeTableRecords([$project, $other]);
});

it('filters on other project attributes and relationships', function () {
    $rhino = Team::factory()->create(['name' => 'Rhino']);
    $ofRhino = Project::factory()->for($rhino)->create(['name' => 'Rhino site', 'is_muted' => true]);
    $other = Project::factory()->create(['name' => 'Other site', 'is_muted' => false]);

    Livewire::test(ListProjects::class)
        ->filterTable('queryBuilder', rules(rule('name', 'contains', ['text' => 'Rhino'])))
        ->assertCanSeeTableRecords([$ofRhino])
        ->assertCanNotSeeTableRecords([$other]);

    Livewire::test(ListProjects::class)
        ->filterTable('queryBuilder', rules(rule('team', 'isRelatedTo', ['value' => $rhino->getKey()])))
        ->assertCanSeeTableRecords([$ofRhino])
        ->assertCanNotSeeTableRecords([$other]);

    Livewire::test(ListProjects::class)
        ->filterTable('queryBuilder', rules(rule('is_muted', 'isTrue')))
        ->assertCanSeeTableRecords([$ofRhino])
        ->assertCanNotSeeTableRecords([$other]);
});

it('filters on packages used by the project', function () {
    $axios = Package::factory()->npm()->create(['name' => 'axios']);

    $usesAxios = projectUsingPackage($axios, '1.0.0');
    $other = Project::factory()->create();

    Livewire::test(ListProjects::class)
        ->filterTable('queryBuilder', rules(rule('package_name', 'equals', ['text' => 'axios'])))
        ->assertCanSeeTableRecords([$usesAxios])
        ->assertCanNotSeeTableRecords([$other]);
});

it('uses the query builder as the only filter', function () {
    $table = Livewire::test(ListProjects::class)->instance()->getTable();

    expect(array_keys($table->getFilters()))->toBe(['queryBuilder'])
        ->and($table->getFiltersLayout())->toBe(FiltersLayout::AboveContent);
});
