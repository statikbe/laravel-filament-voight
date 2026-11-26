<?php

namespace Statikbe\FilamentVoight\Resources\TagResource\Pages;

use Filament\Resources\Pages\ViewRecord;
use Illuminate\Contracts\Support\Htmlable;
use Statikbe\FilamentVoight\Models\Tag;
use Statikbe\FilamentVoight\Resources\TagResource;
use Statikbe\FilamentVoight\Resources\TagTypeResource;

class ViewTag extends ViewRecord
{
    protected static string $resource = TagResource::class;

    public function getTitle(): string | Htmlable
    {
        /** @var Tag $tag */
        $tag = $this->getRecord();

        return $tag->name;
    }

    /**
     * @return array<string, string>
     */
    public function getBreadcrumbs(): array
    {
        /** @var Tag $tag */
        $tag = $this->getRecord();

        return [
            TagTypeResource::getUrl('index') => TagTypeResource::getNavigationLabel(),
            TagTypeResource::getUrl('edit', ['record' => $tag->tagType]) => $tag->tagType->name,
            $tag->name,
        ];
    }
}
