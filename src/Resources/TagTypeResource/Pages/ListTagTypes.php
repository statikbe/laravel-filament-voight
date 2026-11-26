<?php

namespace Statikbe\FilamentVoight\Resources\TagTypeResource\Pages;

use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;
use Statikbe\FilamentVoight\Resources\TagTypeResource;

class ListTagTypes extends ListRecords
{
    protected static string $resource = TagTypeResource::class;

    protected function getHeaderActions(): array
    {
        return [
            CreateAction::make(),
        ];
    }
}
