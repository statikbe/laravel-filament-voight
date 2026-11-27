<?php

namespace Statikbe\FilamentVoight\Exports;

use Filament\Actions\Exports\ExportColumn;
use Statikbe\FilamentVoight\Enums\Framework;
use Statikbe\FilamentVoight\Models\Project;
use Statikbe\FilamentVoight\Services\ProjectStackService;

class ProjectExporter extends VoightExporter
{
    protected static ?string $model = Project::class;

    public static function getColumns(): array
    {
        return [
            ExportColumn::make('project_code')
                ->label(voightTrans('models.project.fields.project_code')),
            ExportColumn::make('name')
                ->label(voightTrans('models.project.fields.name')),
            ExportColumn::make('customer.name')
                ->label(voightTrans('models.project.fields.customer')),
            ExportColumn::make('team.name')
                ->label(voightTrans('models.project.fields.team')),
            ExportColumn::make('environments_count')
                ->label(voightTrans('models.environment.plural'))
                ->counts('environments'),
            ...array_map(
                fn (Framework $framework): ExportColumn => ExportColumn::make(strtolower($framework->name) . '_version')
                    ->label($framework->label())
                    ->state(fn (Project $record): ?string => ProjectStackService::version($record, $framework)),
                Framework::cases(),
            ),
            ExportColumn::make('php_version')
                ->label(voightTrans('models.project.fields.php_version'))
                ->state(fn (Project $record): ?string => ProjectStackService::phpVersion($record)),
            ExportColumn::make('upgrade_status')
                ->label(voightTrans('models.project.fields.upgrade_status'))
                ->state(fn (Project $record): string => ProjectStackService::upgradeStatus($record)->label()),
            ExportColumn::make('is_muted')
                ->label(voightTrans('models.project.fields.is_muted'))
                ->formatStateUsing(fn (bool $state): int => (int) $state),
            ExportColumn::make('created_at')
                ->label(voightTrans('fields.created_at')),
        ];
    }
}
