<?php

namespace Statikbe\FilamentVoight\Models\Concerns;

use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Relations\MorphPivot;
use Illuminate\Support\Facades\DB;
use Spatie\Tags\HasTags;
use Statikbe\FilamentVoight\Facades\FilamentVoight;
use Statikbe\FilamentVoight\Jobs\RunTagRulesJob;
use Statikbe\FilamentVoight\Models\TagType;

/**
 * Spatie tags on Voight's own tables and fixed settings, so a host app's own spatie-tags setup (tables, config) is never touched.
 */
trait HasVoightTags
{
    use HasTags;

    /**
     * Any change to a taggable record can change what auto-tag rules match.
     */
    public static function bootHasVoightTags(): void
    {
        static::saved(fn () => RunTagRulesJob::dispatchIfRulesExist());
        static::deleted(function (self $record): void {
            DB::table('voight_tag_rule_matches')
                ->where('taggable_type', $record->getMorphClass())
                ->where('taggable_id', (string) $record->getKey())
                ->delete();

            RunTagRulesJob::dispatchIfRulesExist();
        });
    }

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
