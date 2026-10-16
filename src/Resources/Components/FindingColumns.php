<?php

namespace Statikbe\FilamentVoight\Resources\Components;

use Filament\Support\Enums\FontFamily;
use Filament\Support\Enums\FontWeight;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Statikbe\FilamentVoight\Enums\Severity;

/**
 * Column definitions shared by every table listing audit findings or known
 * vulnerabilities. The rows must expose a `vulnerability` relation, or pass an
 * empty `$prefix` when the rows are the vulnerabilities themselves.
 */
class FindingColumns
{
    public static function severity(string $prefix = 'vulnerability.'): TextColumn
    {
        return TextColumn::make($prefix . 'severity')
            ->label(voightTrans('models.package.view.columns.severity'))
            ->badge()
            ->formatStateUsing(fn (Severity $state): string => $state->label())
            ->color(fn (Severity $state): string => $state->color())
            ->icon(fn (Severity $state): Heroicon => $state->icon());
    }

    public static function cvss(string $prefix = 'vulnerability.'): TextColumn
    {
        return TextColumn::make($prefix . 'vulnerability_score')
            ->label(voightTrans('models.package.view.columns.cvss'))
            ->numeric(decimalPlaces: 1)
            ->color(fn (mixed $state): string => Severity::fromScore((float) $state)->color())
            ->alignEnd()
            ->sortable();
    }

    public static function vulnerabilityId(string $prefix = 'vulnerability.'): TextColumn
    {
        return TextColumn::make($prefix . 'source_id')
            ->label(voightTrans('models.package.view.columns.source_id'))
            ->fontFamily(FontFamily::Mono)
            ->weight(FontWeight::Medium)
            ->copyable()
            ->searchable();
    }

    public static function summary(string $prefix = 'vulnerability.'): TextColumn
    {
        return TextColumn::make($prefix . 'summary')
            ->label(voightTrans('models.package.view.columns.summary'))
            ->limit(50)
            ->tooltip(fn (mixed $record): ?string => data_get($record, $prefix . 'summary'))
            ->searchable();
    }

    public static function installedVersion(string $name = 'installed_version'): TextColumn
    {
        return TextColumn::make($name)
            ->label(voightTrans('models.package.view.columns.installed_version'))
            ->fontFamily(FontFamily::Mono);
    }

    public static function fixedVersion(): TextColumn
    {
        return TextColumn::make('fixed_version')
            ->label(voightTrans('models.package.view.columns.fixed_version'))
            ->fontFamily(FontFamily::Mono)
            ->color('success')
            ->placeholder('—');
    }

    /**
     * A date shown relative to now, with the exact timestamp as tooltip.
     */
    public static function since(string $name, string $label): TextColumn
    {
        return TextColumn::make($name)
            ->label($label)
            ->since()
            ->dateTimeTooltip()
            ->placeholder('—')
            ->sortable();
    }
}
