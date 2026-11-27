<?php

namespace Statikbe\FilamentVoight\Resources\CustomerResource\RelationManagers;

use BackedEnum;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables\Table;
use Illuminate\Contracts\Support\Htmlable;
use Illuminate\Database\Eloquent\Model;
use Statikbe\FilamentVoight\Enums\VoightIcon;
use Statikbe\FilamentVoight\Resources\ProjectResource;

class ProjectRelationManager extends RelationManager
{
    protected static string $relationship = 'projects';

    public static function getIcon(Model $ownerRecord, string $pageClass): string | BackedEnum | Htmlable | null
    {
        return VoightIcon::TabCustomerProjects->resolve();
    }

    public function table(Table $table): Table
    {
        return ProjectResource\Schemas\ProjectTableSchema::configure($table);
    }
}
