<?php

namespace Statikbe\FilamentVoight\Resources\EnvironmentResource\Pages;

use Filament\Actions\Action;
use Filament\Resources\Pages\ViewRecord;
use Filament\Support\Icons\Heroicon;
use Illuminate\Contracts\Support\Htmlable;
use Illuminate\Support\Js;
use Statikbe\FilamentVoight\Models\Environment;
use Statikbe\FilamentVoight\Resources\EnvironmentResource;
use Statikbe\FilamentVoight\Support\SystemDetailsPresenter;

class ViewEnvironment extends ViewRecord
{
    protected static string $resource = EnvironmentResource::class;

    public function getSubheading(): string | Htmlable | null
    {
        $record = $this->getRecord();
        $receivedAt = $record instanceof Environment ? $record->systemDetails?->received_at : null;

        if ($receivedAt === null) {
            return voightTrans('models.environment.never_reported');
        }

        return voightTrans('models.environment.system_details.last_reported') . ': ' . $receivedAt->diffForHumans() . ' (' . $receivedAt->format('Y-m-d H:i') . ')';
    }

    protected function getHeaderActions(): array
    {
        return [
            Action::make('copyMarkdown')
                ->label(voightTrans('models.environment.system_details.copy_markdown'))
                ->icon(Heroicon::ClipboardDocument)
                ->color('gray')
                ->visible(fn (Environment $record): bool => $record->systemDetails !== null)
                ->alpineClickHandler(fn (Environment $record): string => 'window.navigator.clipboard.writeText(' . Js::from(SystemDetailsPresenter::markdown($record)) . ').then(() => new FilamentNotification().title(' . Js::from(voightTrans('models.environment.system_details.copied')) . ').success().send())'),
        ];
    }
}
