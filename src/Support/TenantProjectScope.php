<?php

namespace Statikbe\FilamentVoight\Support;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

class TenantProjectScope
{
    /**
     * Restricts a query to rows reachable from a project that belongs to the tenant.
     * Each path is a relationship chain ending at a project; a row matches when any path does.
     * Without a tenant (panel without tenancy) the query is returned untouched.
     *
     * @template TModel of Model
     *
     * @param  Builder<TModel>  $query
     * @param  array<int, string>  $projectPaths
     * @return Builder<TModel>
     */
    public static function apply(Builder $query, ?Model $tenant, array $projectPaths): Builder
    {
        if ($tenant === null) {
            return $query;
        }

        return $query->where(function (Builder $query) use ($tenant, $projectPaths): void {
            foreach ($projectPaths as $path) {
                $query->orWhereHas($path, fn (Builder $projects): Builder => $projects->where(
                    $projects->qualifyColumn('team_id'),
                    $tenant->getKey(),
                ));
            }
        });
    }
}
