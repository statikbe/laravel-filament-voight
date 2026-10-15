<?php

namespace Statikbe\FilamentVoight\Services\Mcp;

use Statikbe\FilamentVoight\Enums\Severity;
use Statikbe\FilamentVoight\Facades\FilamentVoight;
use Statikbe\FilamentVoight\Mcp\McpAccess;
use Statikbe\FilamentVoight\Models\Environment;
use Statikbe\FilamentVoight\Models\EnvironmentPackage;
use Statikbe\FilamentVoight\Models\Package;
use Statikbe\FilamentVoight\Models\Vulnerability;
use Statikbe\FilamentVoight\Services\DependencyGraphService;
use Statikbe\FilamentVoight\Support\DependencyPath;

/**
 * Maps an environment's current findings to the direct dependencies to act on, with the
 * dependency paths and constraints that explain why. No registry lookups: it does not know
 * which version of a direct dependency pulls in a fixed transitive version.
 *
 * @phpstan-type Action array{package: string, type: string, current_version: string, is_dev: bool, resolves: array<int, array<string, mixed>>, paths: array<int, array<int, array<string, mixed>>>, paths_truncated: bool, worst_score: float}
 */
class UpgradePlanService
{
    public const string NOT_IN_GRAPH = 'not_in_dependency_graph';

    public const string NO_DIRECT_DEPENDENCY_FOUND = 'no_direct_dependency_found';

    public function __construct(
        private readonly DependencyGraphService $dependencyGraph,
    ) {}

    /**
     * Findings that cannot be filed under a direct dependency are listed in `unresolved`, never dropped:
     * the package is missing from the synced dependency graph, or the walk up the graph hit its limits.
     *
     * @return array{environment: string, actions: array<int, array<string, mixed>>, unresolved: array<int, array<string, mixed>>}
     */
    public function plan(McpAccess $access, Environment $environment, ?Severity $minSeverity): array
    {
        $findings = CurrentFindings::query($access, $minSeverity)
            ->where('e.id', $environment->id)
            ->get(['f.vulnerability_id', 'f.package_id', 'f.installed_version', 'f.fixed_version']);

        $config = FilamentVoight::config();
        $packages = $config->getPackageModel()::query()->whereIn('id', $findings->pluck('package_id')->unique())->get()->keyBy('id');
        $vulnerabilities = $config->getVulnerabilityModel()::query()->whereIn('id', $findings->pluck('vulnerability_id')->unique())->get()->keyBy('id');

        /** @var array<string, Action> $actions keyed by direct node id */
        $actions = [];
        $unresolved = [];

        foreach ($findings as $finding) {
            /** @var Package $package */
            $package = $packages[$finding->package_id];
            /** @var Vulnerability $vulnerability */
            $vulnerability = $vulnerabilities[$finding->vulnerability_id];

            $resolves = [
                'id' => $vulnerability->source_id,
                'severity' => $vulnerability->severity->value,
                'score' => (float) $vulnerability->vulnerability_score,
                'package' => $package->name,
                'installed_version' => $finding->installed_version,
                'fixed_version' => $finding->fixed_version,
            ];

            $nodes = $this->dependencyGraph->nodesFor($environment, $package, $finding->installed_version);
            $filed = false;

            foreach ($nodes as $node) {
                $filed = $this->addNode($actions, $node, $resolves) || $filed;
            }

            if (! $filed) {
                $unresolved[] = [...$resolves, 'reason' => $nodes->isEmpty() ? self::NOT_IN_GRAPH : self::NO_DIRECT_DEPENDENCY_FOUND];
            }
        }

        $ordered = collect($actions)
            ->sortBy([['worst_score', 'desc'], ['package', 'asc']])
            ->map(function (array $action): array {
                unset($action['worst_score']);
                $action['resolves'] = collect($action['resolves'])->sortByDesc('score')->values()->all();
                $action['paths'] = array_values($action['paths']);

                return $action;
            })
            ->values()
            ->all();

        return [
            'environment' => $environment->name,
            'actions' => $ordered,
            'unresolved' => collect($unresolved)->sortByDesc('score')->values()->all(),
        ];
    }

    /**
     * Files the finding under every direct dependency that pulls the vulnerable node in.
     * Returns false when no direct dependency was found.
     *
     * @param  array<string, Action>  $actions
     * @param  array{id: string, severity: string, score: float, package: string, installed_version: string, fixed_version: string|null}  $resolves
     */
    private function addNode(array &$actions, EnvironmentPackage $node, array $resolves): bool
    {
        $directs = $node->is_direct ? collect([$node]) : $this->dependencyGraph->introducingDirectDependencies($node);

        if ($directs->isEmpty()) {
            return false;
        }

        $pathResult = $this->dependencyGraph->pathsToRoots($node);

        foreach ($directs as $direct) {
            $actions[$direct->id] ??= [
                'package' => $direct->package->name,
                'type' => $direct->package->type->value,
                'current_version' => $direct->version,
                'is_dev' => $direct->is_dev,
                'resolves' => [],
                'paths' => [],
                'paths_truncated' => false,
                'worst_score' => 0.0,
            ];

            $action = &$actions[$direct->id];
            $resolvesKey = $resolves['id'] . '|' . $resolves['package'] . '|' . $resolves['installed_version'];
            $action['resolves'][$resolvesKey] = $resolves;
            $action['worst_score'] = max($action['worst_score'], $resolves['score']);
            $action['paths_truncated'] = $action['paths_truncated'] || $pathResult->truncated;

            foreach ($pathResult->paths as $path) {
                if ($path->steps[0]['node']->id === $direct->id) {
                    $steps = $this->describePath($path);
                    $action['paths'][md5(serialize($steps))] = $steps;
                }
            }

            unset($action);
        }

        return true;
    }

    /**
     * @return array<int, array{name: string, version: string, constraint: string|null, kind: string|null}>
     */
    private function describePath(DependencyPath $path): array
    {
        return collect($path->steps)->map(fn (array $step): array => [
            'name' => $step['node']->package->name,
            'version' => $step['node']->version,
            'constraint' => $step['constraint'],
            'kind' => $step['kind']?->value,
        ])->all();
    }
}
