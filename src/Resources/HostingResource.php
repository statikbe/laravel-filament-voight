<?php

namespace Statikbe\FilamentVoight\Resources;

use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;
use Statikbe\FilamentVoight\Models\Hosting;
use Statikbe\FilamentVoight\Resources\HostingResource\Pages\ManageHostings;
use Statikbe\FilamentVoight\Resources\HostingResource\Schemas\HostingFormSchema;
use Statikbe\FilamentVoight\Resources\HostingResource\Schemas\HostingTableSchema;

class HostingResource extends Resource
{
    protected static ?string $model = Hosting::class;

    protected static string | \BackedEnum | null $navigationIcon = Heroicon::OutlinedCloud;

    protected static ?string $recordTitleAttribute = 'name';

    protected static ?int $navigationSort = 3;

    public static function getNavigationGroup(): ?string
    {
        return voightTrans('navigation.organisation');
    }

    public static function getModelLabel(): string
    {
        return voightTrans('models.hosting.label');
    }

    public static function getPluralModelLabel(): string
    {
        return voightTrans('models.hosting.plural');
    }

    public static function form(Schema $schema): Schema
    {
        return HostingFormSchema::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return HostingTableSchema::configure($table);
    }

    public static function getPages(): array
    {
        return [
            'index' => ManageHostings::route('/'),
        ];
    }
}
