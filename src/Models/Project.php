<?php

namespace Statikbe\FilamentVoight\Models;

use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasManyThrough;
use Illuminate\Support\Carbon;
use Laravel\Sanctum\HasApiTokens;
use Statikbe\FilamentVoight\Facades\FilamentVoight;
use Staudenmeir\EloquentHasManyDeep\HasManyDeep;
use Staudenmeir\EloquentHasManyDeep\HasRelationships;

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
    use HasRelationships;

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

    /**
     * @return HasManyThrough<AuditRun, Environment, $this>
     */
    public function auditRuns(): HasManyThrough
    {
        return $this->hasManyThrough(AuditRun::class, Environment::class);
    }

    /**
     * @return HasManyDeep<AuditFinding, $this>
     */
    public function findings(): HasManyDeep
    {
        return $this->hasManyDeep(
            AuditFinding::class,
            [Environment::class, AuditRun::class],
            ['project_id', 'environment_id', 'audit_run_id'],
            ['id', 'id', 'id'],
        );
    }

    /**
     * The projects a user may see over MCP. Null means the local (operator) server: no constraint.
     * Otherwise MCP admins see everything, and other users see the projects of their teams, or
     * whatever the host registered with FilamentVoight::resolveMcpVisibleProjectsUsing().
     * Projects without a team are only visible to admins.
     *
     * @param  Builder<static>  $query
     * @return Builder<static>
     */
    public function scopeVisibleTo(Builder $query, ?Authenticatable $user): Builder
    {
        if ($user === null || FilamentVoight::isMcpAdmin($user)) {
            return $query;
        }

        $resolver = FilamentVoight::getMcpVisibleProjectsResolver();

        if ($resolver !== null) {
            return $resolver($query, $user);
        }

        return $query->whereHas('team.users', fn (Builder $users): Builder => $users->whereKey($user->getAuthIdentifier()));
    }

    public function getRouteKeyName()
    {
        return 'project_code';
    }
}
