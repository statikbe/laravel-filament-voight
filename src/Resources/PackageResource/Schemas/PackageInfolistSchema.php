<?php

namespace Statikbe\FilamentVoight\Resources\PackageResource\Schemas;

use Filament\Infolists\Components\TextEntry;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Support\Enums\FontFamily;
use Filament\Support\Enums\IconPosition;
use Filament\Support\Icons\Heroicon;
use Statikbe\FilamentVoight\Enums\PackageType;
use Statikbe\FilamentVoight\Models\Package;
use Statikbe\FilamentVoight\Resources\Components\InfolistStat;
use Statikbe\FilamentVoight\Resources\PackageResource\Actions\OpenPackageWebsiteAction;

class PackageInfolistSchema
{
    public static function configure(Schema $schema): Schema
    {
        return $schema->components([
            InfolistStat::strip([
                InfolistStat::make(
                    TextEntry::make('type')
                        ->label(voightTrans('models.package.fields.type'))
                        ->icon(Heroicon::OutlinedCube)
                        ->badge()
                        ->formatStateUsing(fn (PackageType $state): string => $state->label()),
                ),
                InfolistStat::make(
                    TextEntry::make('stat_installed')
                        ->label(voightTrans('models.package.view.header.installed_in'))
                        ->hintIcon(Heroicon::OutlinedInformationCircle, tooltip: voightTrans('hints.installed_summary'))
                        ->icon(Heroicon::OutlinedServerStack)
                        ->state(fn (Package $record): string => self::installedSummary($record)),
                ),
                InfolistStat::make(
                    TextEntry::make('latest_version')
                        ->label(voightTrans('models.package.fields.latest_version'))
                        ->icon(Heroicon::OutlinedTag)
                        ->fontFamily(FontFamily::Mono)
                        ->placeholder('—')
                        ->belowContent(fn (Package $record): ?string => $record->latest_version_updated_at?->diffForHumans()),
                ),
                InfolistStat::openFindings(voightTrans('models.package.view.header.active_findings')),
            ]),
            Section::make(voightTrans('models.package.sections.details'))
                ->icon(Heroicon::OutlinedCube)
                ->columnSpanFull()
                ->components([
                    TextEntry::make('name')
                        ->label(voightTrans('models.package.fields.name'))
                        ->fontFamily(FontFamily::Mono)
                        ->copyable(),
                    TextEntry::make('website')
                        ->label(voightTrans('models.package.fields.website'))
                        ->state(fn (Package $record): string => OpenPackageWebsiteAction::urlFor($record))
                        ->url(fn (string $state): string => $state, shouldOpenInNewTab: true)
                        ->icon(Heroicon::OutlinedArrowTopRightOnSquare)
                        ->iconPosition(IconPosition::After)
                        ->extraAttributes(['class' => 'underline']),
                ]),
        ]);
    }

    private static function installedSummary(Package $package): string
    {
        return voightTransChoice('models.package.view.installed_environments', $package->environmentPackages()->distinct()->count('environment_id'))
            . ' · '
            . voightTransChoice('models.package.view.installed_projects', $package->projects()->count());
    }
}
