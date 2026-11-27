<?php

namespace Statikbe\FilamentVoight\Exports;

use Filament\Actions\Exports\ExportColumn;
use Statikbe\FilamentVoight\Models\Customer;

class CustomerExporter extends VoightExporter
{
    protected static ?string $model = Customer::class;

    public static function getColumns(): array
    {
        return [
            ExportColumn::make('name')
                ->label(voightTrans('models.customer.fields.name')),
            ExportColumn::make('slug')
                ->label(voightTrans('models.customer.fields.slug')),
            ExportColumn::make('projects_count')
                ->label(voightTrans('models.project.plural'))
                ->counts('projects'),
            ExportColumn::make('created_at')
                ->label(voightTrans('fields.created_at')),
        ];
    }
}
