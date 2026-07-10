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
use Statikbe\FilamentVoight\Facades\FilamentVoight;
use Statikbe\FilamentVoight\Models\AlertSetting;
use Statikbe\FilamentVoight\Models\Team;

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
                    ->options(AlertChannel::options())
                    ->required()
                    ->live(),
                TextInput::make('severity_threshold')
                    ->label(voightTrans('models.alert_setting.fields.severity_threshold'))
                    ->numeric()
                    ->minValue(0.0)
                    ->maxValue(10.0)
                    ->step(0.1)
                    ->required(),
                Select::make('frequency')
                    ->label(voightTrans('models.alert_setting.fields.frequency'))
                    ->options(AlertFrequency::options())
                    ->required(),
                TextInput::make('slack_channel')
                    ->label(voightTrans('models.alert_setting.fields.slack_channel'))
                    ->placeholder(fn (): ?string => FilamentVoight::config()->getSlackDefaultChannel()
                        ?? config('services.slack.notifications.channel'))
                    ->maxLength(255)
                    ->visible(fn (callable $get): bool => $get('channel') === AlertChannel::Slack->value),
                Select::make('recipient_users')
                    ->label(voightTrans('models.alert_setting.fields.recipient_users'))
                    ->options(fn (): array => FilamentVoight::config()->getUserModel()::query()
                        ->orderBy('name')
                        ->pluck('name', 'id')
                        ->all())
                    ->multiple()
                    ->searchable()
                    ->dehydrated(false)
                    ->visible(fn (callable $get): bool => $get('channel') === AlertChannel::Email->value)
                    ->loadStateFromRelationshipsUsing(function (Select $component): void {
                        $this->fillRecipientState($component, $this->userMorphAlias());
                    })
                    ->saveRelationshipsUsing(function (Select $component): void {
                        $this->syncRecipientState($component, $this->userMorphAlias());
                    }),
                Select::make('recipient_teams')
                    ->label(voightTrans('models.alert_setting.fields.recipient_teams'))
                    ->options(fn (): array => Team::query()
                        ->orderBy('name')
                        ->pluck('name', 'id')
                        ->all())
                    ->multiple()
                    ->searchable()
                    ->dehydrated(false)
                    ->visible(fn (callable $get): bool => $get('channel') === AlertChannel::Email->value)
                    ->loadStateFromRelationshipsUsing(function (Select $component): void {
                        $this->fillRecipientState($component, $this->teamMorphAlias());
                    })
                    ->saveRelationshipsUsing(function (Select $component): void {
                        $this->syncRecipientState($component, $this->teamMorphAlias());
                    }),
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
                TextColumn::make('slack_channel')
                    ->label(voightTrans('models.alert_setting.fields.slack_channel'))
                    ->placeholder('—'),
                TextColumn::make('recipients_count')
                    ->label(voightTrans('models.alert_setting.fields.recipients'))
                    ->counts('recipients'),
                TextColumn::make('last_sent_at')
                    ->label(voightTrans('models.alert_setting.fields.last_sent_at'))
                    ->dateTime()
                    ->sortable()
                    ->placeholder('—'),
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

    private function fillRecipientState(Select $component, string $morphAlias): void
    {
        /** @var AlertSetting $setting */
        $setting = $component->getRecord();

        $component->state(
            $setting->recipients()
                ->where('recipient_type', $morphAlias)
                ->pluck('recipient_id')
                ->all(),
        );
    }

    private function syncRecipientState(Select $component, string $morphAlias): void
    {
        /** @var AlertSetting $setting */
        $setting = $component->getRecord();

        /** @var array<int, string> $recipientIds */
        $recipientIds = array_map(strval(...), (array) $component->getState());

        $setting->recipients()
            ->where('recipient_type', $morphAlias)
            ->whereNotIn('recipient_id', $recipientIds)
            ->delete();

        foreach ($recipientIds as $recipientId) {
            $setting->recipients()->firstOrCreate([
                'recipient_type' => $morphAlias,
                'recipient_id' => $recipientId,
            ]);
        }
    }

    private function userMorphAlias(): string
    {
        return (new (FilamentVoight::config()->getUserModel()))->getMorphClass();
    }

    private function teamMorphAlias(): string
    {
        return (new Team)->getMorphClass();
    }
}
