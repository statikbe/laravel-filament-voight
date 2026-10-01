<?php

namespace Statikbe\FilamentVoight\Resources;

use Filament\Resources\Resource;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use Statikbe\FilamentVoight\Models\EnvironmentPackage;
use Statikbe\FilamentVoight\Models\Package;
use Statikbe\FilamentVoight\Resources\PackageResource\Pages\ListPackages;
use Statikbe\FilamentVoight\Resources\PackageResource\Pages\ViewPackage;
use Statikbe\FilamentVoight\Resources\PackageResource\RelationManagers\ActiveFindingsRelationManager;
use Statikbe\FilamentVoight\Resources\PackageResource\RelationManagers\InstallationsRelationManager;
use Statikbe\FilamentVoight\Resources\PackageResource\RelationManagers\KnownVulnerabilitiesRelationManager;
use Statikbe\FilamentVoight\Resources\PackageResource\Schemas\PackageTableSchema;

class PackageResource extends Resource
{
    protected static ?string $model = Package::class;

    protected static string | \BackedEnum | null $navigationIcon = Heroicon::OutlinedCube;

    protected static ?string $recordTitleAttribute = 'name';

    protected static ?int $navigationSort = 4;

    public static function getNavigationGroup(): ?string
    {
        return voightTrans('navigation.dependencies');
    }

    public static function getModelLabel(): string
    {
        return voightTrans('models.package.label');
    }

    public static function getPluralModelLabel(): string
    {
        return voightTrans('models.package.plural');
    }

    /**
     * @return array<int, string>
     */
    public static function getGloballySearchableAttributes(): array
    {
        return ['name'];
    }

    public static function getGlobalSearchEloquentQuery(): Builder
    {
        // Distinct on project: a package installed in several environments of one project counts once.
        return parent::getGlobalSearchEloquentQuery()->select('voight_packages.*')->addSelect([
            'projects_count' => EnvironmentPackage::query()
                ->join('voight_environments', 'voight_environments.id', '=', 'voight_environment_packages.environment_id')
                ->whereColumn('voight_environment_packages.package_id', 'voight_packages.id')
                ->select(DB::raw('count(distinct voight_environments.project_id)')),
        ])->withCasts(['projects_count' => 'integer']);
    }

    /**
     * @return array<string, string|int>
     */
    public static function getGlobalSearchResultDetails(Model $record): array
    {
        return array_filter([
            voightTrans('models.package.fields.type') => $record->type->label(),
            voightTrans('models.package.fields.latest_version') => $record->latest_version,
            voightTrans('models.project.plural') => $record->projects_count,
        ], fn (mixed $value): bool => $value !== null);
    }

    public static function table(Table $table): Table
    {
        return PackageTableSchema::configure($table);
    }

    public static function canCreate(): bool
    {
        return false;
    }

    public static function getRelations(): array
    {
        return [
            InstallationsRelationManager::class,
            ActiveFindingsRelationManager::class,
            KnownVulnerabilitiesRelationManager::class,
        ];
    }

    public static function getPages(): array
    {
        return [
            'index' => ListPackages::route('/'),
            'view' => ViewPackage::route('/{record}'),
        ];
    }
}
