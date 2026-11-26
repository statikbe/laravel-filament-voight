<?php

namespace Statikbe\FilamentVoight\Resources\ProjectResource\Schemas;

use Filament\Actions\Action;
use Filament\Infolists\Components\RepeatableEntry;
use Filament\Infolists\Components\TextEntry;
use Filament\Notifications\Notification;
use Filament\Schemas\Components\Callout;
use Filament\Schemas\Components\EmbeddedTable;
use Filament\Schemas\Components\Grid;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Collection;
use Illuminate\Support\Js;
use Statikbe\FilamentVoight\Enums\PackageType;
use Statikbe\FilamentVoight\Enums\Severity;
use Statikbe\FilamentVoight\Enums\VoightIcon;
use Statikbe\FilamentVoight\Mcp\McpAccess;
use Statikbe\FilamentVoight\Models\Project;
use Statikbe\FilamentVoight\Resources\CustomerResource;
use Statikbe\FilamentVoight\Resources\ProjectResource\Pages\ViewProject;
use Statikbe\FilamentVoight\Resources\TeamResource;
use Statikbe\FilamentVoight\Services\Mcp\CurrentFindings;
use Statikbe\FilamentVoight\Services\ProjectOnboardingService;

class ProjectInfoListSchema
{
    public static function configure(Schema $schema): Schema
    {
        return $schema->components([
            EnvironmentHealthCallouts::make(),
            Section::make(voightTrans('models.project.sections.environments'))
                ->icon(VoightIcon::ProjectSectionEnvironments->resolve())
                ->description(voightTrans('models.project.sections.environments_description'))
                ->components([
                    Callout::make(fn (Project $record, ViewProject $livewire): string => self::npmHint($record, $livewire->includesNpm()) ?? '')
                        ->status('warning')
                        ->visible(fn (Project $record, ViewProject $livewire): bool => self::npmHint($record, $livewire->includesNpm()) !== null),
                    EmbeddedTable::make(),
                ])
                ->columnSpanFull(),
            Section::make(voightTrans('models.project.sections.general'))
                ->icon(VoightIcon::ProjectSectionGeneral->resolve())
                ->description(voightTrans('models.project.sections.general_description'))
                ->components([
                    TextEntry::make('project_code')
                        ->label(voightTrans('models.project.fields.project_code')),
                    TextEntry::make('customer.name')
                        ->label(voightTrans('models.project.fields.customer'))
                        ->url(fn ($record) => CustomerResource::getUrl('view', ['record' => $record->customer]))
                        ->badge(),
                    TextEntry::make('team.name')
                        ->label(voightTrans('models.project.fields.team'))
                        ->formatStateUsing(fn (Project $record): View => view('filament-voight::team.avatar-name', ['team' => $record->team]))
                        ->url(fn ($record) => TeamResource::getUrl('view', ['record' => $record->team])),
                    TextEntry::make('description')
                        ->label(voightTrans('models.project.fields.description'))
                        ->placeholder(voightTrans('models.project.fields.no_description'))
                        ->columnSpanFull(),
                ])
                ->columns(3)
                ->columnSpanFull(),
            Grid::make(['default' => 1, 'lg' => 2])
                ->components([
                    Section::make(voightTrans('models.project.sections.alerts'))
                        ->icon(VoightIcon::ProjectSectionAlerts->resolve())
                        ->description(voightTrans('models.project.sections.alerts_description'))
                        ->components([
                            TextEntry::make('is_muted')
                                ->badge()
                                ->formatStateUsing(fn (bool $state): string => voightTrans('models.project.fields.' . ($state ? 'is_muted' : 'alerts_active')))
                                ->color(fn (bool $state): string => $state ? 'gray' : 'success')
                                ->label(voightTrans('models.project.sections.alerts'))
                                ->helperText(voightTrans('models.project.fields.is_muted_help')),
                            TextEntry::make('last_alert')
                                ->label(voightTrans('models.project.fields.last_alert'))
                                ->state(fn (Project $record): ?string => $record->alertSettings()->max('last_sent_at'))
                                ->dateTime()
                                ->placeholder(voightTrans('models.project.fields.never_alerted')),
                        ])
                        ->columns(2),
                    Section::make(voightTrans('models.project.sections.api_token'))
                        ->key('api_token')
                        ->icon(VoightIcon::ProjectSectionApiToken->resolve())
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
                        ]),
                ])
                ->columnSpanFull(),
        ]);
    }

    /**
     * Warns that npm findings exist while they are hidden; the worst counts of any environment, worst severity first.
     */
    public static function npmHint(Project $project, bool $includeNpm): ?string
    {
        if ($includeNpm) {
            return null;
        }

        $counts = self::severityCounts($project, [PackageType::Npm]);

        $worst = collect([Severity::Critical, Severity::High, Severity::Medium, Severity::Low])
            ->mapWithKeys(fn (Severity $severity): array => [
                $severity->label() => (int) $counts->max($severity->value),
            ])
            ->filter()
            ->take(2)
            ->map(fn (int $count, string $label): string => $count . ' ' . mb_strtolower($label));

        return $worst->isEmpty() ? null : voightTrans('models.project.fields.npm_hint', ['counts' => $worst->implode(', ')]);
    }

    /**
     * Distinct open vulnerabilities per severity, keyed by environment id.
     *
     * @param  array<int, PackageType>  $types
     * @return Collection<string, object>
     */
    public static function severityCounts(Project $project, array $types): Collection
    {
        return CurrentFindings::query(McpAccess::local())
            ->where('e.project_id', $project->getKey())
            ->whereIn('pk.type', array_map(fn (PackageType $type): string => $type->value, $types))
            ->groupBy('e.id')
            ->select(['e.id', ...CurrentFindings::severityColumns(distinctVulnerabilities: true)])
            ->get()
            ->keyBy('id');
    }
}
