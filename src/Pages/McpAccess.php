<?php

namespace Statikbe\FilamentVoight\Pages;

use Filament\Actions\Action;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Schemas\Components\Callout;
use Filament\Schemas\Components\EmbeddedTable;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Concerns\InteractsWithTable;
use Filament\Tables\Contracts\HasTable;
use Filament\Tables\Table;
use Illuminate\Contracts\Support\Htmlable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Js;
use Laravel\Sanctum\Contracts\HasApiTokens as HasApiTokensContract;
use Laravel\Sanctum\HasApiTokens;
use Laravel\Sanctum\PersonalAccessToken;
use Laravel\Sanctum\Sanctum;
use Statikbe\FilamentVoight\Facades\FilamentVoight;
use Statikbe\FilamentVoight\Http\Middleware\EnsureMcpUserToken;

/**
 * Lets the current user manage their personal tokens for the remote MCP server.
 */
class McpAccess extends Page implements HasTable
{
    use InteractsWithTable;

    protected static string | \BackedEnum | null $navigationIcon = Heroicon::OutlinedCommandLine;

    protected static ?int $navigationSort = 100;

    protected static ?string $slug = 'mcp-access';

    public static function canAccess(): bool
    {
        return FilamentVoight::config()->mcpEnabled() && auth()->check();
    }

    public static function getNavigationGroup(): ?string
    {
        return voightTrans('navigation.settings');
    }

    public static function getNavigationLabel(): string
    {
        return voightTrans('pages.mcp_access.title');
    }

    public function getTitle(): string | Htmlable
    {
        return voightTrans('pages.mcp_access.title');
    }

    public function getSubheading(): ?string
    {
        return voightTrans('pages.mcp_access.description');
    }

    public static function userCanHaveTokens(): bool
    {
        return in_array(HasApiTokens::class, class_uses_recursive(FilamentVoight::config()->getUserModel()), true);
    }

    public function content(Schema $schema): Schema
    {
        if (! static::userCanHaveTokens()) {
            return $schema->components([
                Callout::make(voightTrans('pages.mcp_access.missing_tokens_heading'))
                    ->description(voightTrans('pages.mcp_access.missing_tokens_body', ['model' => FilamentVoight::config()->getUserModel()]))
                    ->warning(),
            ]);
        }

        return $schema->components([EmbeddedTable::make()]);
    }

    public function table(Table $table): Table
    {
        return $table
            ->query(fn (): Builder => $this->tokensQuery())
            ->columns([
                TextColumn::make('name')
                    ->label(voightTrans('pages.mcp_access.fields.name')),
                TextColumn::make('last_used_at')
                    ->label(voightTrans('pages.mcp_access.fields.last_used'))
                    ->since()
                    ->dateTimeTooltip()
                    ->placeholder(voightTrans('pages.mcp_access.fields.never_used')),
                TextColumn::make('created_at')
                    ->label(voightTrans('fields.created_at'))
                    ->since()
                    ->dateTimeTooltip(),
            ])
            ->recordActions([
                Action::make('revoke')
                    ->label(voightTrans('pages.mcp_access.actions.revoke'))
                    ->color('danger')
                    ->requiresConfirmation()
                    ->action(function (PersonalAccessToken $record): void {
                        $record->delete();

                        Notification::make()
                            ->title(voightTrans('pages.mcp_access.actions.revoked'))
                            ->success()
                            ->send();
                    }),
            ])
            ->emptyStateHeading(voightTrans('pages.mcp_access.no_tokens'))
            ->paginated(false);
    }

    /**
     * @return array<Action>
     */
    protected function getHeaderActions(): array
    {
        return [
            Action::make('create_token')
                ->label(voightTrans('pages.mcp_access.actions.create'))
                ->visible(static::userCanHaveTokens())
                ->schema([
                    TextInput::make('name')
                        ->label(voightTrans('pages.mcp_access.fields.name'))
                        ->placeholder('Claude Code on my laptop')
                        ->required()
                        ->maxLength(255),
                ])
                ->action(function (array $data): void {
                    /** @var HasApiTokensContract $user */
                    $user = auth()->user();
                    $token = $user->createToken($data['name'], [EnsureMcpUserToken::ABILITY]);

                    Notification::make()
                        ->title(voightTrans('pages.mcp_access.actions.created'))
                        ->body(view('filament-voight::mcp.token-created', [
                            'token' => $token->plainTextToken,
                            'url' => url(FilamentVoight::config()->getMcpPath()),
                        ])->render())
                        ->persistent()
                        ->actions([
                            Action::make('copy_token')
                                ->label(voightTrans('pages.mcp_access.actions.copy'))
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
        ];
    }

    /**
     * The current user's tokens that carry the MCP ability.
     *
     * @return Builder<PersonalAccessToken>
     */
    protected function tokensQuery(): Builder
    {
        $user = auth()->user();

        return Sanctum::$personalAccessTokenModel::query()
            ->where('tokenable_type', $user?->getMorphClass())
            ->where('tokenable_id', $user?->getAuthIdentifier())
            ->where('abilities', 'like', '%' . json_encode(EnsureMcpUserToken::ABILITY) . '%')
            ->latest();
    }
}
