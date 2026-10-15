<?php

namespace Statikbe\FilamentVoight\Mcp\Tools;

use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\Validation\Rule;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\ResponseFactory;
use Laravel\Mcp\Server\Tools\Annotations\IsIdempotent;
use Laravel\Mcp\Server\Tools\Annotations\IsReadOnly;
use Statikbe\FilamentVoight\Facades\FilamentVoight;
use Statikbe\FilamentVoight\Mcp\McpAccess;
use Statikbe\FilamentVoight\Services\Mcp\StatisticsService;

#[IsReadOnly]
#[IsIdempotent]
class GetStatisticsTool extends VoightTool
{
    protected string $name = 'get_statistics';

    protected string $title = 'Get statistics';

    protected string $description = <<<'MARKDOWN'
        Current findings aggregated per project, team, customer, package or vulnerability, worst first (highest
        score, then most findings, then most affected projects). Answers "most vulnerable projects", "most
        widespread vulnerable packages" and "vulnerabilities reaching the most projects".
    MARKDOWN;

    public function __construct(private readonly StatisticsService $statistics) {}

    public function schema(JsonSchema $schema): array
    {
        return [
            'group_by' => $schema->string()->enum(StatisticsService::GROUPS)->required(),
            'min_severity' => $this->minSeveritySchema($schema),
            'team' => $schema->string()->description('Only projects of this team (name).'),
            'customer' => $schema->string()->description('Only projects of this customer (name).'),
            'limit' => $this->limitSchema($schema),
        ];
    }

    public function outputSchema(JsonSchema $schema): array
    {
        return [
            'group_by' => $schema->string()->required(),
            'groups' => $schema->array()->items($schema->object([
                'key' => $schema->string()->nullable(),
                'label' => $schema->string()->nullable(),
                'findings' => $schema->object()->description('Current finding counts: critical, high, medium, low, none, total.'),
                'max_score' => $schema->number()->nullable(),
                'affected_projects' => $schema->integer(),
            ]))->required(),
            'truncated' => $schema->boolean()->required(),
        ];
    }

    protected function run(Request $request, McpAccess $access): Response | ResponseFactory
    {
        $request->validate([
            ...$this->sharedRules(),
            'group_by' => ['required', Rule::in(StatisticsService::GROUPS)],
            'team' => ['nullable', 'string'],
            'customer' => ['nullable', 'string'],
        ]);

        $access->authorize('viewAny', FilamentVoight::config()->getProjectModel());

        return Response::structured($this->statistics->statistics(
            $access,
            $request->get('group_by'),
            $this->minSeverity($request),
            $request->get('team'),
            $request->get('customer'),
            $this->limit($request),
        ));
    }
}
