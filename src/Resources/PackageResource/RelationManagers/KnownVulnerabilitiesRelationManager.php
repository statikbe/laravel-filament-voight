<?php

namespace Statikbe\FilamentVoight\Resources\PackageResource\RelationManagers;

use Filament\Resources\RelationManagers\RelationManager;
use Filament\Support\Enums\FontFamily;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Statikbe\FilamentVoight\Enums\Severity;
use Statikbe\FilamentVoight\Enums\VulnerabilitySource;
use Statikbe\FilamentVoight\Resources\Components\FindingColumns;
use Statikbe\FilamentVoight\Resources\VulnerabilityResource;

class KnownVulnerabilitiesRelationManager extends RelationManager
{
    protected static string $relationship = 'vulnerablePackageRanges';

    protected static string | \BackedEnum | null $icon = Heroicon::OutlinedBookOpen;

    public static function getTitle(Model $ownerRecord, string $pageClass): string
    {
        return voightTrans('models.package.view.known_vulnerabilities_title');
    }

    public static function getBadge(Model $ownerRecord, string $pageClass): ?string
    {
        $count = $ownerRecord->vulnerablePackageRanges()->count();

        return $count > 0 ? (string) $count : null;
    }

    public static function getBadgeTooltip(Model $ownerRecord, string $pageClass): ?string
    {
        return voightTrans('hints.badges.package_known_vulnerabilities');
    }

    public static function getBadgeColor(Model $ownerRecord, string $pageClass): ?string
    {
        return 'gray';
    }

    public function table(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(fn (Builder $query) => $query->with('vulnerability'))
            ->recordUrl(fn ($record): ?string => $record->vulnerability_id
                ? VulnerabilityResource::getUrl('view', ['record' => $record->vulnerability_id])
                : null)
            ->striped()
            ->columns([
                FindingColumns::severity(),
                FindingColumns::cvss(),
                FindingColumns::vulnerabilityId(),
                TextColumn::make('vulnerability.source')
                    ->label(voightTrans('models.package.view.columns.source'))
                    ->formatStateUsing(fn (VulnerabilitySource $state): string => $state->label())
                    ->toggleable(isToggledHiddenByDefault: true),
                FindingColumns::summary(),
                TextColumn::make('affected_range')
                    ->label(voightTrans('models.package.view.columns.affected_range'))
                    ->fontFamily(FontFamily::Mono),
                FindingColumns::fixedVersion(),
                FindingColumns::since('vulnerability.published_at', voightTrans('models.package.view.columns.published'))
                    ->toggleable(),
                FindingColumns::since('vulnerability.modified_at', voightTrans('models.package.view.columns.modified'))
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->filters([
                SelectFilter::make('severity')
                    ->label(voightTrans('models.package.view.columns.severity'))
                    ->options(Severity::options())
                    ->query(function (Builder $query, array $data): Builder {
                        if (blank($data['value'] ?? null)) {
                            return $query;
                        }
                        [$min, $max] = Severity::from($data['value'])->scoreRange();

                        return $query->whereHas(
                            'vulnerability',
                            fn (Builder $q) => $q
                                ->where('vulnerability_score', '>=', $min)
                                ->where('vulnerability_score', '<=', $max),
                        );
                    }),
            ])
            ->defaultSort('vulnerability.vulnerability_score', 'desc')
            ->emptyStateIcon(Heroicon::OutlinedShieldCheck)
            ->emptyStateHeading(voightTrans('models.package.view.empty.no_known_vulnerabilities_heading'))
            ->emptyStateDescription(voightTrans('models.package.view.empty.no_known_vulnerabilities_description'));
    }
}
