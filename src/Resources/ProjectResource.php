<?php

namespace Statikbe\FilamentVoight\Resources;

use Filament\Pages\Enums\SubNavigationPosition;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Statikbe\FilamentVoight\Models\Project;
use Statikbe\FilamentVoight\Resources\ProjectResource\Pages\CreateProject;
use Statikbe\FilamentVoight\Resources\ProjectResource\Pages\EditProject;
use Statikbe\FilamentVoight\Resources\ProjectResource\Pages\ListProjects;
use Statikbe\FilamentVoight\Resources\ProjectResource\Pages\ViewProject;
use Statikbe\FilamentVoight\Resources\ProjectResource\RelationManagers\AlertSettingsRelationManager;
use Statikbe\FilamentVoight\Resources\ProjectResource\RelationManagers\AuditRunsRelationManager;
use Statikbe\FilamentVoight\Resources\ProjectResource\RelationManagers\EnvironmentsRelationManager;
use Statikbe\FilamentVoight\Resources\ProjectResource\RelationManagers\VulnerabilitiesRelationManager;
use Statikbe\FilamentVoight\Resources\ProjectResource\Schemas\ProjectFormSchema;
use Statikbe\FilamentVoight\Resources\ProjectResource\Schemas\ProjectInfoListSchema;
use Statikbe\FilamentVoight\Resources\ProjectResource\Schemas\ProjectTableSchema;

class ProjectResource extends Resource
{
    protected static ?string $model = Project::class;

    protected static string | \BackedEnum | null $navigationIcon = Heroicon::OutlinedCodeBracket;

    protected static ?string $recordTitleAttribute = 'name';

    protected static ?int $navigationSort = 3;

    protected static ?SubNavigationPosition $subNavigationPosition = SubNavigationPosition::Top;

    public static function getNavigationGroup(): ?string
    {
        return voightTrans('navigation.management');
    }

    public static function getModelLabel(): string
    {
        return voightTrans('models.project.label');
    }

    public static function getPluralModelLabel(): string
    {
        return voightTrans('models.project.plural');
    }

    /**
     * @return array<int, string>
     */
    public static function getGloballySearchableAttributes(): array
    {
        return ['name', 'project_code', 'customer.name', 'team.name'];
    }

    public static function getGlobalSearchEloquentQuery(): Builder
    {
        return parent::getGlobalSearchEloquentQuery()->with(['customer', 'team']);
    }

    /**
     * @return array<string, string>
     */
    public static function getGlobalSearchResultDetails(Model $record): array
    {
        return array_filter([
            voightTrans('models.project.fields.customer') => $record->customer?->name,
            voightTrans('models.project.fields.team') => $record->team?->name,
        ], fn (mixed $value): bool => $value !== null);
    }

    public static function form(Schema $schema): Schema
    {
        return ProjectFormSchema::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return ProjectTableSchema::configure($table);
    }

    public static function infolist(Schema $schema): Schema
    {
        // return $schema;
        return ProjectInfoListSchema::configure($schema);
    }

    public static function getRelations(): array
    {
        return [
            'environment' => EnvironmentsRelationManager::class,
            'alert' => AlertSettingsRelationManager::class,
            'vulnerability' => VulnerabilitiesRelationManager::class,
            'auditRun' => AuditRunsRelationManager::class,
        ];
    }

    public static function getPages(): array
    {
        return [
            'index' => ListProjects::route('/'),
            'create' => CreateProject::route('/create'),
            'view' => ViewProject::route('/{record}'),
            'edit' => EditProject::route('/{record}/edit'),
        ];
    }
}
