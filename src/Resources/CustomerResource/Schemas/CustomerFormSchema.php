<?php

namespace Statikbe\FilamentVoight\Resources\CustomerResource\Schemas;

use Filament\Forms\Components\TextInput;
use Filament\Schemas\Components\Component;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Statikbe\FilamentVoight\Models\Customer;
use Statikbe\FilamentVoight\Resources\Components\TagsSection;

class CustomerFormSchema
{
    /**
     * @return array<Component>
     */
    public static function fields(): array
    {
        return [
            TextInput::make('name')
                ->label(voightTrans('models.customer.fields.name'))
                ->required()
                ->maxLength(255),
        ];
    }

    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                Section::make(self::fields()),
                TagsSection::make(Customer::class),
            ]);
    }
}
