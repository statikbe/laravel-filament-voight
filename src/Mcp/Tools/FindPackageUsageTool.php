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

#[IsReadOnly]
#[IsIdempotent]
class FindPackageUsageTool extends VoightTool
{
    protected string $name = 'find_package_usage';

    protected string $title = 'Find package usage';

    protected string $description = <<<'MARKDOWN'
        Where a package is installed: per project and environment, the installed version and whether it is a
        direct (`is_direct`) or dev-only (`is_dev`) dependency. Narrow with `version`, one comparison such as
        `<4.17.21`, `>=2.0` or `=1.2.3`, e.g. to find every project still on a vulnerable version.
    MARKDOWN;

    public function __construct(private readonly PackageUsageService $packageUsage) {}

    public function schema(JsonSchema $schema): array
    {
        return [
            'package' => $schema->string()->required()->description('Exact package name, e.g. `lodash` or `guzzlehttp/guzzle`.'),
            'type' => $schema->string()->enum(array_column(PackageType::cases(), 'value')),
            'version' => $schema->string()->description('One comparison: <, <=, >, >= or = followed by a version. A leading `v` is ignored.'),
            'include_dev' => $schema->boolean()->default(true),
            'limit' => $this->limitSchema($schema),
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
            'truncated' => $schema->boolean()->required(),
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
        ]);

        $access->authorize('viewAny', FilamentVoight::config()->getProjectModel());

        return Response::structured($this->packageUsage->find(
            $access,
            $request->get('package'),
            $request->get('type'),
            $request->get('version'),
            filter_var($request->get('include_dev', true), FILTER_VALIDATE_BOOLEAN),
            $this->limit($request),
        ));
    }
}
