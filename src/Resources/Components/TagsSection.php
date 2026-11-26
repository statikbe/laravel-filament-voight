<?php

namespace Statikbe\FilamentVoight\Resources\Components;

use Filament\Schemas\Components\Section;
use Illuminate\Database\Eloquent\Model;

/**
 * The tag fields of a resource form, hidden when no tag type applies to the model.
 */
class TagsSection
{
    /**
     * @param  class-string<Model>  $modelClass
     */
    public static function make(string $modelClass): Section
    {
        $fields = TagFields::make($modelClass);

        return Section::make(voightTrans('models.tag.plural'))
            ->components($fields)
            ->visible($fields !== [])
            ->columnSpanFull();
    }
}
