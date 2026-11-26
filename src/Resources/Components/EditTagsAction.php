<?php

namespace Statikbe\FilamentVoight\Resources\Components;

use BackedEnum;
use Filament\Actions\Action;
use Illuminate\Contracts\Support\Htmlable;
use Illuminate\Database\Eloquent\Model;
use Statikbe\FilamentVoight\Enums\VoightIcon;
use Statikbe\FilamentVoight\Services\TagAssigner;

/**
 * Edits a record's tags from its view page, for models without an edit form.
 */
class EditTagsAction
{
    public static function make(): Action
    {
        return Action::make('editTags')
            ->label(voightTrans('tags.actions.edit'))
            ->icon(fn (): string | BackedEnum | Htmlable => VoightIcon::ActionEditTags->resolve())
            ->color('gray')
            ->visible(fn (Model $record): bool => TagFields::make($record::class, saveWithRecord: false) !== [])
            ->authorize(fn (Model $record): bool => TagAssigner::userMayTag(auth()->user(), $record))
            ->schema(fn (Model $record): array => TagFields::make($record::class, saveWithRecord: false))
            ->fillForm(fn (Model $record): array => TagFields::state($record))
            ->action(fn (Model $record, array $data) => TagFields::save($record, $data))
            ->successNotificationTitle(voightTrans('tags.notifications.saved'));
    }
}
