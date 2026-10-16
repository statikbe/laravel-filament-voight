<?php

namespace Statikbe\FilamentVoight\Resources\CustomerResource\RelationManagers;

use Filament\Resources\RelationManagers\RelationManager;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;
use Statikbe\FilamentVoight\Models\Project;
use Statikbe\FilamentVoight\Resources\ProjectResource;

class ProjectRelationManager extends RelationManager
{
    protected static string $relationship = 'projects';

    /**
     * Projects are managed from the customer view page as well as the edit page.
     */
    public function isReadOnly(): bool
    {
        return false;
    }

    public function table(Table $table): Table
    {
        return ProjectResource\Schemas\ProjectTableSchema::configure($table, showCustomer: false)
            ->recordUrl(fn (Project $record): string => ProjectResource::getUrl('view', ['record' => $record]))
            ->striped()
            ->emptyStateIcon(Heroicon::OutlinedFolder)
            ->emptyStateHeading(voightTrans('models.customer.empty.heading'))
            ->emptyStateDescription(voightTrans('models.customer.empty.description'));
    }
}
