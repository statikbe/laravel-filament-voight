<?php

namespace Statikbe\FilamentVoight\Models;

use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;
use Statikbe\FilamentVoight\Models\Concerns\HasVersionKeys;
use Statikbe\FilamentVoight\Support\VersionKey;

/**
 * @property string $id
 * @property string $environment_id
 * @property string|null $php_version
 * @property string|null $php_version_sort
 * @property array<string, mixed> $payload
 * @property Carbon|null $collected_at
 * @property Carbon $received_at
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
class EnvironmentSystemDetail extends Model
{
    use HasFactory;
    use HasUlids;
    use HasVersionKeys;

    protected $table = 'voight_environment_system_details';

    protected $guarded = [];

    /**
     * @return array<string, string>
     */
    protected function versionKeyColumns(): array
    {
        return ['php_version' => 'php_version_sort'];
    }

    protected function versionKey(string $version): ?string
    {
        return VersionKey::forPhp($version);
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'payload' => 'array',
            'collected_at' => 'datetime',
            'received_at' => 'datetime',
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
