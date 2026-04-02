<?php

namespace Statikbe\FilamentVoight\Models;

use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;
use Statikbe\FilamentVoight\Enums\DependencySyncStatus;

/**
 * @property string $id
 * @property string $environment_id
 * @property string $lockfile_hash
 * @property array<string>|null $lockfile_paths
 * @property int $package_count
 * @property DependencySyncStatus $status
 * @property string|null $error_message
 * @property Carbon|null $synced_at
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
class DependencySync extends Model
{
    use HasFactory;
    use HasUlids;

    protected $table = 'voight_dependency_syncs';

    protected $guarded = [];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'status' => DependencySyncStatus::class,
            'lockfile_paths' => 'array',
            'synced_at' => 'datetime',
        ];
    }

    /**
     * @return BelongsTo<Environment, $this>
     */
    public function environment(): BelongsTo
    {
        return $this->belongsTo(Environment::class);
    }
}
