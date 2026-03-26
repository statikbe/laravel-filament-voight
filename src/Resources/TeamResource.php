<?php

namespace Statikbe\FilamentVoight\Resources;

use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;
use Statikbe\FilamentVoight\Models\Team;
use Statikbe\FilamentVoight\Resources\TeamResource\Pages\CreateTeam;
use Statikbe\FilamentVoight\Resources\TeamResource\Pages\EditTeam;
use Statikbe\FilamentVoight\Resources\TeamResource\Pages\ListTeams;
use Statikbe\FilamentVoight\Resources\TeamResource\Schemas\TeamFormSchema;
use Statikbe\FilamentVoight\Resources\TeamResource\Schemas\TeamTableSchema;

class TeamResource extends Resource
{
    protected static ?string $model = Team::class;

    protected static string | \BackedEnum | null $navigationIcon = Heroicon::OutlinedUserGroup;

    protected static ?string $recordTitleAttribute = 'name';

    protected static ?int $navigationSort = 2;

    public static function getNavigationGroup(): ?string
    {
        return voightTrans('navigation.management');
    }

    public static function getModelLabel(): string
    {
        return voightTrans('models.team.label');
    }

    public static function getPluralModelLabel(): string
    {
        return voightTrans('models.team.plural');
    }

    public static function form(Schema $schema): Schema
    {
        return TeamFormSchema::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return TeamTableSchema::configure($table);
    }

    public static function getPages(): array
    {
        return [
            'index' => ListTeams::route('/'),
            'create' => CreateTeam::route('/create'),
            'edit' => EditTeam::route('/{record}/edit'),
        ];
    }
}
