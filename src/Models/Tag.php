<?php

namespace Statikbe\FilamentVoight\Models;

use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;
use Spatie\Tags\Tag as SpatieTag;
use Statikbe\FilamentVoight\Database\Factories\TagFactory;
use Statikbe\FilamentVoight\Facades\FilamentVoight;

/**
 * A Spatie tag on Voight's own table. Its `type` is the key of the TagType it belongs to.
 *
 * @property string $id
 * @property string $name
 * @property string $slug
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
     * Always a plain slug, whatever `tags.slugger` the host configured: MCP matches tags on it.
     */
    protected function generateSlug(string $locale): string
    {
        return Str::slug($this->getTranslation('name', $locale));
    }

    /**
     * @return BelongsTo<TagType, $this>
     */
    public function tagType(): BelongsTo
    {
        return $this->belongsTo(FilamentVoight::config()->getTagTypeModel(), 'type', 'key');
    }
}
