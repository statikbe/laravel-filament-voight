<?php

namespace Statikbe\FilamentVoight\Resources;

use Filament\Resources\Resource;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;
use Statikbe\FilamentVoight\Models\Package;
use Statikbe\FilamentVoight\Resources\PackageResource\Pages\ListPackages;
use Statikbe\FilamentVoight\Resources\PackageResource\Schemas\PackageTableSchema;

class PackageResource extends Resource
{
    protected static ?string $model = Package::class;

    protected static string|\BackedEnum|null $navigationIcon = Heroicon::OutlinedCube;

    protected static ?string $recordTitleAttribute = 'name';

    protected static ?int $navigationSort = 4;

    public static function getNavigationGroup(): ?string
    {
        return voightTrans('navigation.dependencies');
    }

    public static function getModelLabel(): string
    {
        return voightTrans('models.package.label');
    }

    public static function getPluralModelLabel(): string
    {
        return voightTrans('models.package.plural');
    }

    public static function table(Table $table): Table
    {
        return PackageTableSchema::configure($table);
    }

    public static function canCreate(): bool
    {
        return false;
    }

    public static function getPages(): array
    {
        return [
            'index' => ListPackages::route('/'),
        ];
    }
}
