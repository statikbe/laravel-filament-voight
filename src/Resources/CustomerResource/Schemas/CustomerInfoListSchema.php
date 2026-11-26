<?php

namespace Statikbe\FilamentVoight\Resources\CustomerResource\Schemas;

use Filament\Infolists\Components\TextEntry;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Support\Enums\TextSize;
use Statikbe\FilamentVoight\Resources\Components\TagsColumn;

class CustomerInfoListSchema
{
    public static function configure(Schema $schema): Schema
    {
        return $schema->components([
            Section::make()->components([
                TextEntry::make('name')
                    ->label(voightTrans('models.customer.fields.name'))
                    ->size(TextSize::Medium)
                    ->inlineLabel(),
                TagsColumn::entry()
                    ->inlineLabel(),
            ])
                ->columnSpanFull(),
        ]);
    }
}
