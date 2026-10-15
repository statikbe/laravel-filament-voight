<?php

namespace Statikbe\FilamentVoight;

use Closure;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Database\Eloquent\Builder;

class FilamentVoight
{
    /** @var (Closure(Authenticatable): bool)|null */
    protected ?Closure $mcpAdminResolver = null;

    /** @var (Closure(Builder, Authenticatable): Builder)|null */
    protected ?Closure $mcpVisibleProjectsResolver = null;

    public function config(): FilamentVoightConfig
    {
        return app(FilamentVoightConfig::class);
    }

    /**
     * Users for whom the callback returns true see every project over MCP. Default: nobody.
     *
     * @param  Closure(Authenticatable): bool  $resolver
     */
    public function resolveMcpAdminUsing(Closure $resolver): void
    {
        $this->mcpAdminResolver = $resolver;
    }

    /**
     * Replaces the team membership rule that decides which projects an MCP user sees.
     * The admin callback still applies first.
     *
     * @param  Closure(Builder, Authenticatable): Builder  $resolver
     */
    public function resolveMcpVisibleProjectsUsing(Closure $resolver): void
    {
        $this->mcpVisibleProjectsResolver = $resolver;
    }

    public function isMcpAdmin(Authenticatable $user): bool
    {
        return $this->mcpAdminResolver !== null && ($this->mcpAdminResolver)($user) === true;
    }

    /**
     * @return (Closure(Builder, Authenticatable): Builder)|null
     */
    public function getMcpVisibleProjectsResolver(): ?Closure
    {
        return $this->mcpVisibleProjectsResolver;
    }
}
