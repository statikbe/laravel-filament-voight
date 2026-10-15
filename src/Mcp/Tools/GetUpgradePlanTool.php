<?php

namespace Statikbe\FilamentVoight\Mcp\Tools;

use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\ResponseFactory;
use Laravel\Mcp\Server\Tools\Annotations\IsIdempotent;
use Laravel\Mcp\Server\Tools\Annotations\IsReadOnly;
use Statikbe\FilamentVoight\Mcp\McpAccess;
use Statikbe\FilamentVoight\Services\Mcp\ProjectQueryService;
use Statikbe\FilamentVoight\Services\Mcp\UpgradePlanService;

#[IsReadOnly]
#[IsIdempotent]
class GetUpgradePlanTool extends VoightTool
{
    protected string $name = 'get_upgrade_plan';

    protected string $title = 'Get upgrade plan';

    protected string $description = <<<'MARKDOWN'
        Which direct dependencies to bump to fix an environment's current findings. One action per direct
        dependency, ordered by the worst severity it resolves. `resolves` lists the vulnerable packages beneath it
        with installed and fixed versions; `paths` shows how each is pulled in, from the direct dependency down,
        where every step's `constraint` and `kind` describe the requirement leading into it.

        Reason from the constraints: e.g. a parent requiring `^0.0.8` cannot install a fixed `1.2.6`, so that parent
        needs a bigger bump. A `peer` edge means the parent does not install its own copy: bumping it does not
        change the child's installed version. Voight does not look up registries, so it cannot tell which version
        of the direct dependency pulls in the fix. `paths_truncated` means not every path is listed.

        `unresolved` lists findings that could not be tied to a direct dependency: the package is missing from the
        synced dependency graph (`not_in_dependency_graph`) or the graph is too large to walk
        (`no_direct_dependency_found`). Report them; do not ignore them.
    MARKDOWN;

    public function __construct(
        private readonly ProjectQueryService $projects,
        private readonly UpgradePlanService $upgradePlans,
    ) {}

    public function schema(JsonSchema $schema): array
    {
        return [
            'project_code' => $schema->string()->required(),
            'environment' => $schema->string()->description('Environment name; required when the project has more than one.'),
            'min_severity' => $this->minSeveritySchema($schema),
        ];
    }

    public function outputSchema(JsonSchema $schema): array
    {
        return [
            'project' => $schema->object()->required(),
            'environment' => $schema->string()->required(),
            'actions' => $schema->array()->items($schema->object([
                'package' => $schema->string(),
                'type' => $schema->string(),
                'current_version' => $schema->string(),
                'is_dev' => $schema->boolean(),
                'resolves' => $schema->array(),
                'paths' => $schema->array(),
                'paths_truncated' => $schema->boolean(),
            ]))->required(),
            'unresolved' => $schema->array()->items($schema->object([
                'id' => $schema->string(),
                'package' => $schema->string(),
                'installed_version' => $schema->string(),
                'reason' => $schema->string()->enum([UpgradePlanService::NOT_IN_GRAPH, UpgradePlanService::NO_DIRECT_DEPENDENCY_FOUND]),
            ]))->required(),
        ];
    }

    protected function run(Request $request, McpAccess $access): Response | ResponseFactory
    {
        $request->validate([
            ...$this->sharedRules(),
            'project_code' => ['required', 'string'],
            'environment' => ['nullable', 'string'],
        ]);

        $project = $access->findProject($request->get('project_code'));
        $access->authorize('view', $project);
        $environment = $this->projects->environment($project, $request->get('environment'), required: true);

        return Response::structured([
            'project' => $this->projects->describeProject($project),
            ...$this->upgradePlans->plan($access, $environment, $this->minSeverity($request)),
        ]);
    }
}
