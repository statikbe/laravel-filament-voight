<?php

namespace Statikbe\FilamentVoight\Resources;

use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;
use Statikbe\FilamentVoight\Models\Customer;
use Statikbe\FilamentVoight\Resources\CustomerResource\Pages\CreateCustomer;
use Statikbe\FilamentVoight\Resources\CustomerResource\Pages\EditCustomer;
use Statikbe\FilamentVoight\Resources\CustomerResource\Pages\ListCustomers;
use Statikbe\FilamentVoight\Resources\CustomerResource\Pages\ViewCustomer;
use Statikbe\FilamentVoight\Resources\CustomerResource\RelationManagers\ProjectRelationManager;
use Statikbe\FilamentVoight\Resources\CustomerResource\Schemas\CustomerFormSchema;
use Statikbe\FilamentVoight\Resources\CustomerResource\Schemas\CustomerTableSchema;

class CustomerResource extends Resource
{
    protected static ?string $model = Customer::class;

    protected static string | \BackedEnum | null $navigationIcon = Heroicon::OutlinedBuildingOffice;

    protected static ?string $recordTitleAttribute = 'name';

    protected static ?int $navigationSort = 1;

    public static function getNavigationGroup(): ?string
    {
        return voightTrans('navigation.management');
    }

    public static function getModelLabel(): string
    {
        return voightTrans('models.customer.label');
    }

    public static function getPluralModelLabel(): string
    {
        return voightTrans('models.customer.plural');
    }

    public static function form(Schema $schema): Schema
    {
        return CustomerFormSchema::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return CustomerTableSchema::configure($table);
    }

    public static function getRelations(): array {
        return [
            ProjectRelationManager::class
        ];
    }

    public static function getPages(): array
    {
        return [
            'index' => ListCustomers::route('/'),
            'create' => CreateCustomer::route('/create'),
            'view' => ViewCustomer::route('/{record}'),
            'edit' => EditCustomer::route('/{record}/edit'),
        ];
    }
}
