<?php

namespace Statikbe\FilamentVoight\Models;

use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Spatie\Tags\Tag as SpatieTag;
use Statikbe\FilamentVoight\Database\Factories\TagFactory;
use Statikbe\FilamentVoight\Facades\FilamentVoight;

/**
 * A Spatie tag on Voight's own table. Its `type` is the key of the TagType it belongs to.
 *
 * @property string $id
 * @property string $type
 * @property-read TagType $tagType
 */
class Tag extends SpatieTag
{
    use HasUlids;

    protected $table = 'voight_tags';

    protected static function newFactory(): TagFactory
    {
        return TagFactory::new();
    }

    /**
     * @return BelongsTo<TagType, $this>
     */
    public function tagType(): BelongsTo
    {
        return $this->belongsTo(FilamentVoight::config()->getTagTypeModel(), 'type', 'key');
    }
}
