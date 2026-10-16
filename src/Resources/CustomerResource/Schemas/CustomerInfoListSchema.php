<?php

namespace Statikbe\FilamentVoight\Resources\CustomerResource\Schemas;

use Filament\Infolists\Components\TextEntry;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Statikbe\FilamentVoight\Models\Customer;
use Statikbe\FilamentVoight\Resources\Components\InfolistStat;

class CustomerInfoListSchema
{
    public static function configure(Schema $schema): Schema
    {
        return $schema->components([
            InfolistStat::strip([
                InfolistStat::make(
                    TextEntry::make('stat_projects')
                        ->label(voightTrans('models.project.plural'))
                        ->icon(Heroicon::OutlinedSquares2x2)
                        ->state(fn (Customer $record): int => $record->projects()->count()),
                ),
                InfolistStat::make(
                    TextEntry::make('stat_environments')
                        ->label(voightTrans('models.environment.plural'))
                        ->icon(Heroicon::OutlinedServerStack)
                        ->state(fn (Customer $record): int => $record->environments()->count()),
                ),
                InfolistStat::openFindings(voightTrans('models.customer.fields.open_findings')),
            ]),
        ]);
    }
}
