<?php

namespace Statikbe\FilamentVoight\Resources\TagTypeResource\Pages;

use Filament\Actions\DeleteAction;
use Filament\Resources\Pages\EditRecord;
use Statikbe\FilamentVoight\Models\TagType;
use Statikbe\FilamentVoight\Resources\TagTypeResource;

class EditTagType extends EditRecord
{
    protected static string $resource = TagTypeResource::class;

    protected function getHeaderActions(): array
    {
        return [
            DeleteAction::make()
                ->requiresConfirmation()
                ->modalDescription(fn (TagType $record): string => voightTrans('models.tag_type.delete_description', [
                    'count' => array_sum($record->assignmentCountsByModel()),
                ])),
        ];
    }

    /**
     * Translatable attributes arrive as all locales; the form edits the current one.
     *
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    protected function mutateFormDataBeforeFill(array $data): array
    {
        /** @var TagType $record */
        $record = $this->getRecord();

        return [...$data, 'name' => $record->name, 'description' => $record->description];
    }
}
