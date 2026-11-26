<?php

namespace Statikbe\FilamentVoight\Resources\CustomerResource\Pages;

use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;
use Statikbe\FilamentVoight\Resources\Components\SaveTagRuleAction;
use Statikbe\FilamentVoight\Resources\Concerns\SavesTagRules;
use Statikbe\FilamentVoight\Resources\CustomerResource;

class ListCustomers extends ListRecords
{
    use SavesTagRules;

    protected static string $resource = CustomerResource::class;

    protected function getHeaderActions(): array
    {
        return [
            SaveTagRuleAction::make(),
            CreateAction::make(),
        ];
    }
}
