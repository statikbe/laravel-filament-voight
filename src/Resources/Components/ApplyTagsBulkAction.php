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
use Statikbe\FilamentVoight\Services\TagAssignmentException;

/**
 * Adds tags to every selected record; on a single-value type the record's current tag is replaced.
 */
class ApplyTagsBulkAction
{
    public static function make(): BulkAction
    {
        return BulkAction::make('applyTags')
            ->label(voightTrans('tags.actions.apply'))
            ->icon(fn (): string | BackedEnum | Htmlable => VoightIcon::ActionApplyTags->resolve())
            ->schema(fn (BulkAction $action): array => [
                Select::make('tags')
                    ->label(voightTrans('models.tag.plural'))
                    ->helperText(voightTrans('tags.actions.apply_help'))
                    ->options(TagOptions::grouped($action->getModel()))
                    ->multiple()
                    ->searchable()
                    ->required(),
            ])
            ->authorizeIndividualRecords(fn (Model $record): bool => TagAssigner::userMayTag(auth()->user(), $record))
            ->action(function (Collection $records, array $data): void {
                $tags = FilamentVoight::config()->getTagModel()::query()->whereKey($data['tags'])->get();

                try {
                    app(TagAssigner::class)->assign($records, $tags);
                } catch (TagAssignmentException $exception) {
                    Notification::make()
                        ->danger()
                        ->title(voightTrans('tags.notifications.not_applied'))
                        ->body(implode("\n", $exception->problems))
                        ->send();

                    return;
                }

                Notification::make()
                    ->success()
                    ->title(voightTrans('tags.notifications.applied', ['count' => $records->count()]))
                    ->send();
            })
            ->deselectRecordsAfterCompletion();
    }
}
