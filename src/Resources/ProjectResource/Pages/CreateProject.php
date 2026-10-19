<?php

namespace Statikbe\FilamentVoight\Resources\ProjectResource\Pages;

use Filament\Resources\Pages\CreateRecord;
use Statikbe\FilamentVoight\Resources\ProjectResource;

class CreateProject extends CreateRecord
{
    protected static string $resource = ProjectResource::class;

    /**
     * The view page is where the project's API token is generated.
     */
    protected function getRedirectUrl(): string
    {
        return $this->getResource()::getUrl('view', ['record' => $this->getRecord()]);
    }
}
