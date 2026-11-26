<?php

namespace Statikbe\FilamentVoight\Resources;

use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Illuminate\Database\Eloquent\Model;
use Statikbe\FilamentVoight\Models\Tag;
use Statikbe\FilamentVoight\Resources\TagResource\Pages\ViewTag;
use Statikbe\FilamentVoight\Resources\TagResource\RelationManagers\RulesRelationManager;
use Statikbe\FilamentVoight\Resources\TagResource\Schemas\TagInfolistSchema;

/**
 * A tag's own page, reached from its tag type: shows the tag and manages its auto-tag rules.
 */
class TagResource extends Resource
{
    protected static ?string $model = Tag::class;

    protected static ?string $recordTitleAttribute = 'name';

    protected static bool $shouldRegisterNavigation = false;

    public static function getModelLabel(): string
    {
        return voightTrans('models.tag.label');
    }

    public static function getPluralModelLabel(): string
    {
        return voightTrans('models.tag.plural');
    }

    /**
     * Tags are listed on their tag type's page.
     *
     * @param  array<string, mixed>  $parameters
     */
    public static function getIndexUrl(array $parameters = [], bool $isAbsolute = true, ?string $panel = null, ?Model $tenant = null, bool $shouldGuessMissingParameters = false): string
    {
        return TagTypeResource::getUrl('index', $parameters, $isAbsolute, $panel, $tenant);
    }

    public static function infolist(Schema $schema): Schema
    {
        return TagInfolistSchema::configure($schema);
    }

    public static function getRelations(): array
    {
        return [
            RulesRelationManager::class,
        ];
    }

    public static function getPages(): array
    {
        return [
            'view' => ViewTag::route('/{record}'),
        ];
    }
}
