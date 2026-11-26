<?php

namespace Statikbe\FilamentVoight\Resources\TeamResource\RelationManagers;

use BackedEnum;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables\Table;
use Illuminate\Contracts\Support\Htmlable;
use Illuminate\Database\Eloquent\Model;
use Statikbe\FilamentVoight\Enums\VoightIcon;
use Statikbe\FilamentVoight\Models\Team;
use Statikbe\FilamentVoight\Resources\ProjectResource\Schemas\ProjectTableSchema;

class ProjectsRelationManager extends RelationManager
{
    protected static string $relationship = 'projects';

    public static function getIcon(Model $ownerRecord, string $pageClass): string | BackedEnum | Htmlable | null
    {
        return VoightIcon::TabTeamProjects->resolve();
    }

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
