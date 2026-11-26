<?php

namespace Statikbe\FilamentVoight\Resources\TagTypeResource\Pages;

use Filament\Resources\Pages\CreateRecord;
use Statikbe\FilamentVoight\Resources\TagTypeResource;

class CreateTagType extends CreateRecord
{
    protected static string $resource = TagTypeResource::class;

    protected function getRedirectUrl(): string
    {
        return $this->getResource()::getUrl('edit', ['record' => $this->getRecord()]);
    }
}
