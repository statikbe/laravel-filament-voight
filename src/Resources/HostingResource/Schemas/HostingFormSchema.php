<?php

namespace Statikbe\FilamentVoight\Resources\HostingResource\Schemas;

use Closure;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Schema;
use Statikbe\FilamentVoight\Models\HostingTag;

class HostingFormSchema
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                TextInput::make('name')
                    ->label(voightTrans('models.hosting.fields.name'))
                    ->helperText(voightTrans('models.hosting.fields.name_help'))
                    ->required()
                    ->maxLength(255)
                    ->rule(fn (?HostingTag $record): Closure => function (string $attribute, mixed $value, Closure $fail) use ($record): void {
                        $taken = HostingTag::query()
                            ->named((string) $value)
                            ->when($record, fn ($query) => $query->whereKeyNot($record->getKey()))
                            ->exists();

                        if ($taken) {
                            $fail(voightTrans('models.hosting.name_taken'));
                        }
                    }),
            ])
            ->columns(1);
    }
}
