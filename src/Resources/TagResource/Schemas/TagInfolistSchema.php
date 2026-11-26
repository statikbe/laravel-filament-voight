<?php

namespace Statikbe\FilamentVoight\Resources\TagResource\Schemas;

use Filament\Infolists\Components\TextEntry;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Illuminate\Support\Facades\DB;
use Statikbe\FilamentVoight\Models\Tag;
use Statikbe\FilamentVoight\Resources\TagTypeResource;

class TagInfolistSchema
{
    public static function configure(Schema $schema): Schema
    {
        return $schema->components([
            Section::make()
                ->columnSpanFull()
                ->columns(3)
                ->components([
                    TextEntry::make('name')
                        ->label(voightTrans('models.tag.fields.name'))
                        ->state(fn (Tag $record): string => $record->name),
                    TextEntry::make('tag_type')
                        ->label(voightTrans('models.tag_type.label'))
                        ->state(fn (Tag $record): string => $record->tagType->name)
                        ->url(fn (Tag $record): string => TagTypeResource::getUrl('edit', ['record' => $record->tagType])),
                    TextEntry::make('usage')
                        ->label(voightTrans('models.tag.fields.usage_count'))
                        ->state(fn (Tag $record): string => self::usage($record)),
                ]),
        ]);
    }

    /**
     * "12 (9 by rule)": all records carrying the tag, and how many only because of a rule.
     */
    public static function usage(Tag $tag): string
    {
        $counts = DB::table('voight_taggables')
            ->where('tag_id', $tag->getKey())
            ->selectRaw('count(*) as total, sum(case when is_manual then 0 else 1 end) as automatic')
            ->first();

        return self::formatUsage((int) ($counts->total ?? 0), (int) ($counts->automatic ?? 0));
    }

    public static function formatUsage(int $total, int $automatic): string
    {
        return $automatic > 0
            ? voightTrans('tags.rules.usage', ['total' => $total, 'automatic' => $automatic])
            : (string) $total;
    }
}
