<?php

namespace Statikbe\FilamentVoight\Models;

use Illuminate\Database\Eloquent\Relations\Pivot;
use Statikbe\FilamentVoight\Enums\DependencyKind;

/**
 * An edge between two installed packages of the same environment.
 *
 * @property string $parent_id
 * @property string $child_id
 * @property string|null $constraint
 * @property DependencyKind $kind
 */
class EnvironmentPackageDependency extends Pivot
{
    protected $table = 'voight_environment_package_dependencies';

    public $timestamps = false;

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'kind' => DependencyKind::class,
        ];
    }
}
