<?php

namespace Statikbe\FilamentVoight\Resources\CustomerResource\Pages;

use Filament\Resources\Pages\CreateRecord;
use Statikbe\FilamentVoight\Resources\CustomerResource;

class CreateCustomer extends CreateRecord
{
    protected static string $resource = CustomerResource::class;

    protected function getRedirectUrl(): string
    {
        return $this->getResource()::getUrl('index');
    }
}
