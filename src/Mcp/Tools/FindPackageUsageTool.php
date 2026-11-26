<?php

namespace Statikbe\FilamentVoight\Mcp\Tools;

use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\Validation\Rule;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\ResponseFactory;
use Laravel\Mcp\Server\Tools\Annotations\IsIdempotent;
use Laravel\Mcp\Server\Tools\Annotations\IsReadOnly;
use Statikbe\FilamentVoight\Enums\PackageType;
use Statikbe\FilamentVoight\Facades\FilamentVoight;
use Statikbe\FilamentVoight\Mcp\McpAccess;
use Statikbe\FilamentVoight\Services\Mcp\PackageUsageService;
use Statikbe\FilamentVoight\Services\Mcp\TagReferences;

#[IsReadOnly]
#[IsIdempotent]
class FindPackageUsageTool extends VoightTool
{
    protected string $name = 'find_package_usage';

    protected string $title = 'Find package usage';

    protected string $description = <<<'MARKDOWN'
        Where a package is installed: per project and environment, the installed version and whether it is a
        direct (`is_direct`) or dev-only (`is_dev`) dependency. Narrow with `version`: one or more comparisons that
        must all hold, such as `<4.17.21`, `=1.2.3` or the range `>=7.0 <7.4`, e.g. to find every project still on
        a vulnerable version. To list the projects themselves, use `list_projects` with `package`.
    MARKDOWN;

    public function __construct(private readonly PackageUsageService $packageUsage) {}

    public function schema(JsonSchema $schema): array
    {
        return [
            'package' => $schema->string()->required()->description('Exact package name, e.g. `lodash` or `guzzlehttp/guzzle`.'),
            'type' => $schema->string()->enum(array_column(PackageType::cases(), 'value')),
            'version' => $schema->string()->description('One or more comparisons (<, <=, >, >=, =) separated by spaces, all of which must hold, e.g. `>=7.0 <7.4`. A leading `v` is ignored.'),
            'include_dev' => $schema->boolean()->default(true),
            'tags' => TagReferences::schema($schema)->description('Only projects with these project tags (any of the tags given per type, every type given). ' . TagReferences::DESCRIPTION),
            'limit' => $this->limitSchema($schema),
            'offset' => $this->offsetSchema($schema),
        ];
    }

    public function outputSchema(JsonSchema $schema): array
    {
        return [
            'usages' => $schema->array()->items($schema->object([
                'project_code' => $schema->string(),
                'environment' => $schema->string(),
                'type' => $schema->string(),
                'version' => $schema->string(),
                'is_direct' => $schema->boolean(),
                'is_dev' => $schema->boolean(),
            ]))->required(),
            ...$this->pagingOutputSchema($schema),
        ];
    }

    protected function run(Request $request, McpAccess $access): Response | ResponseFactory
    {
        $request->validate([
            ...$this->sharedRules(),
            'package' => ['required', 'string'],
            'type' => ['nullable', Rule::enum(PackageType::class)],
            'version' => ['nullable', 'string'],
            'include_dev' => ['nullable', 'boolean'],
            ...TagReferences::rules('tags'),
        ]);

        $access->authorize('viewAny', FilamentVoight::config()->getProjectModel());

        return Response::structured($this->packageUsage->find(
            $access,
            $request->get('package'),
            $request->get('type'),
            $request->get('version'),
            filter_var($request->get('include_dev', true), FILTER_VALIDATE_BOOLEAN),
            $this->page($request),
            filled($request->get('tags')) ? TagReferences::resolveOrFail($request->get('tags')) : null,
        ));
    }
}
