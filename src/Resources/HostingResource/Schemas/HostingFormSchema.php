<?php

namespace Statikbe\FilamentVoight\Resources\HostingResource\Schemas;

use Filament\Forms\Components\TextInput;
use Filament\Schemas\Components\Component;
use Filament\Schemas\Schema;

class HostingFormSchema
{
    /**
     * @return array<Component>
     */
    public static function fields(): array
    {
        return [
            TextInput::make('name')
                ->label(voightTrans('models.hosting.fields.name'))
                ->helperText(voightTrans('models.hosting.fields.name_help'))
                ->required()
                ->maxLength(255)
                ->unique(ignoreRecord: true),
        ];
    }

    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components(self::fields())
            ->columns(1);
    }
}
