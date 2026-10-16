<?php

namespace Statikbe\FilamentVoight\Resources\PackageResource\RelationManagers;

use Filament\Resources\RelationManagers\RelationManager;
use Filament\Support\Enums\FontWeight;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Statikbe\FilamentVoight\Models\EnvironmentPackage;
use Statikbe\FilamentVoight\Resources\Components\EnvironmentNameColumn;
use Statikbe\FilamentVoight\Resources\Components\FindingColumns;
use Statikbe\FilamentVoight\Resources\EnvironmentResource;
use Statikbe\FilamentVoight\Resources\PackageResource\Actions\WhyInstalledAction;

class InstallationsRelationManager extends RelationManager
{
    protected static string $relationship = 'environmentPackages';

    protected static string | \BackedEnum | null $icon = Heroicon::OutlinedServerStack;

    public static function getTitle(Model $ownerRecord, string $pageClass): string
    {
        return voightTrans('models.package.view.installations_title');
    }

    public static function getBadge(Model $ownerRecord, string $pageClass): ?string
    {
        $count = $ownerRecord->environmentPackages()->count();

        return $count > 0 ? (string) $count : null;
    }

    public static function getBadgeTooltip(Model $ownerRecord, string $pageClass): ?string
    {
        return voightTrans('hints.badges.package_installations');
    }

    public static function getBadgeColor(Model $ownerRecord, string $pageClass): ?string
    {
        return 'gray';
    }

    public function table(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(fn (Builder $query) => $query->with(['environment.project', 'parents.package']))
            ->recordUrl(fn (EnvironmentPackage $record): string => EnvironmentResource::getUrl('view', [
                'project' => $record->environment->project_id,
                'record' => $record->environment_id,
            ]))
            ->striped()
            ->columns([
                TextColumn::make('environment.project.name')
                    ->label(voightTrans('models.package.view.columns.project'))
                    ->weight(FontWeight::Medium)
                    ->searchable()
                    ->sortable(),
                EnvironmentNameColumn::make('environment.name')
                    ->label(voightTrans('models.package.view.columns.environment'))
                    ->searchable()
                    ->sortable(),
                FindingColumns::installedVersion('version'),
                IconColumn::make('is_direct')
                    ->label(voightTrans('models.package.view.columns.direct'))
                    ->boolean(),
                TextColumn::make('parents.package.name')
                    ->label(voightTrans('models.package.view.columns.required_by'))
                    ->listWithLineBreaks()
                    ->limitList(3)
                    ->expandableLimitedList()
                    ->placeholder('—'),
                IconColumn::make('is_dev')
                    ->label(voightTrans('models.package.view.columns.dev'))
                    ->boolean()
                    ->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('environment.scanned_at')
                    ->label(voightTrans('models.package.view.columns.last_scan'))
                    ->since()
                    ->dateTimeTooltip()
                    ->sortable()
                    ->placeholder(voightTrans('models.environment.never_scanned')),
            ])
            ->recordActions([
                WhyInstalledAction::make(),
            ])
            ->defaultSort('environment.scanned_at', 'desc')
            ->emptyStateIcon(Heroicon::OutlinedServerStack)
            ->emptyStateHeading(voightTrans('models.package.view.empty.no_installations_heading'))
            ->emptyStateDescription(voightTrans('models.package.view.empty.no_installations_description'));
    }
}
