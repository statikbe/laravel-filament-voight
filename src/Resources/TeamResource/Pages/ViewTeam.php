<?php

namespace Statikbe\FilamentVoight\Resources\TeamResource\Pages;

use Filament\Actions\DeleteAction;
use Filament\Actions\EditAction;
use Filament\Resources\Pages\ViewRecord;
use Statikbe\FilamentVoight\Resources\TeamResource;

class ViewTeam extends ViewRecord
{
    protected function getHeaderActions(): array
    {
        return [
            EditAction::make(),
            DeleteAction::make()
                ->requiresConfirmation()
                ->disabled(fn ($record) => $record->projects->isNotEmpty()),
        ];
    }

    protected static string $resource = TeamResource::class;
}
