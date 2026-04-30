<?php

namespace Statikbe\FilamentVoight\Resources\ProjectResource\Pages;

use Filament\Actions\EditAction;
use Filament\Pages\Enums\SubNavigationPosition;
use Filament\Resources\Pages\ViewRecord;
use Statikbe\FilamentVoight\Resources\ProjectResource;

class ViewProject extends ViewRecord
{

    protected function getHeaderActions(): array {
        return [
            EditAction::make()
        ];
    }

    protected static string $resource = ProjectResource::class;

}
