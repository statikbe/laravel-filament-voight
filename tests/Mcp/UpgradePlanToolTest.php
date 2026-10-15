<?php

use Statikbe\FilamentVoight\Enums\DependencyKind;
use Statikbe\FilamentVoight\Mcp\Tools\GetUpgradePlanTool;
use Statikbe\FilamentVoight\Mcp\VoightLocalServer;
use Statikbe\FilamentVoight\Models\Environment;
use Statikbe\FilamentVoight\Models\EnvironmentPackage;
use Statikbe\FilamentVoight\Models\Package;
use Statikbe\FilamentVoight\Models\Project;
use Statikbe\FilamentVoight\Models\Vulnerability;
use Statikbe\FilamentVoight\Services\DependencyGraphService;

beforeEach(function () {
    $this->project = Project::factory()->create(['project_code' => 'APP']);
    $this->environment = Environment::factory()->for($this->project)->create(['name' => 'production']);
});

function planNode(Environment $environment, string $name, string $version, bool $direct = false, bool $dev = false): EnvironmentPackage
{
    $package = Package::query()->firstOrCreate(['name' => $name, 'type' => 'npm']);

    return EnvironmentPackage::factory()->for($environment)->for($package)->create(['version' => $version, 'is_direct' => $direct, 'is_dev' => $dev]);
}

function planEdge(EnvironmentPackage $parent, EnvironmentPackage $child, string $constraint, DependencyKind $kind = DependencyKind::Dependency): void
{
    $parent->children()->attach($child, ['constraint' => $constraint, 'kind' => $kind]);
}

/**
 * @return array<int, array<string, mixed>>
 */
function planActions(array $arguments = ['project_code' => 'APP']): array
{
    return structuredContent(VoightLocalServer::tool(GetUpgradePlanTool::class, $arguments))['actions'];
}

it('groups a transitive vulnerability under its direct dependency with path and constraints', function () {
    $express = planNode($this->environment, 'express', '4.17.1', direct: true);
    $send = planNode($this->environment, 'send', '0.17.1');
    $ms = planNode($this->environment, 'ms', '2.0.0');
    planEdge($express, $send, '0.17.1');
    planEdge($send, $ms, '2.0.0', DependencyKind::Peer);
    mcpFinding($this->environment, Vulnerability::factory()->high()->create(['source_id' => 'GHSA-ms']), 'ms', '2.0.0', '2.1.3', installed: false);

    expect(planActions())->toBe([[
        'package' => 'express',
        'type' => 'npm',
        'current_version' => '4.17.1',
        'is_dev' => false,
        'resolves' => [[
            'id' => 'GHSA-ms',
            'severity' => 'high',
            'score' => (float) Vulnerability::query()->where('source_id', 'GHSA-ms')->value('vulnerability_score'),
            'package' => 'ms',
            'installed_version' => '2.0.0',
            'fixed_version' => '2.1.3',
        ]],
        'paths' => [[
            ['name' => 'express', 'version' => '4.17.1', 'constraint' => null, 'kind' => null],
            ['name' => 'send', 'version' => '0.17.1', 'constraint' => '0.17.1', 'kind' => 'dependency'],
            ['name' => 'ms', 'version' => '2.0.0', 'constraint' => '2.0.0', 'kind' => 'peer'],
        ]],
        'paths_truncated' => false,
    ]]);
});

it('makes a vulnerable direct dependency its own action with a one-node path', function () {
    planNode($this->environment, 'lodash', '4.17.15', direct: true, dev: true);
    mcpFinding($this->environment, Vulnerability::factory()->critical()->create(), 'lodash', '4.17.15', installed: false);

    $action = planActions()[0];

    expect($action['package'])->toBe('lodash')
        ->and($action['is_dev'])->toBeTrue()
        ->and($action['paths'])->toBe([[['name' => 'lodash', 'version' => '4.17.15', 'constraint' => null, 'kind' => null]]]);
});

it('lists the vulnerability under every direct dependency that introduces it, worst action first', function () {
    $jest = planNode($this->environment, 'jest', '29.0.0', direct: true, dev: true);
    $webpack = planNode($this->environment, 'webpack', '5.0.0', direct: true);
    $minimist = planNode($this->environment, 'minimist', '1.2.5');
    planEdge($jest, $minimist, '^1.2.0');
    planEdge($webpack, $minimist, '^1.2.5');
    mcpFinding($this->environment, Vulnerability::factory()->critical()->create(['source_id' => 'GHSA-min']), 'minimist', '1.2.5', installed: false);

    $lodash = planNode($this->environment, 'lodash', '4.17.15', direct: true);
    mcpFinding($this->environment, Vulnerability::factory()->low()->create(['source_id' => 'GHSA-low']), 'lodash', '4.17.15', installed: false);

    $actions = planActions();

    expect(array_column($actions, 'package'))->toBe(['jest', 'webpack', 'lodash'])
        ->and(array_column($actions[0]['resolves'], 'id'))->toBe(['GHSA-min'])
        ->and(array_column($actions[1]['resolves'], 'id'))->toBe(['GHSA-min'])
        ->and(array_column($actions[1]['paths'][0], 'name'))->toBe(['webpack', 'minimist']);
});

it('contributes the paths of every installed copy of the vulnerable version', function () {
    $a = planNode($this->environment, 'a', '1.0.0', direct: true);
    $b = planNode($this->environment, 'b', '1.0.0', direct: true);
    $nestedUnderA = planNode($this->environment, 'qs', '6.5.0');
    $nestedUnderB = planNode($this->environment, 'qs', '6.5.0');
    planEdge($a, $nestedUnderA, '~6.5.0');
    planEdge($b, $nestedUnderB, '^6.5.0');
    mcpFinding($this->environment, Vulnerability::factory()->high()->create(), 'qs', '6.5.0', installed: false);

    $actions = collect(planActions())->keyBy('package');

    expect($actions->keys()->sort()->values()->all())->toBe(['a', 'b'])
        ->and($actions['a']['paths'][0][1]['constraint'])->toBe('~6.5.0')
        ->and($actions['b']['paths'][0][1]['constraint'])->toBe('^6.5.0');
});

it('reports truncated paths', function () {
    $leaf = planNode($this->environment, 'leaf', '1.0.0');
    // More direct parents than pathsToRoots lists by default (10).
    foreach (range(1, 11) as $i) {
        planEdge(planNode($this->environment, "direct-{$i}", '1.0.0', direct: true), $leaf, '^1.0');
    }
    mcpFinding($this->environment, Vulnerability::factory()->high()->create(), 'leaf', '1.0.0', installed: false);

    $actions = planActions();

    expect($actions)->toHaveCount(11)
        ->each(fn ($action) => $action->paths_truncated->toBeTrue())
        ->and(collect($actions)->where('paths', [])->count())->toBe(1);
});

it('lists findings it cannot tie to a direct dependency as unresolved', function () {
    $this->app->bind(DependencyGraphService::class, fn () => new DependencyGraphService(expansionBudget: 1));
    $direct = planNode($this->environment, 'direct', '1.0.0', direct: true);
    $middle = planNode($this->environment, 'middle', '1.0.0');
    planEdge($direct, $middle, '^1.0');
    planEdge($middle, planNode($this->environment, 'leaf', '1.0.0'), '^1.0');
    mcpFinding($this->environment, Vulnerability::factory()->high()->create(['source_id' => 'GHSA-deep']), 'leaf', '1.0.0', installed: false);
    mcpFinding($this->environment, Vulnerability::factory()->medium()->create(['source_id' => 'GHSA-gone']), 'not-synced', '1.0.0', installed: false);

    $plan = structuredContent(VoightLocalServer::tool(GetUpgradePlanTool::class, ['project_code' => 'APP']));

    expect($plan['actions'])->toBe([])
        ->and(collect($plan['unresolved'])->pluck('reason', 'id')->all())->toBe([
            'GHSA-deep' => 'no_direct_dependency_found',
            'GHSA-gone' => 'not_in_dependency_graph',
        ]);
});

it('requires an environment when the project has several', function () {
    Environment::factory()->for($this->project)->create(['name' => 'staging']);

    VoightLocalServer::tool(GetUpgradePlanTool::class, ['project_code' => 'APP'])
        ->assertHasErrors(['Project `APP` has environments production, staging; pass `environment`.']);
    VoightLocalServer::tool(GetUpgradePlanTool::class, ['project_code' => 'APP', 'environment' => 'staging'])
        ->assertHasNoErrors();
});

it('applies the severity threshold', function () {
    planNode($this->environment, 'lodash', '4.17.15', direct: true);
    mcpFinding($this->environment, Vulnerability::factory()->low()->create(), 'lodash', '4.17.15', installed: false);

    expect(planActions(['project_code' => 'APP', 'min_severity' => 'high']))->toBe([]);
});
