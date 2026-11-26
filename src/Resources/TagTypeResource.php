<?php

namespace Statikbe\FilamentVoight\Resources;

use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Tables\Table;
use Illuminate\Contracts\Support\Htmlable;
use Statikbe\FilamentVoight\Enums\VoightIcon;
use Statikbe\FilamentVoight\Models\TagType;
use Statikbe\FilamentVoight\Resources\TagTypeResource\Pages\CreateTagType;
use Statikbe\FilamentVoight\Resources\TagTypeResource\Pages\EditTagType;
use Statikbe\FilamentVoight\Resources\TagTypeResource\Pages\ListTagTypes;
use Statikbe\FilamentVoight\Resources\TagTypeResource\RelationManagers\TagsRelationManager;
use Statikbe\FilamentVoight\Resources\TagTypeResource\Schemas\TagTypeFormSchema;
use Statikbe\FilamentVoight\Resources\TagTypeResource\Schemas\TagTypeTableSchema;

class TagTypeResource extends Resource
{
    protected static ?string $model = TagType::class;

    protected static ?string $recordTitleAttribute = 'name';

    protected static ?int $navigationSort = 3;

    public static function getNavigationIcon(): string | BackedEnum | Htmlable | null
    {
        return VoightIcon::NavigationTagTypes->resolve();
    }

    public static function getNavigationGroup(): ?string
    {
        return voightTrans('navigation.settings');
    }

    public static function getNavigationLabel(): string
    {
        return voightTrans('models.tag_type.navigation_label');
    }

    public static function getModelLabel(): string
    {
        return voightTrans('models.tag_type.label');
    }

    public static function getPluralModelLabel(): string
    {
        return voightTrans('models.tag_type.plural');
    }

    public static function form(Schema $schema): Schema
    {
        return TagTypeFormSchema::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return TagTypeTableSchema::configure($table);
    }

    public static function getRelations(): array
    {
        return [
            TagsRelationManager::class,
        ];
    }

    public static function getPages(): array
    {
        return [
            'index' => ListTagTypes::route('/'),
            'create' => CreateTagType::route('/create'),
            'edit' => EditTagType::route('/{record}/edit'),
        ];
    }
}
