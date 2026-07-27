<?php

namespace Statikbe\FilamentVoight\Models;

use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Statikbe\FilamentVoight\Enums\PackageType;

/**
 * @property string $id
 * @property string $environment_id
 * @property string $package_id
 * @property string $version
 * @property bool $is_direct
 * @property bool $is_dev
 * @property string|null $parent_package_id
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
class EnvironmentPackage extends Model
{
    use HasFactory;
    use HasUlids;

    protected $table = 'voight_environment_packages';

    protected $guarded = [];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'is_direct' => 'boolean',
            'is_dev' => 'boolean',
        ];
    }

    /**
     * @return BelongsTo<Environment, $this>
     */
    public function environment(): BelongsTo
    {
        return $this->belongsTo(Environment::class);
    }

    /**
     * @return BelongsTo<Package, $this>
     */
    public function package(): BelongsTo
    {
        return $this->belongsTo(Package::class);
    }

    /**
     * @return BelongsTo<Package, $this>
     */
    public function parentPackage(): BelongsTo
    {
        return $this->belongsTo(Package::class, 'parent_package_id');
    }

    /**
     * Distinct (type, name, version) triples across the given environments.
     *
     * Returns raw rows (not models) via the query builder, so the result is a
     * deduplicated package set regardless of which environments share versions.
     *
     * @param  Collection<int, Environment>  $environments
     * @return Collection<int, array{type: PackageType, name: string, version: string}>
     */
    public static function distinctPackageSetForEnvironments(Collection $environments): Collection
    {
        $environmentIds = $environments->pluck('id')->all();

        if ($environmentIds === []) {
            return collect();
        }

        return static::query()
            ->join('voight_packages', 'voight_packages.id', '=', 'voight_environment_packages.package_id')
            ->whereIn('voight_environment_packages.environment_id', $environmentIds)
            ->distinct()
            ->toBase()
            ->get([
                'voight_packages.type as type',
                'voight_packages.name as name',
                'voight_environment_packages.version as version',
            ])
            ->map(fn (object $row): array => [
                'type' => PackageType::from((string) $row->type),
                'name' => (string) $row->name,
                'version' => (string) $row->version,
            ])
            ->unique(fn (array $row): string => $row['type']->value . '|' . $row['name'] . '|' . $row['version'])
            ->values();
    }
}
