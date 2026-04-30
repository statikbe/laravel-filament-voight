<?php

namespace Statikbe\FilamentVoight\Resources;

use Filament\Pages\Enums\SubNavigationPosition;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;
use Statikbe\FilamentVoight\Models\Project;
use Statikbe\FilamentVoight\Resources\ProjectResource\Pages\CreateProject;
use Statikbe\FilamentVoight\Resources\ProjectResource\Pages\EditProject;
use Statikbe\FilamentVoight\Resources\ProjectResource\Pages\ListProjects;
use Statikbe\FilamentVoight\Resources\ProjectResource\Pages\ViewProject;
use Statikbe\FilamentVoight\Resources\ProjectResource\RelationManagers\AlertSettingsRelationManager;
use Statikbe\FilamentVoight\Resources\ProjectResource\RelationManagers\EnvironmentsRelationManager;
use Statikbe\FilamentVoight\Resources\ProjectResource\RelationManagers\VulnerabilitiesRelationManager;
use Statikbe\FilamentVoight\Resources\ProjectResource\Schemas\ProjectFormSchema;
use Statikbe\FilamentVoight\Resources\ProjectResource\Schemas\ProjectInfoListSchema;
use Statikbe\FilamentVoight\Resources\ProjectResource\Schemas\ProjectTableSchema;

class ProjectResource extends Resource
{
    protected static ?string $model = Project::class;

    protected static string|\BackedEnum|null $navigationIcon = Heroicon::OutlinedCodeBracket;

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
            EnvironmentsRelationManager::class,
            AlertSettingsRelationManager::class,
            VulnerabilitiesRelationManager::class,
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
