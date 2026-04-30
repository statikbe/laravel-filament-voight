<?php

namespace Statikbe\FilamentVoight\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;
use Laravel\Sanctum\HasApiTokens;

/**
 * @property int $id
 * @property string $project_code
 * @property string|null $name
 * @property string|null $description
 * @property string|null $repo_url
 * @property string|null $customer_id
 * @property string|null $team_id
 * @property bool $is_muted
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
class Project extends Model
{
    use HasApiTokens;
    use HasFactory;

    const DEFAULT_API_TOKEN_NAME = 'ci-pipeline';

    protected $table = 'voight_projects';

    protected $guarded = [];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'is_muted' => 'boolean',
        ];
    }

    /**
     * @return BelongsTo<Customer, $this>
     */
    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }

    /**
     * @return BelongsTo<Team, $this>
     */
    public function team(): BelongsTo
    {
        return $this->belongsTo(Team::class);
    }

    /**
     * @return HasMany<Environment, $this>
     */
    public function environments(): HasMany
    {
        return $this->hasMany(Environment::class);
    }

    /**
     * @return HasMany<AlertSetting, $this>
     */
    public function alertSettings(): HasMany
    {
        return $this->hasMany(AlertSetting::class);
    }

    public function getRouteKeyName()
    {
        return 'project_code';
    }
}
