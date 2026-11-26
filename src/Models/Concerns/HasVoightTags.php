<?php

namespace Statikbe\FilamentVoight\Models\Concerns;

use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Relations\MorphPivot;
use Spatie\Tags\HasTags;
use Statikbe\FilamentVoight\Facades\FilamentVoight;
use Statikbe\FilamentVoight\Models\TagType;

/**
 * Spatie tags on Voight's own tables and fixed settings, so a host app's own spatie-tags setup (tables, config) is never touched.
 */
trait HasVoightTags
{
    use HasTags;

    public static function getTagClassName(): string
    {
        return FilamentVoight::config()->getTagModel();
    }

    public function getTaggableTableName(): string
    {
        return 'voight_taggables';
    }

    public function getTaggableMorphName(): string
    {
        return 'taggable';
    }

    public function getPivotModelClassName(): string
    {
        return MorphPivot::class;
    }

    /**
     * The tag types that can be assigned to this model.
     *
     * @return Collection<int, TagType>
     */
    public function voightTagTypes(): Collection
    {
        return FilamentVoight::config()->getTagTypeModel()::query()->forModel($this)->ordered()->get();
    }
}
