<?php

namespace Statikbe\FilamentVoight\Models;

use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

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
}
