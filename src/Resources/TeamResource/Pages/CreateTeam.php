<?php

namespace Statikbe\FilamentVoight\Resources\TeamResource\Pages;

use Filament\Resources\Pages\CreateRecord;
use Statikbe\FilamentVoight\Resources\TeamResource;

class CreateTeam extends CreateRecord
{
    protected static string $resource = TeamResource::class;

    protected function getRedirectUrl(): string
    {
        return $this->getResource()::getUrl('index');
    }
}
