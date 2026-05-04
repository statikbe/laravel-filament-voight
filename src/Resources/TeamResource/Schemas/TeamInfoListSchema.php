<?php

namespace Statikbe\FilamentVoight\Resources\TeamResource\Schemas;

use Filament\Infolists\Components\TextEntry;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Support\Enums\TextSize;

class TeamInfoListSchema {

    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                Section::make()
                ->components([
                    TextEntry::make('name')
                    ->label(voightTrans('models.team.fields.name'))
                    ->inlineLabel()
                    ->size(TextSize::Medium)
                ])
                ->columnSpanFull()
            ]);
    }
}
