<?php

namespace Statikbe\FilamentVoight\Exports;

use Filament\Actions\Exports\ExportColumn;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;
use Statikbe\FilamentVoight\Enums\PackageType;
use Statikbe\FilamentVoight\Models\Package;

class PackageExporter extends VoightExporter
{
    protected static ?string $model = Package::class;

    public static function getColumns(): array
    {
        return [
            ExportColumn::make('name')
                ->label(voightTrans('models.package.fields.name')),
            ExportColumn::make('type')
                ->label(voightTrans('models.package.fields.type'))
                ->formatStateUsing(fn (PackageType $state): string => $state->label()),
            ExportColumn::make('latest_version')
                ->label(voightTrans('models.package.fields.latest_version')),
            ExportColumn::make('latest_version_updated_at')
                ->label(voightTrans('models.package.fields.latest_version_updated_at')),
            ExportColumn::make('environment_packages_count')
                ->label(voightTrans('models.package.fields.installations'))
                ->counts(['environmentPackages' => fn (Builder $query) => $query->select(DB::raw('count(distinct environment_id)'))]),
        ];
    }
}
