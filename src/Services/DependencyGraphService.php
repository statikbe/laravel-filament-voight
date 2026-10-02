<?php

namespace Statikbe\FilamentVoight\Services;

use Illuminate\Support\Collection;
use SplQueue;
use Statikbe\FilamentVoight\Enums\DependencyKind;
use Statikbe\FilamentVoight\Facades\FilamentVoight;
use Statikbe\FilamentVoight\Models\Environment;
use Statikbe\FilamentVoight\Models\EnvironmentPackage;
use Statikbe\FilamentVoight\Models\EnvironmentPackageDependency;
use Statikbe\FilamentVoight\Models\Package;
use Statikbe\FilamentVoight\Support\DependencyPath;
use Statikbe\FilamentVoight\Support\DependencyPathResult;

/**
 * Answers "why is this package installed?" by walking an environment's dependency
 * graph upward, in PHP, from an installed package to the direct dependencies that
 * pull it in.
 *
 * @phpstan-type Edge array{parent_id: string, constraint: string|null, kind: DependencyKind}
 * @phpstan-type PartialPath array{ids: array<int, string>, edges: array<int, Edge>}
 */
class DependencyGraphService
{
    private const int DEFAULT_EXPANSION_BUDGET = 10_000;

    public function __construct(
        private readonly int $expansionBudget = self::DEFAULT_EXPANSION_BUDGET,
    ) {}

    /**
     * The installed copies of a package version in the environment, e.g. for an audit finding.
     *
     * @return Collection<int, EnvironmentPackage>
     */
    public function nodesFor(Environment $environment, Package $package, string $version): Collection
    {
        return $environment->environmentPackages()
            ->where('package_id', $package->id)
            ->where('version', $version)
            ->with('package')
            ->get();
    }

    /**
     * Paths from a direct dependency down to the node, shortest first.
     */
    public function pathsToRoots(EnvironmentPackage $node, int $maxPaths = 10, int $maxDepth = 15): DependencyPathResult
    {
        $nodes = $this->environmentNodes($node);
        [$completedPaths, $truncated] = $this->walkUp($node, $nodes, $maxPaths, $maxDepth);

        $paths = collect($completedPaths)->map(function (array $path) use ($nodes): DependencyPath {
            $steps = [];

            foreach ($path['ids'] as $index => $id) {
                $edge = $path['edges'][$index] ?? null;
                $steps[] = ['node' => $nodes[$id], 'constraint' => $edge['constraint'] ?? null, 'kind' => $edge['kind'] ?? null];
            }

            return new DependencyPath(array_reverse($steps));
        });

        return new DependencyPathResult($paths->values(), $truncated);
    }

    /**
     * The distinct direct dependencies whose subtree contains the node: what you would bump.
     *
     * @return Collection<int, EnvironmentPackage>
     */
    public function introducingDirectDependencies(EnvironmentPackage $node, int $maxDepth = 15): Collection
    {
        $nodes = $this->environmentNodes($node);
        [$completedPaths] = $this->walkUp($node, $nodes, PHP_INT_MAX, $maxDepth);

        return collect($completedPaths)
            ->map(fn (array $path): string => self::headId($path))
            ->unique()
            ->map(fn (string $id): EnvironmentPackage => $nodes[$id])
            ->values();
    }

    /**
     * Breadth-first over partial paths, so complete paths come out shortest first.
     * A path is complete at a direct node (what you bump) or at a node nothing
     * requires; a node already on the path ends that branch, which breaks cycles.
     *
     * @param  array<string, EnvironmentPackage>  $nodes
     * @return array{0: array<int, PartialPath>, 1: bool} completed paths (node first), truncated
     */
    private function walkUp(EnvironmentPackage $start, array $nodes, int $maxPaths, int $maxDepth): array
    {
        $parentEdges = $this->parentEdgesByChild($start->environment_id);
        $completed = [];
        $truncated = false;
        $expanded = 0;

        /** @var SplQueue<PartialPath> $queue */
        $queue = new SplQueue;
        $queue->enqueue(['ids' => [$start->id], 'edges' => []]);

        while (! $queue->isEmpty()) {
            if (count($completed) >= $maxPaths || $expanded >= $this->expansionBudget) {
                $truncated = true;

                break;
            }

            $path = $queue->dequeue();
            $expanded++;
            $headId = self::headId($path);
            $parents = $parentEdges[$headId] ?? [];

            if ($nodes[$headId]->is_direct || $parents === []) {
                $completed[] = $path;

                continue;
            }

            if (count($path['edges']) >= $maxDepth) {
                $truncated = true;

                continue;
            }

            foreach ($parents as $edge) {
                if (! in_array($edge['parent_id'], $path['ids'], true)) {
                    $queue->enqueue(['ids' => [...$path['ids'], $edge['parent_id']], 'edges' => [...$path['edges'], $edge]]);
                }
            }
        }

        return [$completed, $truncated];
    }

    /**
     * @return array<string, EnvironmentPackage>
     */
    private function environmentNodes(EnvironmentPackage $node): array
    {
        return FilamentVoight::config()->getEnvironmentPackageModel()::query()
            ->where('environment_id', $node->environment_id)
            ->with('package')
            ->get()
            ->keyBy('id')
            ->all();
    }

    /**
     * @return array<string, array<int, Edge>> child id => edges to its parents
     */
    private function parentEdgesByChild(string $environmentId): array
    {
        $parentEdges = [];

        $edges = EnvironmentPackageDependency::query()
            ->join('voight_environment_packages as children', 'children.id', '=', 'voight_environment_package_dependencies.child_id')
            ->where('children.environment_id', $environmentId)
            ->orderBy('voight_environment_package_dependencies.parent_id')
            ->get(['voight_environment_package_dependencies.parent_id', 'voight_environment_package_dependencies.child_id', 'constraint', 'kind']);

        foreach ($edges as $edge) {
            $parentEdges[$edge->child_id][] = ['parent_id' => $edge->parent_id, 'constraint' => $edge->constraint, 'kind' => $edge->kind];
        }

        return $parentEdges;
    }

    /**
     * The node a partial path currently ends at.
     *
     * @param  PartialPath  $path
     */
    private static function headId(array $path): string
    {
        return $path['ids'][array_key_last($path['ids'])];
    }
}
