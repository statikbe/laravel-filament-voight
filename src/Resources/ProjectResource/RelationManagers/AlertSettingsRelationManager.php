<?php

namespace Statikbe\FilamentVoight\Resources\ProjectResource\RelationManagers;

use Filament\Actions\CreateAction;
use Filament\Actions\DeleteAction;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\EditAction;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Model;
use Statikbe\FilamentVoight\Enums\AlertChannel;
use Statikbe\FilamentVoight\Enums\AlertFrequency;

class AlertSettingsRelationManager extends RelationManager
{
    protected static string $relationship = 'alertSettings';

    public static function getTitle(Model $ownerRecord, string $pageClass): string
    {
        return voightTrans('models.alert_setting.plural');
    }

    public function form(Schema $schema): Schema
    {
        return $schema
            ->components([
                Select::make('channel')
                    ->label(voightTrans('models.alert_setting.fields.channel'))
                    ->options(collect(AlertChannel::cases())->mapWithKeys(fn (AlertChannel $case) => [$case->value => $case->label()]))
                    ->required(),
                TextInput::make('severity_threshold')
                    ->label(voightTrans('models.alert_setting.fields.severity_threshold'))
                    ->numeric()
                    ->minValue(0.0)
                    ->maxValue(10.0)
                    ->step(0.1)
                    ->required(),
                Select::make('frequency')
                    ->label(voightTrans('models.alert_setting.fields.frequency'))
                    ->options(collect(AlertFrequency::cases())->mapWithKeys(fn (AlertFrequency $case) => [$case->value => $case->label()]))
                    ->required(),
                TextInput::make('webhook_url')
                    ->label(voightTrans('models.alert_setting.fields.webhook_url'))
                    ->url()
                    ->maxLength(255)
                    ->visible(fn (callable $get): bool => $get('channel') === AlertChannel::Slack->value),
                Toggle::make('is_enabled')
                    ->label(voightTrans('models.alert_setting.fields.is_enabled'))
                    ->default(true),
            ]);
    }

    public function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('channel')
                    ->label(voightTrans('models.alert_setting.fields.channel'))
                    ->formatStateUsing(fn (AlertChannel $state): string => $state->label()),
                TextColumn::make('severity_threshold')
                    ->label(voightTrans('models.alert_setting.fields.severity_threshold'))
                    ->sortable(),
                TextColumn::make('frequency')
                    ->label(voightTrans('models.alert_setting.fields.frequency'))
                    ->formatStateUsing(fn (AlertFrequency $state): string => $state->label()),
                IconColumn::make('is_enabled')
                    ->label(voightTrans('models.alert_setting.fields.is_enabled'))
                    ->boolean(),
            ])
            ->headerActions([
                CreateAction::make(),
            ])
            ->recordActions([
                EditAction::make(),
                DeleteAction::make(),
            ])
            ->toolbarActions([
                DeleteBulkAction::make(),
            ]);
    }
}
