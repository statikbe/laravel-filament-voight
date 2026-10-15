<?php

namespace Statikbe\FilamentVoight\Resources;

use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Illuminate\Contracts\Support\Htmlable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Statikbe\FilamentVoight\Models\Environment;
use Statikbe\FilamentVoight\Resources\EnvironmentResource\Pages\ViewEnvironment;
use Statikbe\FilamentVoight\Resources\EnvironmentResource\Schemas\EnvironmentInfolistSchema;

/**
 * Nested under ProjectResource, so it has no navigation entry or index of its own;
 * the global overview is the ListEnvironments page.
 */
class EnvironmentResource extends Resource
{
    protected static ?string $model = Environment::class;

    protected static ?string $parentResource = ProjectResource::class;

    protected static ?string $recordTitleAttribute = 'name';

    public static function getModelLabel(): string
    {
        return voightTrans('models.environment.label');
    }

    public static function getPluralModelLabel(): string
    {
        return voightTrans('models.environment.plural');
    }

    public static function getRecordTitle(?Model $record): string | Htmlable | null
    {
        /** @var Environment|null $record */
        return $record?->name;
    }

    public static function getEloquentQuery(): Builder
    {
        return parent::getEloquentQuery()->with('systemDetails');
    }

    public static function infolist(Schema $schema): Schema
    {
        return EnvironmentInfolistSchema::configure($schema);
    }

    public static function canCreate(): bool
    {
        return false;
    }

    public static function getPages(): array
    {
        return [
            'view' => ViewEnvironment::route('/{record}'),
        ];
    }
}
