<?php

namespace Statikbe\FilamentVoight\Resources\ProjectResource\Schemas;

use Filament\Actions\Action;
use Filament\Infolists\Components\RepeatableEntry;
use Filament\Infolists\Components\TextEntry;
use Filament\Notifications\Notification;
use Filament\Schemas\Components\Grid;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Support\Enums\IconPosition;
use Filament\Support\Icons\Heroicon;
use Statikbe\FilamentVoight\Models\Project;
use Statikbe\FilamentVoight\Resources\CustomerResource;
use Statikbe\FilamentVoight\Resources\TeamResource;

class ProjectInfoListSchema
{
    public static function configure(Schema $schema): Schema
    {
        return $schema->components([
            Grid::make()
                ->components([
                    Section::make(voightTrans('models.project.sections.general'))->components([
                        TextEntry::make('project_code')
                            ->label(voightTrans('models.project.fields.project_code')),
                        TextEntry::make('name')
                            ->label(voightTrans('models.project.fields.name')),
                        TextEntry::make('description')
                            ->label(voightTrans('models.project.fields.description')),
                        TextEntry::make('repo_url')
                            ->label(voightTrans('models.project.fields.repo_url'))
                            ->url(fn ($state) => $state, shouldOpenInNewTab: true)
                            ->icon(Heroicon::OutlinedArrowTopRightOnSquare)

                            ->iconPosition(IconPosition::After)
                            ->extraAttributes(['class' => 'underline'])
                            ->iconPosition(IconPosition::After),
                    ]),
                    Section::make(voightTrans('models.project.sections.assignment'))
                        ->components([
                            TextEntry::make('customer.name')
                                ->label(voightTrans('models.project.fields.customer'))
                                ->url(fn ($record) => CustomerResource::getUrl('view', ['record' => $record->customer]))
                                ->badge(),
                            TextEntry::make('team.name')
                                ->label(voightTrans('models.project.fields.team'))
                                ->url(fn ($record) => TeamResource::getUrl('view', ['record' => $record->team]))
                                ->badge(),
                        ]),
                    Section::make(voightTrans('models.project.sections.settings'))
                        ->components([
                            TextEntry::make('is_muted')
                                ->badge()
                                ->formatStateUsing(fn ($state) => $state ? voightTrans('models.project.fields.is_muted') : voightTrans('models.project.fields.is_unmuted'))
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
                ])->columnSpanFull(),
        ]);
    }
}
