<?php

namespace Statikbe\FilamentVoight\Resources\ProjectResource\Pages;

use Filament\Resources\Pages\CreateRecord;
use Statikbe\FilamentVoight\Resources\ProjectResource;

class CreateProject extends CreateRecord
{
    protected static string $resource = ProjectResource::class;

    protected function getRedirectUrl(): string
    {
        return $this->getResource()::getUrl('index');
    }
}
