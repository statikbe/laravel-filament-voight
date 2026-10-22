<?php

namespace Statikbe\FilamentVoight\Resources\TeamResource\RelationManagers;

use Filament\Resources\RelationManagers\RelationManager;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Model;
use Statikbe\FilamentVoight\Models\Team;
use Statikbe\FilamentVoight\Resources\ProjectResource\Schemas\ProjectTableSchema;

class ProjectsRelationManager extends RelationManager
{
    protected static string $relationship = 'projects';

    protected static string | \BackedEnum | null $icon = Heroicon::OutlinedFolder;

    public static function getTitle(Model $ownerRecord, string $pageClass): string
    {
        return voightTrans('models.project.plural');
    }

    /**
     * @param  Team  $ownerRecord
     */
    public static function getBadge(Model $ownerRecord, string $pageClass): ?string
    {
        return (string) $ownerRecord->projects()->count();
    }

    public function table(Table $table): Table
    {
        return ProjectTableSchema::configure($table, showTeam: false);
    }
}
