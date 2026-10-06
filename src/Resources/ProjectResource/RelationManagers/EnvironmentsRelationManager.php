<?php

namespace Statikbe\FilamentVoight\Resources\ProjectResource\RelationManagers;

use Filament\Actions\Action;
use Filament\Actions\CreateAction;
use Filament\Actions\DeleteAction;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\EditAction;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Infolists\Components\KeyValueEntry;
use Filament\Infolists\Components\TextEntry;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Columns\ToggleColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Statikbe\FilamentVoight\Enums\EnvironmentIssueType;
use Statikbe\FilamentVoight\Models\Environment;
use Statikbe\FilamentVoight\Services\EnvironmentHealthService;
use Statikbe\FilamentVoight\Support\EnvironmentIssue;

class EnvironmentsRelationManager extends RelationManager
{
    private const string HEALTHY = 'healthy';

    protected static string $relationship = 'environments';

    public static function getTitle(Model $ownerRecord, string $pageClass): string
    {
        return voightTrans('models.environment.plural');
    }

    public function form(Schema $schema): Schema
    {
        return $schema
            ->components([
                TextInput::make('name')
                    ->label(voightTrans('models.environment.fields.name'))
                    ->required()
                    ->maxLength(255),
                Toggle::make('scan_nightly')
                    ->label(voightTrans('models.environment.fields.scan_nightly'))
                    ->default(true),
            ]);
    }

    public function table(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(fn (Builder $query) => $query->with(Environment::HEALTH_RELATIONS))
            ->columns([
                TextColumn::make('name')
                    ->label(voightTrans('models.environment.fields.name'))
                    ->sortable(),
                IconColumn::make('health')
                    ->label(voightTrans('models.environment.fields.health'))
                    ->state(fn (Environment $record): string => $this->issues($record)->first()?->type->value ?? self::HEALTHY)
                    ->icon(fn (string $state): string => EnvironmentIssueType::tryFrom($state)?->getIcon() ?? 'heroicon-o-check-circle')
                    ->color(fn (string $state): string => EnvironmentIssueType::tryFrom($state)?->getColor() ?? 'success')
                    ->tooltip(fn (Environment $record): string => $this->issues($record)
                        ->map(fn (EnvironmentIssue $issue): string => $issue->message)
                        ->implode("\n") ?: voightTrans('models.environment.healthy')),
                ToggleColumn::make('scan_nightly')
                    ->label(voightTrans('models.environment.fields.scan_nightly'))
                    ->sortable(),
                TextColumn::make('scanned_at')
                    ->label(voightTrans('models.environment.fields.scanned_at'))
                    ->dateTime()
                    ->sortable()
                    ->placeholder(voightTrans('models.environment.never_scanned')),
                TextColumn::make('system_details_received_at')
                    ->label(voightTrans('models.environment.fields.system_details_received_at'))
                    ->since()
                    ->sortable()
                    ->placeholder(voightTrans('models.environment.never_reported'))
                    ->tooltip(fn (Environment $record): ?string => collect([
                        data_get($record->system_details, 'server.php.version'),
                        data_get($record->system_details, 'server.system.os'),
                    ])->filter()->implode(' / ') ?: null),
                TextColumn::make('environment_packages_count')
                    ->label(voightTrans('models.package.plural'))
                    ->counts(['environmentPackages' => fn (Builder $query) => $query->select(DB::raw('count(distinct package_id)'))])
                    ->sortable(),
            ])
            ->headerActions([
                CreateAction::make(),
            ])
            ->recordActions([
                Action::make('systemDetails')
                    ->label(voightTrans('models.environment.system_details.action'))
                    ->icon(Heroicon::ServerStack)
                    ->slideOver()
                    ->modalHeading(fn (Environment $record): string => voightTrans('models.environment.system_details.heading', ['name' => $record->name]))
                    ->modalSubmitAction(false)
                    ->modalCancelActionLabel(voightTrans('models.environment.system_details.close'))
                    ->visible(fn (Environment $record): bool => $record->system_details !== null)
                    ->schema(fn (Schema $schema, Environment $record): Schema => $schema
                        ->record($record)
                        ->components($this->systemDetailsComponents($record))),
                EditAction::make(),
                DeleteAction::make(),
            ])
            ->toolbarActions([
                DeleteBulkAction::make(),
            ]);
    }

    /**
     * @return Collection<int, EnvironmentIssue> worst first
     */
    private function issues(Environment $environment): Collection
    {
        return app(EnvironmentHealthService::class)->issuesFor($environment);
    }

    /**
     * @return array<int, TextEntry|Section>
     */
    private function systemDetailsComponents(Environment $environment): array
    {
        $details = $environment->system_details ?? [];

        $components = [
            TextEntry::make('system_details.collected_at')
                ->label(voightTrans('models.environment.system_details.collected_at'))
                ->state($details['collected_at'] ?? null)
                ->dateTime(),
            TextEntry::make('system_details_received_at')
                ->label(voightTrans('models.environment.fields.system_details_received_at'))
                ->dateTime(),
        ];

        foreach (['server' => false, 'laravel' => true] as $group => $collapsed) {
            foreach ((array) ($details[$group] ?? []) as $key => $values) {
                // Keys are rendered as-is so fields from newer client versions need no change here.
                $components[] = Section::make(Str::headline((string) $key))
                    ->statePath("system_details.{$group}.{$key}")
                    ->collapsible()
                    ->collapsed($collapsed)
                    ->schema([
                        KeyValueEntry::make('rows')
                            ->hiddenLabel()
                            ->state($this->formatRows($values)),
                    ]);
            }
        }

        return $components;
    }

    /**
     * @return array<string, string>
     */
    private function formatRows(mixed $values): array
    {
        return collect(is_array($values) ? $values : ['value' => $values])
            ->mapWithKeys(fn (mixed $value, int | string $key): array => [
                Str::headline((string) $key) => match (true) {
                    is_bool($value) => voightTrans($value ? 'models.environment.system_details.yes' : 'models.environment.system_details.no'),
                    is_scalar($value) => (string) $value,
                    $value === null => '',
                    default => (string) json_encode($value),
                },
            ])
            ->all();
    }
}
