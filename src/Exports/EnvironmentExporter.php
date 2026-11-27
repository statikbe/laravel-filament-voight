<?php

namespace Statikbe\FilamentVoight\Exports;

use Filament\Actions\Exports\ExportColumn;
use Statikbe\FilamentVoight\Models\Environment;

class EnvironmentExporter extends VoightExporter
{
    protected static ?string $model = Environment::class;

    public static function getColumns(): array
    {
        return [
            ExportColumn::make('project.name')
                ->label(voightTrans('models.environment.fields.project')),
            ExportColumn::make('name')
                ->label(voightTrans('models.environment.fields.name')),
            ExportColumn::make('url')
                ->label(voightTrans('models.environment.fields.url')),
            ExportColumn::make('systemDetails.php_version')
                ->label(voightTrans('models.environment.fields.php_version')),
            ExportColumn::make('systemDetails.received_at')
                ->label(voightTrans('models.environment.fields.system_details_received_at')),
        ];
    }
}
