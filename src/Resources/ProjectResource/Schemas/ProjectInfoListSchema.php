<?php

namespace Statikbe\FilamentVoight\Resources\ProjectResource\Schemas;

use Filament\Actions\Action;
use Filament\Infolists\Components\RepeatableEntry;
use Filament\Infolists\Components\TextEntry;
use Filament\Notifications\Notification;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Support\Enums\FontFamily;
use Filament\Support\Enums\IconPosition;
use Filament\Support\Icons\Heroicon;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Js;
use Statikbe\FilamentVoight\Models\Project;
use Statikbe\FilamentVoight\Resources\Components\InfolistLayout;
use Statikbe\FilamentVoight\Resources\Components\InfolistStat;
use Statikbe\FilamentVoight\Resources\CustomerResource;
use Statikbe\FilamentVoight\Resources\TeamResource;
use Statikbe\FilamentVoight\Services\ProjectOnboardingService;

class ProjectInfoListSchema
{
    public static function configure(Schema $schema): Schema
    {
        return $schema->components([
            EnvironmentHealthCallouts::make(),
            InfolistStat::strip([
                InfolistStat::make(
                    TextEntry::make('stat_environments')
                        ->label(voightTrans('models.environment.plural'))
                        ->icon(Heroicon::OutlinedServerStack)
                        ->state(fn (Project $record): int => $record->environments()->count()),
                ),
                InfolistStat::openFindings(voightTrans('models.project.fields.open_findings')),
                InfolistStat::make(
                    TextEntry::make('stat_last_audit')
                        ->label(voightTrans('models.project.fields.last_audit'))
                        ->hintIcon(Heroicon::OutlinedInformationCircle, tooltip: voightTrans('hints.last_audit'))
                        ->icon(Heroicon::OutlinedClipboardDocumentCheck)
                        ->state(fn (Project $record): ?string => $record->auditRuns()->max('voight_audit_runs.started_at'))
                        ->since()
                        ->dateTimeTooltip()
                        ->placeholder('—'),
                ),
                InfolistStat::make(
                    TextEntry::make('is_muted')
                        ->label(voightTrans('models.project.fields.alerts'))
                        ->hintIcon(Heroicon::OutlinedInformationCircle, tooltip: voightTrans('models.project.fields.is_muted_help'))
                        ->icon(fn (bool $state): Heroicon => $state ? Heroicon::OutlinedBellSlash : Heroicon::OutlinedBell)
                        ->badge()
                        ->color(fn (bool $state): string => $state ? 'warning' : 'success')
                        ->formatStateUsing(fn (bool $state): string => voightTrans('models.project.fields.' . ($state ? 'is_muted' : 'alerts_on'))),
                ),
            ]),
            InfolistLayout::withSidebar(
                main: [
                    Section::make(voightTrans('models.project.sections.general'))
                        ->icon(Heroicon::OutlinedInformationCircle)
                        ->components([
                            TextEntry::make('description')
                                ->label(voightTrans('models.project.fields.description'))
                                ->placeholder('—'),
                            TextEntry::make('repo_url')
                                ->label(voightTrans('models.project.fields.repo_url'))
                                ->url(fn (?string $state): ?string => $state, shouldOpenInNewTab: true)
                                ->icon(Heroicon::OutlinedArrowTopRightOnSquare)
                                ->iconPosition(IconPosition::After)
                                ->extraAttributes(['class' => 'underline'])
                                ->placeholder('—'),
                        ]),
                    Section::make(voightTrans('models.project.sections.api_token'))
                        ->key('api_token')
                        ->icon(Heroicon::OutlinedKey)
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
                                        ->dateTimeTooltip()
                                        ->placeholder(voightTrans('models.project.fields.never_used')),
                                    TextEntry::make('created_at')
                                        ->label(voightTrans('fields.created_at'))
                                        ->since()
                                        ->dateTimeTooltip(),
                                ])
                                ->columns(3)
                                ->placeholder(voightTrans('models.project.fields.no_tokens')),
                        ])
                        ->afterHeader([
                            Action::make('generate_token')
                                ->label(voightTrans('models.project.actions.generate_token'))
                                ->requiresConfirmation()
                                ->action(function (Project $record) {
                                    $token = app(ProjectOnboardingService::class)->createToken($record);

                                    Notification::make()
                                        ->title(voightTrans('models.project.actions.token_generated'))
                                        ->body($token->plainTextToken)
                                        ->persistent()
                                        ->actions([
                                            Action::make('copy_token')
                                                ->label(voightTrans('models.project.actions.copy_token'))
                                                ->alpineClickHandler(
                                                    'window.navigator.clipboard.writeText(' . Js::from($token->plainTextToken) . '); '
                                                    . '$tooltip(' . Js::from(__('filament-forms::components.text_input.actions.copy.message')) . ', { theme: $store.theme, timeout: 2000 })'
                                                )
                                                ->button()
                                                ->color('primary'),
                                        ])
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
                        ])
                        ->collapsible(),
                ],
                sidebar: [
                    Section::make(voightTrans('models.project.sections.details'))
                        ->icon(Heroicon::OutlinedUserGroup)
                        ->components([
                            TextEntry::make('project_code')
                                ->label(voightTrans('models.project.fields.project_code'))
                                ->fontFamily(FontFamily::Mono)
                                ->copyable(),
                            TextEntry::make('customer.name')
                                ->label(voightTrans('models.project.fields.customer'))
                                ->url(fn (Project $record): ?string => $record->customer ? CustomerResource::getUrl('view', ['record' => $record->customer]) : null)
                                ->badge()
                                ->placeholder('—'),
                            TextEntry::make('team.name')
                                ->label(voightTrans('models.project.fields.team'))
                                ->formatStateUsing(fn (Project $record): View => view('filament-voight::team.avatar-name', ['team' => $record->team]))
                                ->url(fn (Project $record): ?string => $record->team ? TeamResource::getUrl('view', ['record' => $record->team]) : null)
                                ->placeholder('—'),
                            TextEntry::make('created_at')
                                ->label(voightTrans('fields.created_at'))
                                ->since()
                                ->dateTimeTooltip(),
                            TextEntry::make('updated_at')
                                ->label(voightTrans('fields.updated_at'))
                                ->since()
                                ->dateTimeTooltip(),
                        ]),
                ],
            ),
        ]);
    }
}
