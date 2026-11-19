<?php

namespace Statikbe\FilamentVoight\Mcp\Tools;

use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\ResponseFactory;
use Laravel\Mcp\Server\Tools\Annotations\IsIdempotent;
use Statikbe\FilamentVoight\Facades\FilamentVoight;
use Statikbe\FilamentVoight\FilamentVoightConfig;
use Statikbe\FilamentVoight\Mcp\McpAccess;

#[IsIdempotent]
class SetEnvironmentHostingsTool extends VoightTool
{
    private const int MAX_HOSTINGS = 10;

    protected string $name = 'set_environment_hostings';

    protected string $title = 'Set environment hostings';

    protected string $description = <<<'MARKDOWN'
        Records where an environment of a project is hosted, e.g. `Combell`, `Level27`, `Own server` or
        `Customer environment`. Hostings are tags: the given list replaces the environment's hostings. The
        environment is created when the project has none by that name yet. Names match existing hostings
        case-insensitively and unknown ones are created, so reuse the names `list_projects` shows. CI can also send
        them on each sync through the uploader's `VOIGHT_HOSTING` variable (comma-separated).
    MARKDOWN;

    public function shouldRegister(FilamentVoightConfig $config): bool
    {
        return $config->mcpWriteToolsEnabled();
    }

    public function schema(JsonSchema $schema): array
    {
        return [
            'project_code' => $schema->string()->required(),
            'environment' => $schema->string()->required()->description('Environment name, e.g. `production`.'),
            'hostings' => $schema->array()->items($schema->string())->min(1)->max(self::MAX_HOSTINGS)->required()
                ->description('Hosting names, e.g. `["Combell"]`. Replaces the current ones.'),
        ];
    }

    public function outputSchema(JsonSchema $schema): array
    {
        return [
            'project_code' => $schema->string()->required(),
            'environment' => $schema->string()->required(),
            'hostings' => $schema->array()->items($schema->string())->required()->description('The stored names, after matching.'),
            'environment_created' => $schema->boolean()->required(),
            'created_hostings' => $schema->array()->items($schema->string())->required()->description('Hostings that did not exist yet.'),
        ];
    }

    protected function run(Request $request, McpAccess $access): Response | ResponseFactory
    {
        $request->validate([
            'project_code' => ['required', 'string'],
            'environment' => ['required', 'string', 'max:255'],
            'hostings' => ['required', 'array', 'min:1', 'max:' . self::MAX_HOSTINGS],
            'hostings.*' => ['required', 'string', 'max:255'],
        ]);

        $project = $access->findProject($request->get('project_code'));
        $access->authorize('update', $project);

        $environment = FilamentVoight::config()->getEnvironmentModel()::query()->firstOrCreate(
            ['project_id' => $project->getKey(), 'name' => $request->get('environment')],
        );
        $created = $environment->syncHostings($request->get('hostings'));

        return Response::structured([
            'project_code' => $project->project_code,
            'environment' => $environment->name,
            'hostings' => $environment->hostings->pluck('name')->all(),
            'environment_created' => $environment->wasRecentlyCreated,
            'created_hostings' => $created,
        ]);
    }
}
