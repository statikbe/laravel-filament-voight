<?php

namespace Statikbe\FilamentVoight\Queries;

use Illuminate\Support\Collection;
use Statikbe\FilamentVoight\Enums\PackageType;
use Statikbe\FilamentVoight\Models\Environment;
use Statikbe\FilamentVoight\Models\EnvironmentPackage;

class DeduplicatedPackageSetQuery
{
    /**
     * @param  Collection<int, Environment>  $environments
     * @return Collection<int, array{type: PackageType, name: string, version: string}>
     */
    public function forEnvironments(Collection $environments): Collection
    {
        $environmentIds = $environments->pluck('id')->all();

        if ($environmentIds === []) {
            return collect();
        }

        return EnvironmentPackage::query()
            ->join('voight_packages', 'voight_packages.id', '=', 'voight_environment_packages.package_id')
            ->whereIn('voight_environment_packages.environment_id', $environmentIds)
            ->distinct()
            ->get([
                'voight_packages.type as type',
                'voight_packages.name as name',
                'voight_environment_packages.version as version',
            ])
            ->map(fn ($row): array => [
                'type' => $row->type instanceof PackageType ? $row->type : PackageType::from((string) $row->type),
                'name' => (string) $row->name,
                'version' => (string) $row->version,
            ])
            ->unique(fn (array $row): string => $row['type']->value . '|' . $row['name'] . '|' . $row['version'])
            ->values();
    }
}
