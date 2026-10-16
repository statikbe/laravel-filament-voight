<?php

namespace Statikbe\FilamentVoight\Resources\ProjectResource\Pages;

use Filament\Actions\DeleteAction;
use Filament\Actions\EditAction;
use Filament\Resources\Pages\ViewRecord;
use Filament\Schemas\Components\EmbeddedSchema;
use Filament\Schemas\Components\Tabs\Tab;
use Filament\Support\Icons\Heroicon;
use Illuminate\Contracts\Support\Htmlable;
use Statikbe\FilamentVoight\Models\Project;
use Statikbe\FilamentVoight\Resources\ProjectResource;

class ViewProject extends ViewRecord
{
    protected static string $resource = ProjectResource::class;

    protected function getHeaderActions(): array
    {
        return [
            EditAction::make(),
            DeleteAction::make()
                ->requiresConfirmation(),
        ];
    }

    public function getTitle(): string | Htmlable
    {
        return $this->getRecordTitle();
    }

    public function getSubheading(): ?string
    {
        /** @var Project $project */
        $project = $this->getRecord();

        return collect([$project->customer?->name, $project->team?->name])
            ->filter()
            ->implode(' · ') ?: null;
    }

    public function hasCombinedRelationManagerTabsWithContent(): bool
    {
        return true;
    }

    public function getContentTabComponent(): Tab
    {
        return Tab::make($this->getContentTabLabel())
            ->icon($this->getContentTabIcon())
            ->schema([
                EmbeddedSchema::make('infolist'),
            ]);
    }

    public function getContentTabLabel(): ?string
    {
        return voightTrans('models.project.view.overview_tab');
    }

    public function getContentTabIcon(): Heroicon
    {
        return Heroicon::OutlinedSquares2x2;
    }
}
