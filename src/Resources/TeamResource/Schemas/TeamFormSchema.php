<?php

namespace Statikbe\FilamentVoight\Resources\TeamResource\Schemas;

use Filament\Forms\Components\TextInput;
use Filament\Schemas\Schema;

class TeamFormSchema
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                TextInput::make('name')
                    ->label(voightTrans('models.team.fields.name'))
                    ->required()
                    ->maxLength(255),
            ]);
    }
}
