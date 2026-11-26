<?php

namespace Statikbe\FilamentVoight\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;
use LogicException;
use Spatie\EloquentSortable\Sortable;
use Spatie\EloquentSortable\SortableTrait;
use Spatie\Translatable\HasTranslations;
use Statikbe\FilamentVoight\Facades\FilamentVoight;

/**
 * A curated classification (e.g. "Security level") whose tags can be assigned to the models it applies to.
 *
 * @property string $id
 * @property string $key Slug of the name at creation; immutable, stored as `type` on its tags.
 * @property string $name
 * @property string|null $description
 * @property bool $is_single A record holds at most one tag of a single-value type.
 * @property list<string>|null $taggable_types Morph aliases it applies to; null means all taggable models.
 * @property int|null $order_column
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
class TagType extends Model implements Sortable
{
    use HasFactory;
    use HasTranslations;
    use HasUlids;
    use SortableTrait;

    protected $table = 'voight_tag_types';

    protected $guarded = [];

    /** @var list<string> */
    public array $translatable = ['name', 'description'];

    protected function casts(): array
    {
        return [
            'is_single' => 'boolean',
            'taggable_types' => 'array',
        ];
    }

    protected static function booted(): void
    {
        static::creating(function (self $type): void {
            $type->key ??= Str::slug($type->getTranslation('name', app()->getLocale()));
        });

        static::updating(function (self $type): void {
            if ($type->isDirty('key')) {
                throw new LogicException('A tag type key cannot change: its tags and MCP clients refer to it.');
            }
        });
    }

    /**
     * @return HasMany<Tag, $this>
     */
    public function tags(): HasMany
    {
        return $this->hasMany(FilamentVoight::config()->getTagModel(), 'type', 'key')->ordered();
    }

    /**
     * @param  Model|class-string<Model>|string  $model  A model, its class, or its morph alias.
     */
    public function appliesTo(Model | string $model): bool
    {
        return $this->taggable_types === null
            || $this->taggable_types === []
            || in_array(self::morphAlias($model), $this->taggable_types, true);
    }

    /**
     * @param  Builder<self>  $query
     * @param  Model|class-string<Model>|string  $model
     * @return Builder<self>
     */
    public function scopeForModel(Builder $query, Model | string $model): Builder
    {
        $alias = self::morphAlias($model);

        return $query->where(fn (Builder $query) => $query
            ->whereNull('taggable_types')
            ->orWhereJsonLength('taggable_types', 0)
            ->orWhereJsonContains('taggable_types', $alias));
    }

    /**
     * @param  Model|class-string<Model>|string  $model
     */
    public static function morphAlias(Model | string $model): string
    {
        if ($model instanceof Model) {
            return $model->getMorphClass();
        }

        return class_exists($model) ? Relation::getMorphAlias($model) : $model;
    }
}
