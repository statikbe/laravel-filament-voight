<?php

namespace Statikbe\FilamentVoight\Resources\Components;

use BackedEnum;
use Filament\Actions\BulkAction;
use Filament\Forms\Components\Select;
use Filament\Notifications\Notification;
use Illuminate\Contracts\Support\Htmlable;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use Statikbe\FilamentVoight\Enums\VoightIcon;
use Statikbe\FilamentVoight\Facades\FilamentVoight;
use Statikbe\FilamentVoight\Services\TagAssigner;

class RemoveTagsBulkAction
{
    public static function make(): BulkAction
    {
        return BulkAction::make('removeTags')
            ->label(voightTrans('tags.actions.remove'))
            ->icon(fn (): string | BackedEnum | Htmlable => VoightIcon::ActionRemoveTags->resolve())
            ->color('gray')
            ->schema(fn (BulkAction $action): array => [
                Select::make('tags')
                    ->label(voightTrans('models.tag.plural'))
                    ->options(TagOptions::grouped($action->getModel()))
                    ->multiple()
                    ->searchable()
                    ->required(),
            ])
            ->authorizeIndividualRecords(fn (Model $record): bool => TagAssigner::userMayTag(auth()->user(), $record))
            ->action(function (Collection $records, array $data): void {
                $tags = FilamentVoight::config()->getTagModel()::query()->whereKey($data['tags'])->get();

                app(TagAssigner::class)->remove($records, $tags);

                Notification::make()
                    ->success()
                    ->title(voightTrans('tags.notifications.removed', ['count' => $records->count()]))
                    ->send();
            })
            ->deselectRecordsAfterCompletion();
    }
}
