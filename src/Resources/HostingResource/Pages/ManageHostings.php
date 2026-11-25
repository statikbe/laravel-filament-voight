<?php

namespace Statikbe\FilamentVoight\Resources\HostingResource\Pages;

use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ManageRecords;
use Statikbe\FilamentVoight\Resources\HostingResource;

class ManageHostings extends ManageRecords
{
    protected static string $resource = HostingResource::class;

    protected function getHeaderActions(): array
    {
        return [
            CreateAction::make(),
        ];
    }
}
