<?php

namespace Statikbe\FilamentVoight\Mcp;

use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Gate;
use Statikbe\FilamentVoight\Facades\FilamentVoight;
use Statikbe\FilamentVoight\Models\Project;

/**
 * Who is calling the MCP server, built once per request by the server. Decides which
 * projects exist for the caller (visibility) and what the caller may do (host policies).
 */
final class McpAccess
{
    private function __construct(
        public readonly ?Authenticatable $user,
        public readonly bool $seesEverything,
        private readonly bool $isLocal,
    ) {}

    /**
     * The stdio server: runs as the machine's operator, sees everything, skips policies.
     */
    public static function local(): self
    {
        return new self(null, true, true);
    }

    /**
     * The HTTP server. Without a user, every authorization fails.
     */
    public static function forUser(?Authenticatable $user): self
    {
        return new self($user, $user !== null && FilamentVoight::isMcpAdmin($user), false);
    }

    /**
     * Checks the host's policy for the project model. A denied `view` or `update` on a
     * project reports "not found", like an invisible project; other denials report "not allowed".
     *
     * @throws McpToolError
     */
    public function authorize(string $ability, Project | string $target): void
    {
        if ($this->isLocal) {
            return;
        }

        if ($this->user === null) {
            throw McpToolError::unauthenticated();
        }

        $projectModel = FilamentVoight::config()->getProjectModel();

        // Like Filament: without a policy there is nothing to check. Gate would deny.
        if (Gate::getPolicyFor($projectModel) === null) {
            return;
        }

        if (Gate::forUser($this->user)->allows($ability, $target)) {
            return;
        }

        throw $target instanceof Project
            ? McpToolError::projectNotFound($target->project_code)
            : McpToolError::notAllowed();
    }

    /**
     * @return Builder<Project>
     */
    public function visibleProjects(): Builder
    {
        $query = FilamentVoight::config()->getProjectModel()::query();

        if ($this->seesEverything) {
            return $query;
        }

        if ($this->user === null) {
            return $query->whereRaw('1 = 0');
        }

        return $query->visibleTo($this->user);
    }

    /**
     * A visible project by code. Unknown and invisible codes get the same error.
     *
     * @throws McpToolError
     */
    public function findProject(string $projectCode): Project
    {
        return $this->visibleProjects()->where('project_code', $projectCode)->first()
            ?? throw McpToolError::projectNotFound($projectCode);
    }
}
