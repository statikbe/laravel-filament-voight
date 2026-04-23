<?php

namespace Statikbe\FilamentVoight\Resources\PackageResource\Schemas;

use Filament\Infolists\Components\TextEntry;
use Filament\Schemas\Components\Actions;
use Filament\Schemas\Components\Group;
use Filament\Schemas\Schema;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Statikbe\FilamentVoight\Enums\PackageType;
use Statikbe\FilamentVoight\Enums\Severity;
use Statikbe\FilamentVoight\Models\AuditFinding;
use Statikbe\FilamentVoight\Models\AuditRun;
use Statikbe\FilamentVoight\Models\Package;
use Statikbe\FilamentVoight\Resources\PackageResource\Actions\OpenPackageWebsiteAction;

class PackageInfolistSchema
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                Group::make([
                    TextEntry::make('name')
                        ->label(voightTrans('models.package.fields.name'))
                        ->copyable()
                        ->weight('bold'),
                    TextEntry::make('type')
                        ->label(voightTrans('models.package.fields.type'))
                        ->badge()
                        ->formatStateUsing(fn (PackageType $state): string => $state->label()),
                    TextEntry::make('latest_version')
                        ->label(voightTrans('models.package.fields.latest_version'))
                        ->placeholder('—'),
                    TextEntry::make('latest_version_updated_at')
                        ->label(voightTrans('models.package.fields.latest_version_updated_at'))
                        ->since()
                        ->placeholder('—'),
                    TextEntry::make('installed_summary')
                        ->label(voightTrans('models.package.view.header.installed_in'))
                        ->state(fn (Package $record): string => voightTrans(
                            'models.package.view.installed_summary',
                            [
                                'environments' => $record->environmentPackages()->count(),
                                'projects' => $record->projects()->count(),
                            ],
                        )),
                    TextEntry::make('active_findings')
                        ->label(voightTrans('models.package.view.header.active_findings'))
                        ->badge()
                        ->state(fn (Package $record): string => self::activeFindingsLabel($record))
                        ->color(fn (Package $record): string => self::activeFindingsColor($record)),
                ])->columns(3),
                Actions::make([
                    OpenPackageWebsiteAction::make(),
                ]),
            ]);
    }

    private static function activeFindingsLabel(Package $package): string
    {
        $count = self::latestFindingsQuery($package)->count();

        return $count === 0
            ? voightTrans('models.package.view.no_active_findings')
            : (string) $count;
    }

    private static function activeFindingsColor(Package $package): string
    {
        $maxScore = self::latestFindingsQuery($package)
            ->join('voight_vulnerabilities', 'voight_vulnerabilities.id', '=', 'voight_audit_findings.vulnerability_id')
            ->max('voight_vulnerabilities.vulnerability_score');

        if ($maxScore === null) {
            return 'gray';
        }

        return Severity::fromScore((float) $maxScore)->color();
    }

    /**
     * @return HasMany<AuditFinding, Package>
     */
    private static function latestFindingsQuery(Package $package): HasMany
    {
        return $package->findings()
            ->whereIn('audit_run_id', AuditRun::latestIdsPerEnvironment());
    }
}
