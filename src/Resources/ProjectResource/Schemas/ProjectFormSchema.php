<?php

namespace Statikbe\FilamentVoight\Resources\ProjectResource\Schemas;

use Filament\Actions\Action;
use Filament\Forms\Components\Placeholder;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Notifications\Notification;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Statikbe\FilamentVoight\Models\Project;
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
                        Placeholder::make('active_tokens')
                            ->label(voightTrans('models.project.fields.active_tokens'))
                            ->content(fn (Project $record): string => $record->tokens()->count() . ' ' . voightTrans('models.project.fields.active_tokens_count'))
                            ->afterContent(
                                Action::make('generate_token')
                                    ->label(voightTrans('models.project.actions.generate_token'))
                                    ->requiresConfirmation()
                                    ->action(function (Project $record) {
                                        $token = $record->createToken('api');

                                        Notification::make()
                                            ->title(voightTrans('models.project.actions.token_generated'))
                                            ->body($token->plainTextToken)
                                            ->persistent()
                                            ->success()
                                            ->send();
                                    }),
                            )
                            ->afterContent(
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
                            ),
                    ]),
            ]);
    }
}
