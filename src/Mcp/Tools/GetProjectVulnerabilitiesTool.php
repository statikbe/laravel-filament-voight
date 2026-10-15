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

#[IsReadOnly]
#[IsIdempotent]
class GetProjectVulnerabilitiesTool extends VoightTool
{
    protected string $name = 'get_project_vulnerabilities';

    protected string $title = 'Get project vulnerabilities';

    protected string $description = <<<'MARKDOWN'
        The current findings of a project (from each environment's latest audit), grouped by vulnerability,
        worst first. Per affected package: installed and fixed version, whether it is a direct dependency
        (`is_direct`) or only a dev dependency (`is_dev`), and the environments it is installed in.
        For which direct dependency to bump, use `get_upgrade_plan`.
    MARKDOWN;

    public function __construct(private readonly ProjectQueryService $projects) {}

    public function schema(JsonSchema $schema): array
    {
        return [
            'project_code' => $schema->string()->required(),
            'environment' => $schema->string()->description('Environment name; all environments when omitted.'),
            'min_severity' => $this->minSeveritySchema($schema),
            'include_dev' => $schema->boolean()->default(true)->description('false hides findings in dev-only dependencies.'),
            'limit' => $this->limitSchema($schema)->description('Maximum number of vulnerabilities (default 25, max 100).'),
        ];
    }

    public function outputSchema(JsonSchema $schema): array
    {
        return [
            'project' => $schema->object()->required(),
            'vulnerabilities' => $schema->array()->items($schema->object([
                'id' => $schema->string(),
                'aliases' => $schema->array(),
                'summary' => $schema->string(),
                'score' => $schema->number(),
                'severity' => $schema->string(),
                'packages' => $schema->array(),
            ]))->required(),
            'truncated' => $schema->boolean()->required(),
        ];
    }

    protected function run(Request $request, McpAccess $access): Response | ResponseFactory
    {
        $request->validate([
            ...$this->sharedRules(),
            'project_code' => ['required', 'string'],
            'environment' => ['nullable', 'string'],
            'include_dev' => ['nullable', 'boolean'],
        ]);

        $project = $access->findProject($request->get('project_code'));
        $access->authorize('view', $project);

        return Response::structured($this->projects->projectVulnerabilities(
            $access,
            $project,
            $this->projects->environment($project, $request->get('environment')),
            $this->minSeverity($request),
            filter_var($request->get('include_dev', true), FILTER_VALIDATE_BOOLEAN),
            $this->limit($request),
        ));
    }
}
