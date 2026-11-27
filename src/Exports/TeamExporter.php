<?php

namespace Statikbe\FilamentVoight\Exports;

use Filament\Actions\Exports\ExportColumn;
use Statikbe\FilamentVoight\Models\Team;

class TeamExporter extends VoightExporter
{
    protected static ?string $model = Team::class;

    public static function getColumns(): array
    {
        return [
            ExportColumn::make('name')
                ->label(voightTrans('models.team.fields.name')),
            ExportColumn::make('users_count')
                ->label(voightTrans('models.team.fields.users'))
                ->counts('users'),
            ExportColumn::make('projects_count')
                ->label(voightTrans('models.project.plural'))
                ->counts('projects'),
            ExportColumn::make('created_at')
                ->label(voightTrans('fields.created_at')),
        ];
    }
}
