<?php

namespace Statikbe\FilamentVoight\Mcp\Tools;

use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\ResponseFactory;
use RuntimeException;
use Statikbe\FilamentVoight\FilamentVoightConfig;
use Statikbe\FilamentVoight\Mcp\McpAccess;
use Statikbe\FilamentVoight\Mcp\McpToolError;
use Statikbe\FilamentVoight\Models\Customer;
use Statikbe\FilamentVoight\Models\Team;
use Statikbe\FilamentVoight\Services\Mcp\ProjectQueryService;
use Statikbe\FilamentVoight\Services\ProjectOnboardingService;

class CreateProjectTool extends VoightTool
{
    private const int MAX_LISTED_OPTIONS = 50;

    protected string $name = 'create_project';

    protected string $title = 'Create project';

    protected string $description = <<<'MARKDOWN'
        Creates a Voight project. Fails when the project code is taken; the existing project is never changed.
        `team` is required unless you can see every project, and must be one of your teams. Afterwards, call
        `create_project_token` to get the token CI uses to push lockfiles.
    MARKDOWN;

    public function __construct(
        private readonly ProjectOnboardingService $onboarding,
        private readonly ProjectQueryService $projects,
    ) {}

    public function shouldRegister(FilamentVoightConfig $config): bool
    {
        return $config->mcpWriteToolsEnabled();
    }

    public function schema(JsonSchema $schema): array
    {
        return [
            'project_code' => $schema->string()->required()->description('Unique code, e.g. the code used in the uploader\'s PROJECT_CODE.'),
            'name' => $schema->string()->required(),
            'repo_url' => $schema->string()->format('uri'),
            'team' => $schema->string()->description('Team name.'),
            'customer' => $schema->string()->description('Customer name.'),
        ];
    }

    public function outputSchema(JsonSchema $schema): array
    {
        return [
            'project' => $schema->object([
                'code' => $schema->string(),
                'name' => $schema->string()->nullable(),
                'team' => $schema->string()->nullable(),
                'customer' => $schema->string()->nullable(),
            ])->required(),
            'next_step' => $schema->string()->required(),
        ];
    }

    protected function run(Request $request, McpAccess $access): Response | ResponseFactory
    {
        $request->validate([
            'project_code' => ['required', 'string', 'max:255'],
            'name' => ['required', 'string', 'max:255'],
            'repo_url' => ['nullable', 'url', 'max:255'],
            'team' => [$access->seesEverything ? 'nullable' : 'required', 'string'],
            'customer' => ['nullable', 'string'],
        ]);

        $config = app(FilamentVoightConfig::class);
        $access->authorize('create', $config->getProjectModel());

        $team = $this->resolveTeam($access, $request->get('team'));
        $customer = $this->resolveCustomer($request->get('customer'));

        try {
            $project = $this->onboarding->createProject($request->get('project_code'), $request->get('name'), $request->get('repo_url'), $team, $customer);
        } catch (RuntimeException $exception) {
            throw new McpToolError($exception->getMessage());
        }

        return Response::structured([
            'project' => $this->projects->describeProject($project->load(['team', 'customer'])),
            'next_step' => "Call `create_project_token` with project_code `{$project->project_code}` to get the CI sync token.",
        ]);
    }

    /**
     * Remote users may only pick one of their teams, so they can see what they create.
     */
    private function resolveTeam(McpAccess $access, ?string $name): ?Team
    {
        if ($name === null || $name === '') {
            return null;
        }

        $teams = Team::query()->orderBy('name')->when(
            ! $access->seesEverything,
            fn (Builder $query): Builder => $query->whereHas('users', fn (Builder $users): Builder => $users->whereKey($access->user?->getAuthIdentifier())),
        );

        return (clone $teams)->where('name', $name)->first()
            ?? throw new McpToolError("Unknown team `{$name}`. " . $this->options('teams you can use', $teams));
    }

    private function resolveCustomer(?string $name): ?Customer
    {
        if ($name === null || $name === '') {
            return null;
        }

        $customers = app(FilamentVoightConfig::class)->getCustomerModel()::query()->orderBy('name');

        return (clone $customers)->where('name', $name)->first()
            ?? throw new McpToolError("Unknown customer `{$name}`. " . $this->options('customers', $customers));
    }

    /**
     * @param  Builder<covariant Model>  $query
     */
    private function options(string $label, Builder $query): string
    {
        $names = $query->limit(self::MAX_LISTED_OPTIONS)->pluck('name');

        return $names->isEmpty() ? "There are no {$label}." : 'Valid ' . $label . ': ' . $names->implode(', ') . '.';
    }
}
