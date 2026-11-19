<?php

namespace Statikbe\FilamentVoight\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * Where an environment is hosted, e.g. Combell, Level27 or the customer's own server.
 *
 * @property string $id
 * @property string $name
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
class Hosting extends Model
{
    use HasFactory;
    use HasUlids;

    protected $table = 'voight_hostings';

    protected $guarded = [];

    /**
     * @return HasMany<Environment, $this>
     */
    public function environments(): HasMany
    {
        return $this->hasMany(Environment::class);
    }

    /**
     * Names compare case-insensitively and ignore surrounding whitespace, whatever the database collation.
     *
     * @param  Builder<Hosting>  $query
     */
    public function scopeNamed(Builder $query, string $name): void
    {
        $query->whereRaw('LOWER(name) = ?', [mb_strtolower(trim($name))]);
    }

    /**
     * The hosting with this name, created when there is none yet.
     */
    public static function findOrCreateByName(string $name): self
    {
        return self::query()->named($name)->first()
            ?? self::query()->create(['name' => trim($name)]);
    }
}
