<?php

namespace Statikbe\FilamentVoight\Models;

use Filament\AvatarProviders\UiAvatarsProvider;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Foundation\Auth\User;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Storage;
use Statikbe\FilamentVoight\Facades\FilamentVoight;

/**
 * @property string $id
 * @property string $name
 * @property string|null $avatar_path
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
class Team extends Model
{
    use HasFactory;
    use HasUlids;

    protected $table = 'voight_teams';

    protected $guarded = [];

    protected static function booted(): void
    {
        static::updated(function (Team $team): void {
            if ($team->wasChanged('avatar_path')) {
                $team->deleteAvatarFile($team->getOriginal('avatar_path'));
            }
        });

        static::deleted(function (Team $team): void {
            $team->deleteAvatarFile($team->avatar_path);
        });
    }

    /**
     * @return BelongsToMany<User, $this>
     */
    public function users(): BelongsToMany
    {
        return $this->belongsToMany(User::class, 'voight_team_user');
    }

    /**
     * @return HasMany<Project, $this>
     */
    public function projects(): HasMany
    {
        return $this->hasMany(Project::class);
    }

    /**
     * The uploaded avatar, or generated initials when the team has none.
     */
    public function getAvatarUrl(): string
    {
        if ($this->avatar_path) {
            return Storage::disk(FilamentVoight::config()->getTeamAvatarDisk())->url($this->avatar_path);
        }

        return app(UiAvatarsProvider::class)->get($this);
    }

    private function deleteAvatarFile(?string $path): void
    {
        if ($path) {
            Storage::disk(FilamentVoight::config()->getTeamAvatarDisk())->delete($path);
        }
    }
}
