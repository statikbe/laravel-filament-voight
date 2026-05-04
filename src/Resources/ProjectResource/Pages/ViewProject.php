<?php

namespace Statikbe\FilamentVoight\Resources\ProjectResource\Pages;

use Filament\Actions\DeleteAction;
use Filament\Actions\EditAction;
use Filament\Resources\Pages\ViewRecord;
use Illuminate\Contracts\Support\Htmlable;
use Statikbe\FilamentVoight\Resources\ProjectResource;

class ViewProject extends ViewRecord
{
    protected function getHeaderActions(): array
    {
        return [
            EditAction::make(),
            DeleteAction::make()
                ->requiresConfirmation(),
        ];
    }

    protected static string $resource = ProjectResource::class;

    public function getTitle(): string|Htmlable {
        return $this->getRecordTitle();
    }
}
