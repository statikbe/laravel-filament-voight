<?php

namespace Statikbe\FilamentVoight\Mcp\Prompts;

use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Prompt;
use Laravel\Mcp\Server\Prompts\Argument;

class TriageProjectPrompt extends Prompt
{
    protected string $name = 'triage_project';

    protected string $title = 'Triage project';

    protected string $description = 'A prioritized list of fixes for one project\'s vulnerabilities.';

    /**
     * @return array<int, Argument>
     */
    public function arguments(): array
    {
        return [
            new Argument('project_code', 'The Voight project code.', required: true),
        ];
    }

    public function handle(Request $request): Response
    {
        $request->validate(['project_code' => ['required', 'string']]);
        $projectCode = $request->get('project_code');

        return Response::text(<<<MARKDOWN
            Triage the vulnerabilities of Voight project `{$projectCode}`.

            1. Call `get_project_vulnerabilities` for `{$projectCode}`.
            2. Call `get_upgrade_plan` for `{$projectCode}`, once per environment if it has several.
            3. Give a prioritized fix list. Rank by severity first, then reach (production before staging, runtime
               before dev-only dependencies, number of environments affected), then the effort of the bump
               (patch, minor or major, judged from the constraints in each path).
            4. For every fix, name the direct dependency to bump, its current version, the vulnerabilities it
               resolves, and whether a major bump is needed. Call out fixes that a peer dependency blocks.
            MARKDOWN);
    }
}
