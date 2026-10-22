<?php

namespace Statikbe\FilamentVoight\Resources\TeamResource\Pages;

use Filament\Actions\DeleteAction;
use Filament\Actions\EditAction;
use Filament\Resources\Pages\ViewRecord;
use Illuminate\Contracts\Support\Htmlable;
use Illuminate\Support\HtmlString;
use Statikbe\FilamentVoight\Resources\Components\TeamOption;
use Statikbe\FilamentVoight\Resources\TeamResource;

class ViewTeam extends ViewRecord
{
    protected function getHeaderActions(): array
    {
        return [
            EditAction::make(),
            DeleteAction::make()
                ->requiresConfirmation()
                ->disabled(fn ($record) => $record->projects->isNotEmpty()),
        ];
    }

    protected static string $resource = TeamResource::class;

    public function getTitle(): string | Htmlable
    {
        return $this->getRecordTitle();
    }

    public function getHeading(): string | Htmlable
    {
        return new HtmlString(TeamOption::label($this->getRecord(), 'lg'));
    }
}
