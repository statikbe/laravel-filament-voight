<?php

namespace Statikbe\FilamentVoight\Mcp;

use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Gate;
use InvalidArgumentException;
use Statikbe\FilamentVoight\Facades\FilamentVoight;
use Statikbe\FilamentVoight\Models\Customer;
use Statikbe\FilamentVoight\Models\Environment;
use Statikbe\FilamentVoight\Models\Package;
use Statikbe\FilamentVoight\Models\Project;
use Statikbe\FilamentVoight\Models\Vulnerability;
use Statikbe\FilamentVoight\Services\TagAssigner;

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
     * Whether the caller may change a record's tags: its model's `update` policy, unrestricted without one.
     */
    public function mayTag(Model $record): bool
    {
        return $this->isLocal || ($this->user !== null && TagAssigner::userMayTag($this->user, $record));
    }

    /**
     * Environments of visible projects.
     *
     * @return Builder<Environment>
     */
    public function visibleEnvironments(): Builder
    {
        $query = FilamentVoight::config()->getEnvironmentModel()::query();

        return $this->seesEverything ? $query : $query->whereIn('project_id', $this->visibleProjectIds());
    }

    /**
     * Customers of visible projects.
     *
     * @return Builder<Customer>
     */
    public function visibleCustomers(): Builder
    {
        $query = FilamentVoight::config()->getCustomerModel()::query();

        return $this->seesEverything
            ? $query
            : $query->whereIn('id', $this->visibleProjects()->whereNotNull('customer_id')->select('customer_id'));
    }

    /**
     * Packages installed in an environment of a visible project.
     *
     * @return Builder<Package>
     */
    public function visiblePackages(): Builder
    {
        $query = FilamentVoight::config()->getPackageModel()::query();

        return $this->seesEverything
            ? $query
            : $query->whereHas('environmentPackages.environment', fn (Builder $environment) => $environment->whereIn('project_id', $this->visibleProjectIds()));
    }

    /**
     * Vulnerabilities found in, or affecting a package installed in, a visible project.
     *
     * @return Builder<Vulnerability>
     */
    public function visibleVulnerabilities(): Builder
    {
        $query = FilamentVoight::config()->getVulnerabilityModel()::query();

        if ($this->seesEverything) {
            return $query;
        }

        return $query->where(fn (Builder $query) => $query
            ->whereHas('auditFindings.auditRun.environment', fn (Builder $environment) => $environment->whereIn('project_id', $this->visibleProjectIds()))
            ->orWhereHas('vulnerablePackageRanges', fn (Builder $range) => $range->whereIn('package_id', $this->visiblePackages()->select('voight_packages.id'))));
    }

    /**
     * The visible records of a taggable model, by morph alias.
     *
     * @return Builder<Project>|Builder<Environment>|Builder<Customer>|Builder<Package>|Builder<Vulnerability>
     */
    public function visibleTaggables(string $morphAlias): Builder
    {
        return match ($morphAlias) {
            'voight-project' => $this->visibleProjects(),
            'voight-environment' => $this->visibleEnvironments(),
            'voight-customer' => $this->visibleCustomers(),
            'voight-package' => $this->visiblePackages(),
            'voight-vulnerability' => $this->visibleVulnerabilities(),
            default => throw new InvalidArgumentException("Not a taggable model: {$morphAlias}."),
        };
    }

    /**
     * @return Builder<Project>
     */
    private function visibleProjectIds(): Builder
    {
        return $this->visibleProjects()->select('voight_projects.id');
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
