<?php

namespace Statikbe\FilamentVoight\Resources\ProjectResource\RelationManagers;

use Filament\Actions\CreateAction;
use Filament\Actions\DeleteAction;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\EditAction;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Resources\RelationManagers\RelationManager;
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
use Statikbe\FilamentVoight\Enums\EnvironmentIssueType;
use Statikbe\FilamentVoight\Models\Environment;
use Statikbe\FilamentVoight\Resources\EnvironmentResource\Schemas\EnvironmentTableSchema;
use Statikbe\FilamentVoight\Services\EnvironmentHealthService;
use Statikbe\FilamentVoight\Support\EnvironmentIssue;

class EnvironmentsRelationManager extends RelationManager
{
    private const string HEALTHY = 'healthy';

    protected static string $relationship = 'environments';

    protected static string | \BackedEnum | null $icon = Heroicon::OutlinedServerStack;

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
                TextInput::make('url')
                    ->label(voightTrans('models.environment.fields.url'))
                    ->helperText(voightTrans('models.environment.fields.url_help'))
                    ->url()
                    ->maxLength(2048),
                Toggle::make('scan_nightly')
                    ->label(voightTrans('models.environment.fields.scan_nightly'))
                    ->default(true),
            ]);
    }

    public function table(Table $table): Table
    {
        // The project is implied by the page, so its column and constraints are hidden here.
        return EnvironmentTableSchema::configure($table, showProject: false)
            ->modifyQueryUsing(fn (Builder $query) => $query->with(Environment::HEALTH_RELATIONS))
            ->pushColumns([
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
                TextColumn::make('environment_packages_count')
                    ->label(voightTrans('models.package.plural'))
                    ->counts(['environmentPackages' => fn (Builder $query) => $query->select(DB::raw('count(distinct package_id)'))])
                    ->sortable(),
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

    /**
     * @return Collection<int, EnvironmentIssue> worst first
     */
    private function issues(Environment $environment): Collection
    {
        return app(EnvironmentHealthService::class)->issuesFor($environment);
    }
}
