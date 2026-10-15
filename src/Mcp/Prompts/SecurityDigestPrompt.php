<?php

namespace Statikbe\FilamentVoight\Mcp\Prompts;

use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Prompt;
use Laravel\Mcp\Server\Prompts\Argument;

class SecurityDigestPrompt extends Prompt
{
    protected string $name = 'security_digest';

    protected string $title = 'Security digest';

    protected string $description = 'A weekly security summary across projects, including stale scans.';

    /**
     * @return array<int, Argument>
     */
    public function arguments(): array
    {
        return [
            new Argument('team', 'Limit the digest to this team (name).'),
        ];
    }

    public function handle(Request $request): Response
    {
        $request->validate(['team' => ['nullable', 'string']]);
        $team = $request->get('team');
        $scope = filled($team) ? "for team `{$team}` (pass `team: \"{$team}\"` to every call)" : 'across all projects you can see';
        $weekAgo = now()->subDays(7)->toDateString();

        return Response::text(<<<MARKDOWN
            Write a weekly Voight security digest {$scope}.

            1. Call `get_statistics` with `group_by: "project"` and with `group_by: "vulnerability"`.
            2. Call `list_projects` with `scanned_before: "{$weekAgo}"` to find projects whose scans are stale.
            3. Summarize: the overall numbers per severity, the most vulnerable projects, the vulnerabilities that
               reach the most projects, and the projects that have not been scanned in the last 7 days.
            4. End with the three most valuable actions for the coming week.
            MARKDOWN);
    }
}
