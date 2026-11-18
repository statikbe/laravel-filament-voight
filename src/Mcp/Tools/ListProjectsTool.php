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
use Statikbe\FilamentVoight\Enums\UpgradeStatus;
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

        Each project has a `stack`, read from its highest-stage environment: PHP version, installed framework versions
        (Laravel, Filament, Nova, Craft) with their latest major, and `upgrade_status`: `needs_upgrade` (a framework is
        behind its latest major), `current`, or `unknown` (no tracked framework, e.g. Node, Drupal, or never synced).

        To check a list of known codes (e.g. from a spreadsheet), pass `project_codes`: every matching project is
        returned, and `missing_codes` lists the codes that are unknown or not visible to you.
    MARKDOWN;

    public function __construct(private readonly ProjectQueryService $projects) {}

    public function schema(JsonSchema $schema): array
    {
        return [
            'project_codes' => $schema->array()->items($schema->string())->max(self::MAX_LIMIT)
                ->description('Exact project codes (max ' . self::MAX_LIMIT . '). The limit defaults to the number of codes; `missing_codes` lists those not found.'),
            'search' => $schema->string()->description('Matches part of the project code or name.'),
            'team' => $schema->string()->description('Team name.'),
            'customer' => $schema->string()->description('Customer name.'),
            'min_severity' => $this->minSeveritySchema($schema),
            'has_findings' => $schema->boolean()->description('true: only projects with current findings; false: only projects without.'),
            'scanned_before' => $schema->string()->format('date')->description('ISO date. Only projects with an environment not scanned since then.'),
            'upgrade_status' => $schema->string()->enum(array_column(UpgradeStatus::cases(), 'value'))
                ->description('Only projects with this upgrade status (see `stack.upgrade_status`).'),
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
                'stack' => $schema->object([
                    'environment' => $schema->string()->nullable(),
                    'php_version' => $schema->string()->nullable(),
                    'frameworks' => $schema->array()->items($schema->object([
                        'package' => $schema->string(),
                        'version' => $schema->string(),
                        'latest_major' => $schema->integer(),
                    ])),
                    'upgrade_status' => $schema->string()->enum(array_column(UpgradeStatus::cases(), 'value')),
                ]),
            ]))->required(),
            'truncated' => $schema->boolean()->required(),
            'missing_codes' => $schema->array()->items($schema->string())->description('Only when `project_codes` was passed.'),
        ];
    }

    protected function run(Request $request, McpAccess $access): Response | ResponseFactory
    {
        $request->validate([
            ...$this->sharedRules(),
            'project_codes' => ['nullable', 'array', 'max:' . self::MAX_LIMIT],
            'project_codes.*' => ['required', 'string'],
            'upgrade_status' => ['nullable', Rule::enum(UpgradeStatus::class)],
            'search' => ['nullable', 'string'],
            'team' => ['nullable', 'string'],
            'customer' => ['nullable', 'string'],
            'has_findings' => ['nullable', 'boolean'],
            'scanned_before' => ['nullable', 'date'],
            'sort' => ['nullable', Rule::in(ProjectQueryService::SORTS)],
        ]);

        $access->authorize('viewAny', FilamentVoight::config()->getProjectModel());

        $hasFindings = $request->get('has_findings');
        $projectCodes = $request->get('project_codes') === null ? null : array_values(array_unique($request->get('project_codes')));
        $upgradeStatus = $request->get('upgrade_status');

        return Response::structured($this->projects->listProjects($access, [
            'project_codes' => $projectCodes,
            'search' => $request->get('search'),
            'team' => $request->get('team'),
            'customer' => $request->get('customer'),
            'min_severity' => $this->minSeverity($request),
            'has_findings' => $hasFindings === null ? null : filter_var($hasFindings, FILTER_VALIDATE_BOOLEAN),
            'scanned_before' => $request->get('scanned_before') ? Carbon::parse($request->get('scanned_before')) : null,
            'upgrade_status' => $upgradeStatus === null ? null : UpgradeStatus::from($upgradeStatus),
            'sort' => $request->get('sort'),
            'limit' => $request->get('limit') === null && $projectCodes !== null
                ? max(self::DEFAULT_LIMIT, count($projectCodes))
                : $this->limit($request),
        ]));
    }
}
