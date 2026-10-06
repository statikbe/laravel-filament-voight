<?php

namespace Statikbe\FilamentVoight\Resources\Concerns;

use Filament\Panel;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Statikbe\FilamentVoight\Support\TenantProjectScope;

/**
 * For resources whose model has no team of its own: records belong to a tenant through the projects they relate to.
 */
trait ScopesToTenantThroughProjects
{
    /**
     * @return array<int, string>
     */
    abstract protected static function getTenantProjectPaths(): array;

    /**
     * @param  Builder<Model>  $query
     * @return Builder<Model>
     */
    public static function scopeEloquentQueryToTenant(Builder $query, ?Model $tenant): Builder
    {
        return TenantProjectScope::apply($query, $tenant, static::getTenantProjectPaths());
    }

    /**
     * Filament would otherwise try to associate the tenant on create, which needs a direct ownership relationship.
     */
    public static function observeTenancyModelCreation(Panel $panel): void
    {
        //
    }
}
