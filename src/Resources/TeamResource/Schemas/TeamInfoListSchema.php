<?php

namespace Statikbe\FilamentVoight\Resources\TeamResource\Schemas;

use Filament\Infolists\Components\ImageEntry;
use Filament\Infolists\Components\TextEntry;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Support\Enums\TextSize;
use Statikbe\FilamentVoight\Models\Team;

class TeamInfoListSchema
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                Section::make()
                    ->components([
                        ImageEntry::make('avatar')
                            ->label(voightTrans('models.team.fields.avatar'))
                            ->state(fn (Team $record): string => $record->getAvatarUrl())
                            ->inlineLabel()
                            ->imageHeight(64)
                            ->circular(),
                        TextEntry::make('name')
                            ->label(voightTrans('models.team.fields.name'))
                            ->inlineLabel()
                            ->size(TextSize::Medium),
                    ])
                    ->columnSpanFull(),
            ]);
    }
}
