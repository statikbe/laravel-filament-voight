<?php

namespace Statikbe\FilamentVoight\Resources\PackageResource\RelationManagers;

use Filament\Forms\Components\DatePicker;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\Filter;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Filters\TernaryFilter;
use Filament\Tables\Grouping\Group;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Statikbe\FilamentVoight\Enums\PackageType;
use Statikbe\FilamentVoight\Facades\FilamentVoight;
use Statikbe\FilamentVoight\Models\AuditFinding;
use Statikbe\FilamentVoight\Models\Package;
use Statikbe\FilamentVoight\Resources\Components\EnvironmentNameColumn;
use Statikbe\FilamentVoight\Resources\Components\FindingColumns;
use Statikbe\FilamentVoight\Resources\PackageResource\Actions\WhyInstalledAction;
use Statikbe\FilamentVoight\Resources\VulnerabilityResource;

class ActiveFindingsRelationManager extends RelationManager
{
    protected static string $relationship = 'findings';

    protected static string | \BackedEnum | null $icon = Heroicon::OutlinedShieldExclamation;

    public static function getTitle(Model $ownerRecord, string $pageClass): string
    {
        return voightTrans('models.package.view.active_findings_title');
    }

    public static function getBadge(Model $ownerRecord, string $pageClass): ?string
    {
        /** @var Package $ownerRecord */
        $count = $ownerRecord->openFindingsSummary()['count'];

        return $count > 0 ? (string) $count : null;
    }

    public static function getBadgeTooltip(Model $ownerRecord, string $pageClass): ?string
    {
        return voightTrans('hints.badges.package_active_findings');
    }

    public static function getBadgeColor(Model $ownerRecord, string $pageClass): ?string
    {
        /** @var Package $ownerRecord */
        return $ownerRecord->openFindingsSummary()['severity']?->color();
    }

    public function table(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(fn (Builder $query) => $query->with([
                'vulnerability',
                'auditRun.environment.project',
            ]))
            ->recordUrl(fn (AuditFinding $record): string => VulnerabilityResource::getUrl('view', ['record' => $record->vulnerability_id]))
            ->striped()
            ->columns([
                FindingColumns::severity(),
                FindingColumns::cvss(),
                FindingColumns::vulnerabilityId(),
                FindingColumns::summary(),
                FindingColumns::installedVersion(),
                FindingColumns::fixedVersion(),
                TextColumn::make('auditRun.environment.project.name')
                    ->label(voightTrans('models.package.view.columns.project'))
                    ->sortable()
                    ->searchable()
                    ->toggleable(),
                EnvironmentNameColumn::make('auditRun.environment.name')
                    ->label(voightTrans('models.package.view.columns.environment'))
                    ->sortable()
                    ->toggleable(),
                FindingColumns::since('auditRun.started_at', voightTrans('models.package.view.columns.observed'))
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->filters([
                TernaryFilter::make('latest_only')
                    ->label(voightTrans('models.package.view.filters.latest_only'))
                    ->trueLabel(voightTrans('models.package.view.filters.latest_only_true'))
                    ->falseLabel(voightTrans('models.package.view.filters.latest_only_false'))
                    ->default(true)
                    ->queries(
                        true: fn (Builder $q) => $q->whereIn('audit_run_id', (FilamentVoight::config()->getAuditRunModel())::latestIdsPerEnvironment()),
                        false: fn (Builder $q) => $q,
                        blank: fn (Builder $q) => $q,
                    ),
                SelectFilter::make('package_type')
                    ->label(voightTrans('widgets.active_findings.columns.package_type'))
                    ->options(PackageType::options())
                    ->query(function (Builder $query, array $data): Builder {
                        if (blank($data['value'] ?? null)) {
                            return $query;
                        }

                        return $query->whereHas(
                            'package',
                            fn (Builder $q) => $q->where('type', $data['value']),
                        );
                    }),
                Filter::make('observed_at')
                    ->label(voightTrans('models.package.view.filters.observed_at'))
                    ->schema([
                        DatePicker::make('from')->label(voightTrans('models.package.view.filters.observed_from')),
                        DatePicker::make('until')->label(voightTrans('models.package.view.filters.observed_until')),
                    ])
                    ->query(function (Builder $query, array $data): Builder {
                        return $query
                            ->when(
                                $data['from'] ?? null,
                                fn (Builder $q, string $from) => $q->whereHas(
                                    'auditRun',
                                    fn (Builder $ar) => $ar->whereDate('started_at', '>=', $from),
                                ),
                            )
                            ->when(
                                $data['until'] ?? null,
                                fn (Builder $q, string $until) => $q->whereHas(
                                    'auditRun',
                                    fn (Builder $ar) => $ar->whereDate('started_at', '<=', $until),
                                ),
                            );
                    }),
            ])
            ->groups([
                Group::make('vulnerability.source_id')
                    ->label(voightTrans('models.package.view.columns.source_id')),
            ])
            ->recordActions([
                WhyInstalledAction::make(),
            ])
            ->defaultSort('vulnerability.vulnerability_score', 'desc')
            ->emptyStateIcon(Heroicon::OutlinedShieldCheck)
            ->emptyStateHeading(voightTrans('models.package.view.empty.no_active_findings_heading'))
            ->emptyStateDescription(voightTrans('models.package.view.empty.no_active_findings_description'));
    }
}
