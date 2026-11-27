<?php

namespace Statikbe\FilamentVoight\Resources\TagTypeResource\Schemas;

use Filament\Actions\EditAction;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Statikbe\FilamentVoight\Models\TagType;

class TagTypeTableSchema
{
    public static function configure(Table $table): Table
    {
        return $table
            ->reorderable('order_column')
            ->defaultSort('order_column')
            ->stackedOnMobile()
            ->columns([
                TextColumn::make('name')
                    ->label(voightTrans('models.tag_type.fields.name'))
                    ->description(fn (TagType $record): ?string => $record->description),
                TextColumn::make('key')
                    ->label(voightTrans('models.tag_type.fields.key'))
                    ->fontFamily('mono')
                    ->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('applies_to')
                    ->label(voightTrans('models.tag_type.fields.taggable_types'))
                    ->state(fn (TagType $record): array => blank($record->taggable_types)
                        ? [voightTrans('models.tag_type.all_models')]
                        : array_map(TagType::modelLabel(...), $record->taggable_types))
                    ->badge(),
                IconColumn::make('is_single')
                    ->label(voightTrans('models.tag_type.fields.is_single'))
                    ->boolean(),
                TextColumn::make('tags_count')
                    ->label(voightTrans('models.tag.plural'))
                    ->counts('tags'),
            ])
            ->recordActions([
                EditAction::make(),
            ]);
    }
}
