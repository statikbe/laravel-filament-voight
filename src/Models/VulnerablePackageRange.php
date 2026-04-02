<?php

namespace Statikbe\FilamentVoight\Models;

use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * @property string $id
 * @property string $vulnerability_id
 * @property string $package_id
 * @property string $affected_range
 * @property string|null $fixed_version
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
class VulnerablePackageRange extends Model
{
    use HasFactory;
    use HasUlids;

    protected $table = 'voight_vulnerable_package_ranges';

    protected $guarded = [];

    /**
     * @return BelongsTo<Vulnerability, $this>
     */
    public function vulnerability(): BelongsTo
    {
        return $this->belongsTo(Vulnerability::class);
    }

    /**
     * @return BelongsTo<Package, $this>
     */
    public function package(): BelongsTo
    {
        return $this->belongsTo(Package::class);
    }
}
