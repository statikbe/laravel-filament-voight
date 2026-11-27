<?php

namespace Statikbe\FilamentVoight\Exports;

use Filament\Actions\Exports\Exporter;
use Filament\Actions\Exports\Models\Export;
use Illuminate\Support\Number;

abstract class VoightExporter extends Exporter
{
    public static function getCompletedNotificationBody(Export $export): string
    {
        $body = voightTransChoice('exports.completed', $export->successful_rows, [
            'count' => Number::format($export->successful_rows),
        ]);

        if ($failedRowsCount = $export->getFailedRowsCount()) {
            $body .= ' ' . voightTransChoice('exports.failed', $failedRowsCount, [
                'count' => Number::format($failedRowsCount),
            ]);
        }

        return $body;
    }
}
