<?php

use Statikbe\FilamentVoight\Enums\DependencyKind;
use Statikbe\FilamentVoight\Models\Environment;
use Statikbe\FilamentVoight\Models\EnvironmentPackage;
use Statikbe\FilamentVoight\Models\Package;
use Statikbe\FilamentVoight\Services\DependencyGraphService;
use Statikbe\FilamentVoight\Support\DependencyPath;

beforeEach(function () {
    $this->environment = Environment::factory()->create();
});

function graphNode(Environment $environment, string $name, bool $direct = false, string $version = '1.0.0'): EnvironmentPackage
{
    $package = Package::query()->firstOrCreate(['name' => $name, 'type' => 'npm']);

    return EnvironmentPackage::factory()->for($environment)->for($package)->create([
        'version' => $version,
        'is_direct' => $direct,
    ]);
}

function graphEdge(EnvironmentPackage $parent, EnvironmentPackage $child, string $constraint = '^1.0', DependencyKind $kind = DependencyKind::Dependency): void
{
    $parent->children()->attach($child, ['constraint' => $constraint, 'kind' => $kind]);
}

/**
 * @return array<int, string> each path as "a > b > c"
 */
function pathNames(iterable $paths): array
{
    return collect($paths)
        ->map(fn (DependencyPath $path): string => collect($path->steps)->map(fn (array $step): string => $step['node']->package->name)->implode(' > '))
        ->all();
}

it('returns a direct node as its own single path', function () {
    $express = graphNode($this->environment, 'express', direct: true);

    $result = app(DependencyGraphService::class)->pathsToRoots($express);

    expect(pathNames($result->paths))->toBe(['express'])
        ->and($result->truncated)->toBeFalse();
});

it('orders each path from the direct dependency down to the node with the edge leading into every step', function () {
    $express = graphNode($this->environment, 'express', direct: true);
    $send = graphNode($this->environment, 'send');
    $ms = graphNode($this->environment, 'ms');
    graphEdge($express, $send, '0.18.0');
    graphEdge($send, $ms, '2.1.3');

    $steps = app(DependencyGraphService::class)->pathsToRoots($ms)->paths->sole()->steps;

    expect(collect($steps)->map(fn (array $step): array => [$step['node']->id, $step['constraint'], $step['kind']])->all())->toBe([
        [$express->id, null, null],
        [$send->id, '0.18.0', DependencyKind::Dependency],
        [$ms->id, '2.1.3', DependencyKind::Dependency],
    ]);
});

it('returns the paths from every direct dependency, shortest first', function () {
    $ms = graphNode($this->environment, 'ms');
    $express = graphNode($this->environment, 'express', direct: true);
    $send = graphNode($this->environment, 'send');
    $debug = graphNode($this->environment, 'debug', direct: true);
    graphEdge($express, $send);
    graphEdge($send, $ms);
    graphEdge($debug, $ms);

    expect(pathNames(app(DependencyGraphService::class)->pathsToRoots($ms)->paths))
        ->toBe(['debug > ms', 'express > send > ms']);
});

it('follows peer edges and reports their kind', function () {
    $react = graphNode($this->environment, 'react');
    $reactDom = graphNode($this->environment, 'react-dom', direct: true);
    graphEdge($reactDom, $react, '^18.2.0', DependencyKind::Peer);

    $steps = app(DependencyGraphService::class)->pathsToRoots($react)->paths->sole()->steps;

    expect($steps[1]['kind'])->toBe(DependencyKind::Peer);
});

it('ends a path at a parentless node that is not direct, so it is never lost', function () {
    $orphan = graphNode($this->environment, 'orphan');
    $ms = graphNode($this->environment, 'ms');
    graphEdge($orphan, $ms);

    expect(pathNames(app(DependencyGraphService::class)->pathsToRoots($ms)->paths))->toBe(['orphan > ms']);
});

it('terminates on a dependency cycle', function () {
    $vue = graphNode($this->environment, 'vue', direct: true);
    $runtimeCore = graphNode($this->environment, 'runtime-core');
    $shared = graphNode($this->environment, 'shared');
    graphEdge($vue, $runtimeCore);
    graphEdge($runtimeCore, $shared);
    graphEdge($shared, $runtimeCore, '3.4.0', DependencyKind::Peer);

    expect(pathNames(app(DependencyGraphService::class)->pathsToRoots($shared)->paths))->toBe(['vue > runtime-core > shared']);
});

it('stops at maxPaths and reports the result as truncated', function () {
    $ms = graphNode($this->environment, 'ms');
    foreach (['a', 'b', 'c'] as $name) {
        graphEdge(graphNode($this->environment, $name, direct: true), $ms);
    }

    $result = app(DependencyGraphService::class)->pathsToRoots($ms, maxPaths: 2);

    expect($result->paths)->toHaveCount(2)
        ->and($result->truncated)->toBeTrue();
});

it('does not report a result that exactly fills maxPaths as truncated', function () {
    $ms = graphNode($this->environment, 'ms');
    foreach (['a', 'b'] as $name) {
        graphEdge(graphNode($this->environment, $name, direct: true), $ms);
    }

    $result = app(DependencyGraphService::class)->pathsToRoots($ms, maxPaths: 2);

    expect($result->paths)->toHaveCount(2)
        ->and($result->truncated)->toBeFalse();
});

it('drops paths longer than maxDepth and reports the result as truncated', function () {
    $root = graphNode($this->environment, 'root', direct: true);
    $middle = graphNode($this->environment, 'middle');
    $leaf = graphNode($this->environment, 'leaf');
    graphEdge($root, $middle);
    graphEdge($middle, $leaf);

    $result = app(DependencyGraphService::class)->pathsToRoots($leaf, maxDepth: 1);

    expect($result->paths)->toBeEmpty()
        ->and($result->truncated)->toBeTrue();
});

it('stops when the expansion budget is spent and reports the result as truncated', function () {
    $root = graphNode($this->environment, 'root', direct: true);
    $middle = graphNode($this->environment, 'middle');
    $leaf = graphNode($this->environment, 'leaf');
    graphEdge($root, $middle);
    graphEdge($middle, $leaf);

    $result = (new DependencyGraphService(expansionBudget: 2))->pathsToRoots($leaf);

    expect($result->paths)->toBeEmpty()
        ->and($result->truncated)->toBeTrue();
});

it('lists each direct dependency that pulls the node in once', function () {
    $ms = graphNode($this->environment, 'ms');
    $express = graphNode($this->environment, 'express', direct: true);
    $send = graphNode($this->environment, 'send');
    $debug = graphNode($this->environment, 'debug');
    graphEdge($express, $send);
    graphEdge($express, $debug);
    graphEdge($send, $ms);
    graphEdge($debug, $ms);
    graphEdge(graphNode($this->environment, 'vite', direct: true), $ms);

    $introducing = app(DependencyGraphService::class)->introducingDirectDependencies($ms);

    expect($introducing->map(fn (EnvironmentPackage $node): string => $node->package->name)->sort()->values()->all())
        ->toBe(['express', 'vite']);
});

it('finds every installed copy of a package version in the environment', function () {
    $first = graphNode($this->environment, 'ms', version: '2.0.0');
    $second = graphNode($this->environment, 'ms', version: '2.0.0');
    graphNode($this->environment, 'ms', version: '2.1.3');
    graphNode(Environment::factory()->create(), 'ms', version: '2.0.0');

    $nodes = app(DependencyGraphService::class)->nodesFor($this->environment, $first->package, '2.0.0');

    expect($nodes->pluck('id')->sort()->values()->all())->toBe(collect([$first->id, $second->id])->sort()->values()->all());
});
