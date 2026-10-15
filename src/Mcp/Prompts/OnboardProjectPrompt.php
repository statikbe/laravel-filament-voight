<?php

namespace Statikbe\FilamentVoight\Mcp\Prompts;

use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Prompt;
use Statikbe\FilamentVoight\FilamentVoightConfig;

class OnboardProjectPrompt extends Prompt
{
    protected string $name = 'onboard_project';

    protected string $title = 'Onboard project';

    protected string $description = 'Register the current repository in Voight and add the lockfile uploader to its CI.';

    public function shouldRegister(FilamentVoightConfig $config): bool
    {
        return $config->mcpWriteToolsEnabled();
    }

    public function handle(): Response
    {
        $scriptUrl = url('scripts/voight.sh');

        return Response::text(<<<MARKDOWN
            Onboard the current repository in Voight.

            1. Inspect the repository: its lockfiles (composer.lock, package-lock.json, yarn.lock, pnpm-lock.yaml,
               bun.lock), its CI configuration, and a sensible project code and name. Check with `list_projects`
               that the project does not exist yet; ask me before creating anything if it might.
            2. Call `create_project`. Ask me for the team when it is not obvious.
            3. Call `create_project_token`. Treat the token as a secret: never write it to a tracked file.
            4. Add a CI step, after dependencies are installed and per deployed environment, that downloads and runs
               the uploader: `curl -fsSL {$scriptUrl} | sh`. It needs VOIGHT_API_BASE_URL, VOIGHT_API_TOKEN,
               PROJECT_CODE and APP_ENV (the environment name).
            5. Store VOIGHT_API_TOKEN as a CI secret, or tell me exactly where to add it if you cannot. Put the
               non-secret variables in the CI configuration.
            6. Summarize what you changed and what I still need to do.
            MARKDOWN);
    }
}
