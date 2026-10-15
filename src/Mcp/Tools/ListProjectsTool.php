<?php

namespace Statikbe\FilamentVoight\Mcp\Tools;

use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\Support\Carbon;
use Illuminate\Validation\Rule;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\ResponseFactory;
use Laravel\Mcp\Server\Tools\Annotations\IsIdempotent;
use Laravel\Mcp\Server\Tools\Annotations\IsReadOnly;
use Statikbe\FilamentVoight\Facades\FilamentVoight;
use Statikbe\FilamentVoight\Mcp\McpAccess;
use Statikbe\FilamentVoight\Services\Mcp\ProjectQueryService;

#[IsReadOnly]
#[IsIdempotent]
class ListProjectsTool extends VoightTool
{
    protected string $name = 'list_projects';

    protected string $title = 'List projects';

    protected string $description = <<<'MARKDOWN'
        Lists the projects you can see with their environments, when each was last scanned, and their current
        finding counts per severity. Use it to find project codes, the most vulnerable projects, or stale scans
        (`scanned_before`: projects with an environment not scanned since that date, or never scanned).
        Sorts: `findings` (most first, default), `max_score` (worst first), `name`, `last_scanned` (least recently first).
    MARKDOWN;

    public function __construct(private readonly ProjectQueryService $projects) {}

    public function schema(JsonSchema $schema): array
    {
        return [
            'search' => $schema->string()->description('Matches part of the project code or name.'),
            'team' => $schema->string()->description('Team name.'),
            'customer' => $schema->string()->description('Customer name.'),
            'min_severity' => $this->minSeveritySchema($schema),
            'has_findings' => $schema->boolean()->description('true: only projects with current findings; false: only projects without.'),
            'scanned_before' => $schema->string()->format('date')->description('ISO date. Only projects with an environment not scanned since then.'),
            'sort' => $schema->string()->enum(ProjectQueryService::SORTS)->default('findings'),
            'limit' => $this->limitSchema($schema),
        ];
    }

    public function outputSchema(JsonSchema $schema): array
    {
        return [
            'projects' => $schema->array()->items($schema->object([
                'code' => $schema->string(),
                'name' => $schema->string()->nullable(),
                'team' => $schema->string()->nullable(),
                'customer' => $schema->string()->nullable(),
                'environments' => $schema->array(),
                'findings' => $schema->object()->description('Current finding counts: critical, high, medium, low, none, total.'),
                'max_score' => $schema->number()->nullable(),
            ]))->required(),
            'truncated' => $schema->boolean()->required(),
        ];
    }

    protected function run(Request $request, McpAccess $access): Response | ResponseFactory
    {
        $request->validate([
            ...$this->sharedRules(),
            'search' => ['nullable', 'string'],
            'team' => ['nullable', 'string'],
            'customer' => ['nullable', 'string'],
            'has_findings' => ['nullable', 'boolean'],
            'scanned_before' => ['nullable', 'date'],
            'sort' => ['nullable', Rule::in(ProjectQueryService::SORTS)],
        ]);

        $access->authorize('viewAny', FilamentVoight::config()->getProjectModel());

        $hasFindings = $request->get('has_findings');

        return Response::structured($this->projects->listProjects($access, [
            'search' => $request->get('search'),
            'team' => $request->get('team'),
            'customer' => $request->get('customer'),
            'min_severity' => $this->minSeverity($request),
            'has_findings' => $hasFindings === null ? null : filter_var($hasFindings, FILTER_VALIDATE_BOOLEAN),
            'scanned_before' => $request->get('scanned_before') ? Carbon::parse($request->get('scanned_before')) : null,
            'sort' => $request->get('sort'),
            'limit' => $this->limit($request),
        ]));
    }
}
