<?php

namespace Statikbe\FilamentVoight\Resources\ProjectResource\Schemas;

use Filament\Actions\Action;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Infolists\Components\RepeatableEntry;
use Filament\Infolists\Components\TextEntry;
use Filament\Notifications\Notification;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Statikbe\FilamentVoight\Models\Project;
use Statikbe\FilamentVoight\Models\Team;
use Statikbe\FilamentVoight\Resources\CustomerResource\Schemas\CustomerFormSchema;

class ProjectFormSchema
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                Section::make(voightTrans('models.project.sections.general'))
                    ->components([
                        TextInput::make('project_code')
                            ->label(voightTrans('models.project.fields.project_code'))
                            ->required()
                            ->maxLength(255)
                            ->unique(ignoreRecord: true),
                        TextInput::make('name')
                            ->label(voightTrans('models.project.fields.name'))
                            ->required()
                            ->maxLength(255),
                        Textarea::make('description')
                            ->label(voightTrans('models.project.fields.description'))
                            ->rows(3),
                        TextInput::make('repo_url')
                            ->label(voightTrans('models.project.fields.repo_url'))
                            ->required()
                            ->url()
                            ->maxLength(255),
                    ]),
                Section::make(voightTrans('models.project.sections.assignment'))
                    ->components([
                        Select::make('customer_id')
                            ->label(voightTrans('models.project.fields.customer'))
                            ->relationship('customer', 'name')
                            ->searchable()
                            ->preload()
                            ->required()
                            ->createOptionForm(CustomerFormSchema::fields()),
                        Select::make('team_id')
                            ->label(voightTrans('models.project.fields.team'))
                            ->relationship('team', 'name')
                            ->default(Team::whereHas('users', function ($query) {
                                    return $query->where('user_id', auth()->id());
                                })->pluck('id')->first()
                            )
                            ->searchable()
                            ->preload()
                            ->required(),
                    ]),
                Section::make(voightTrans('models.project.sections.settings'))
                    ->components([
                        Toggle::make('is_muted')
                            ->label(voightTrans('models.project.fields.is_muted'))
                            ->helperText(voightTrans('models.project.fields.is_muted_help')),
                    ]),
                Section::make(voightTrans('models.project.sections.api_token'))
                    ->description(voightTrans('models.project.sections.api_token_description'))
                    ->visible(fn (?Project $record): bool => $record !== null)
                    ->components([
                        RepeatableEntry::make('tokens')
                            ->label('')
                            ->schema([
                                TextEntry::make('name')
                                    ->label(voightTrans('models.project.fields.token_name')),
                                TextEntry::make('last_used_at')
                                    ->label(voightTrans('models.project.fields.token_last_used'))
                                    ->since()
                                    ->placeholder(voightTrans('models.project.fields.never_used')),
                                TextEntry::make('created_at')
                                    ->label(voightTrans('fields.created_at'))
                                    ->since(),
                            ])
                            ->columns(3)
                            ->placeholder(voightTrans('models.project.fields.no_tokens')),
                    ])
                    ->afterHeader([
                        Action::make('generate_token')
                            ->label(voightTrans('models.project.actions.generate_token'))
                            ->requiresConfirmation()
                            ->action(function (Project $record) {
                                $token = $record->createToken(Project::DEFAULT_API_TOKEN_NAME);

                                Notification::make()
                                    ->title(voightTrans('models.project.actions.token_generated'))
                                    ->body($token->plainTextToken)
                                    ->persistent()
                                    ->success()
                                    ->send();
                            }),
                        Action::make('revoke_tokens')
                            ->label(voightTrans('models.project.actions.revoke_tokens'))
                            ->color('danger')
                            ->requiresConfirmation()
                            ->visible(fn (Project $record): bool => $record->tokens()->count() > 0)
                            ->action(function (Project $record) {
                                $record->tokens()->delete();

                                Notification::make()
                                    ->title(voightTrans('models.project.actions.tokens_revoked'))
                                    ->success()
                                    ->send();
                            }),
                    ]),
            ]);
    }
}
