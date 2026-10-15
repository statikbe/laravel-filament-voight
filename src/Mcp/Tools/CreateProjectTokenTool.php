<?php

namespace Statikbe\FilamentVoight\Mcp\Tools;

use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\ResponseFactory;
use Statikbe\FilamentVoight\FilamentVoightConfig;
use Statikbe\FilamentVoight\Mcp\McpAccess;
use Statikbe\FilamentVoight\Models\Project;
use Statikbe\FilamentVoight\Services\ProjectOnboardingService;

class CreateProjectTokenTool extends VoightTool
{
    protected string $name = 'create_project_token';

    protected string $title = 'Create project token';

    protected string $description = <<<'MARKDOWN'
        Issues a new token that lets CI push lockfiles for one project. Existing tokens keep working; revoking
        happens in the Voight panel. The plain-text token is returned once and WILL appear in this conversation:
        store it straight away as a CI secret (or in an untracked `.env`) and never commit it.
    MARKDOWN;

    public function __construct(private readonly ProjectOnboardingService $onboarding) {}

    public function shouldRegister(FilamentVoightConfig $config): bool
    {
        return $config->mcpWriteToolsEnabled();
    }

    public function schema(JsonSchema $schema): array
    {
        return [
            'project_code' => $schema->string()->required(),
            'name' => $schema->string()->default(Project::DEFAULT_API_TOKEN_NAME)->description('Token name, shown in the Voight panel.'),
        ];
    }

    public function outputSchema(JsonSchema $schema): array
    {
        return [
            'project_code' => $schema->string()->required(),
            'token_name' => $schema->string()->required(),
            'token' => $schema->string()->required()->description('Shown once.'),
            'sync_endpoint' => $schema->string()->required(),
            'uploader_variables' => $schema->object([
                'VOIGHT_API_BASE_URL' => $schema->string(),
                'VOIGHT_API_TOKEN' => $schema->string(),
                'PROJECT_CODE' => $schema->string(),
            ])->required(),
            'instructions' => $schema->string()->required(),
        ];
    }

    protected function run(Request $request, McpAccess $access): Response | ResponseFactory
    {
        $request->validate([
            'project_code' => ['required', 'string'],
            'name' => ['nullable', 'string', 'max:255'],
        ]);

        $project = $access->findProject($request->get('project_code'));
        $access->authorize('update', $project);

        $tokenName = $request->get('name') ?: Project::DEFAULT_API_TOKEN_NAME;
        $token = $this->onboarding->createToken($project, $tokenName);

        return Response::structured([
            'project_code' => $project->project_code,
            'token_name' => $tokenName,
            'token' => $token->plainTextToken,
            'sync_endpoint' => url('api/voight/lock-file'),
            'uploader_variables' => [
                'VOIGHT_API_BASE_URL' => url('/'),
                'VOIGHT_API_TOKEN' => $token->plainTextToken,
                'PROJECT_CODE' => $project->project_code,
            ],
            'instructions' => 'Store VOIGHT_API_TOKEN as a CI secret (or in an untracked .env). Never commit it to the repository. '
                . 'The uploader script (' . url('scripts/voight.sh') . ') also needs APP_ENV: the environment name, e.g. production.',
        ]);
    }
}
