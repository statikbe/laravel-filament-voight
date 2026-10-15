<?php

namespace Statikbe\FilamentVoight\Mcp;

use Illuminate\Container\Container;
use Laravel\Mcp\Server;
use Laravel\Mcp\Server\Prompt;
use Laravel\Mcp\Server\Tool;
use Statikbe\FilamentVoight\Mcp\Prompts\OnboardProjectPrompt;
use Statikbe\FilamentVoight\Mcp\Prompts\SecurityDigestPrompt;
use Statikbe\FilamentVoight\Mcp\Prompts\TriageProjectPrompt;
use Statikbe\FilamentVoight\Mcp\Tools\CreateProjectTokenTool;
use Statikbe\FilamentVoight\Mcp\Tools\CreateProjectTool;
use Statikbe\FilamentVoight\Mcp\Tools\FindPackageUsageTool;
use Statikbe\FilamentVoight\Mcp\Tools\GetProjectVulnerabilitiesTool;
use Statikbe\FilamentVoight\Mcp\Tools\GetStatisticsTool;
use Statikbe\FilamentVoight\Mcp\Tools\GetUpgradePlanTool;
use Statikbe\FilamentVoight\Mcp\Tools\GetVulnerabilityTool;
use Statikbe\FilamentVoight\Mcp\Tools\ListProjectsTool;

/**
 * The remote server, over HTTP. Every tool requires an authenticated user; without one,
 * tools return an error rather than full access.
 */
class VoightServer extends Server
{
    protected string $name = 'Voight';

    protected string $version = '1.0.0';

    protected string $instructions = <<<'MARKDOWN'
        Voight tracks the dependencies and known vulnerabilities of software projects.

        Domain: a project (identified by `project_code`, optionally with a team and customer) has environments
        (e.g. production, staging). Each environment has installed packages (composer or npm), synced from its
        lockfiles, with the dependency graph between them. Audit runs scan an environment's packages against
        OSV and record findings: a vulnerability affecting an installed package version.

        "Current findings" are the findings of each environment's latest audit run; older runs are history.
        Severity follows the CVSS score: critical ≥ 9.0, high ≥ 7.0, medium ≥ 4.0, low ≥ 0.1.

        Start with `list_projects` or `get_statistics`; drill down with `get_project_vulnerabilities` and
        `get_upgrade_plan`; use `find_package_usage` and `get_vulnerability` for cross-project questions.
        You only see the projects you have access to; an unknown project code may also be one you cannot see.
    MARKDOWN;

    /**
     * @var array<int, class-string<Tool>>
     */
    protected array $tools = [
        ListProjectsTool::class,
        GetProjectVulnerabilitiesTool::class,
        GetUpgradePlanTool::class,
        FindPackageUsageTool::class,
        GetVulnerabilityTool::class,
        GetStatisticsTool::class,
        CreateProjectTool::class,
        CreateProjectTokenTool::class,
    ];

    /**
     * @var array<int, class-string<Prompt>>
     */
    protected array $prompts = [
        TriageProjectPrompt::class,
        SecurityDigestPrompt::class,
        OnboardProjectPrompt::class,
    ];

    protected function boot(): void
    {
        Container::getInstance()->instance(McpAccess::class, $this->access());
    }

    protected function access(): McpAccess
    {
        return McpAccess::forUser(auth()->user());
    }
}
