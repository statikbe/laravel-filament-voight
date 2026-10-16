<?php

namespace Statikbe\FilamentVoight\Models;

use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasManyThrough;
use Illuminate\Support\Carbon;
use Spatie\Sluggable\HasSlug;
use Spatie\Sluggable\SlugOptions;
use Statikbe\FilamentVoight\Enums\Severity;
use Staudenmeir\EloquentHasManyDeep\HasManyDeep;
use Staudenmeir\EloquentHasManyDeep\HasRelationships;

/**
 * @property string $id
 * @property string $name
 * @property string $slug
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
class Customer extends Model
{
    use HasFactory;
    use HasRelationships;
    use HasSlug;
    use HasUlids;

    protected $table = 'voight_customers';

    protected $guarded = [];

    public function getSlugOptions(): SlugOptions
    {
        return SlugOptions::create()
            ->generateSlugsFrom('name')
            ->saveSlugsTo('slug');
    }

    /**
     * @return HasMany<Project, $this>
     */
    public function projects(): HasMany
    {
        return $this->hasMany(Project::class);
    }

    /**
     * @return HasManyDeep<AuditFinding, $this>
     */
    public function findings(): HasManyDeep
    {
        return $this->hasManyDeep(
            AuditFinding::class,
            [Project::class, Environment::class, AuditRun::class],
            ['customer_id', 'project_id', 'environment_id', 'audit_run_id'],
            ['id', 'id', 'id', 'id'],
        );
    }

    /**
     * @return HasManyThrough<Environment, Project, $this>
     */
    public function environments(): HasManyThrough
    {
        return $this->hasManyThrough(Environment::class, Project::class);
    }

    /**
     * The open findings across all projects with the worst severity among them. One query.
     *
     * @return array{count: int, severity: Severity|null}
     */
    public function openFindingsSummary(): array
    {
        return AuditFinding::openSummary($this->findings());
    }
}
