<?php

namespace Statikbe\FilamentVoight\Resources\CustomerResource\RelationManagers;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables\Table;
use Statikbe\FilamentVoight\Resources\ProjectResource;

class ProjectRelationManager extends RelationManager
{

    protected static string $relationship = 'projects';


    public function table(Table $table): Table
    {
        return ProjectResource\Schemas\ProjectTableSchema::configure($table);
    }

}
