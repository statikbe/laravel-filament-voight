<?php

namespace Statikbe\FilamentVoight\Resources\TeamResource\Schemas;

use Filament\Infolists\Components\TextEntry;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Statikbe\FilamentVoight\Models\Team;
use Statikbe\FilamentVoight\Resources\Components\InfolistStat;

class TeamInfoListSchema
{
    public static function configure(Schema $schema): Schema
    {
        return $schema->components([
            InfolistStat::strip([
                InfolistStat::make(
                    TextEntry::make('stat_members')
                        ->label(voightTrans('models.team.fields.members'))
                        ->icon(Heroicon::OutlinedUsers)
                        ->state(fn (Team $record): int => $record->users()->count()),
                ),
                InfolistStat::make(
                    TextEntry::make('stat_projects')
                        ->label(voightTrans('models.project.plural'))
                        ->icon(Heroicon::OutlinedSquares2x2)
                        ->state(fn (Team $record): int => $record->projects()->count()),
                ),
                InfolistStat::openFindings(voightTrans('models.team.fields.open_findings')),
            ]),
        ]);
    }
}
