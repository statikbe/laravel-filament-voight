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
use Statikbe\FilamentVoight\Models\Hosting;

#[IsIdempotent]
class SetEnvironmentHostingTool extends VoightTool
{
    protected string $name = 'set_environment_hosting';

    protected string $title = 'Set environment hosting';

    protected string $description = <<<'MARKDOWN'
        Records where an environment of a project is hosted, e.g. `Combell`, `Level27`, `Own server` or
        `Customer environment`. The environment is created when the project has none by that name yet. The hosting
        name matches an existing one case-insensitively and is created when there is none, so reuse the names
        `list_projects` shows. CI can also send it on each sync through the uploader's `VOIGHT_HOSTING` variable.
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
            'hosting' => $schema->string()->required()->description('Hosting name, e.g. `Combell`.'),
        ];
    }

    public function outputSchema(JsonSchema $schema): array
    {
        return [
            'project_code' => $schema->string()->required(),
            'environment' => $schema->string()->required(),
            'hosting' => $schema->string()->required(),
            'environment_created' => $schema->boolean()->required(),
            'hosting_created' => $schema->boolean()->required(),
        ];
    }

    protected function run(Request $request, McpAccess $access): Response | ResponseFactory
    {
        $request->validate([
            'project_code' => ['required', 'string'],
            'environment' => ['required', 'string', 'max:255'],
            'hosting' => ['required', 'string', 'max:255'],
        ]);

        $project = $access->findProject($request->get('project_code'));
        $access->authorize('update', $project);

        $hosting = Hosting::findOrCreateByName($request->get('hosting'));
        $environment = FilamentVoight::config()->getEnvironmentModel()::query()->firstOrCreate(
            ['project_id' => $project->getKey(), 'name' => $request->get('environment')],
        );
        $environment->update(['hosting_id' => $hosting->getKey()]);

        return Response::structured([
            'project_code' => $project->project_code,
            'environment' => $environment->name,
            'hosting' => $hosting->name,
            'environment_created' => $environment->wasRecentlyCreated,
            'hosting_created' => $hosting->wasRecentlyCreated,
        ]);
    }
}
