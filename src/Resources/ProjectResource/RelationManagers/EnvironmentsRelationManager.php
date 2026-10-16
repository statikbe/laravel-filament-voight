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
use Filament\Support\Enums\FontFamily;
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
use Statikbe\FilamentVoight\Models\Project;
use Statikbe\FilamentVoight\Resources\Components\EnvironmentNameColumn;
use Statikbe\FilamentVoight\Resources\EnvironmentResource;
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

    /**
     * Environments are managed from the project view page as well as the edit page.
     */
    public function isReadOnly(): bool
    {
        return false;
    }

    public static function getBadge(Model $ownerRecord, string $pageClass): ?string
    {
        $count = $ownerRecord->environments()->count();

        return $count > 0 ? (string) $count : null;
    }

    public static function getBadgeTooltip(Model $ownerRecord, string $pageClass): ?string
    {
        return voightTrans('hints.badges.project_environments');
    }

    public static function getBadgeColor(Model $ownerRecord, string $pageClass): ?string
    {
        /** @var Project $ownerRecord */
        $healthService = app(EnvironmentHealthService::class);

        $hasIssue = $ownerRecord->environments()
            ->with(Environment::HEALTH_RELATIONS)
            ->get()
            ->contains(fn (Environment $environment): bool => $healthService->issuesFor($environment)->isNotEmpty());

        return $hasIssue ? 'warning' : 'gray';
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
            ->modifyQueryUsing(fn (Builder $query) => $query->with([...Environment::HEALTH_RELATIONS, 'systemDetails']))
            ->striped()
            ->columns([
                EnvironmentNameColumn::make('name')
                    ->label(voightTrans('models.environment.fields.name'))
                    ->sortable(),
                IconColumn::make('health')
                    ->label(voightTrans('models.environment.fields.health'))
                    ->state(fn (Environment $record): string => $this->issues($record)->first()?->type->value ?? self::HEALTHY)
                    ->icon(fn (string $state): Heroicon => EnvironmentIssueType::tryFrom($state)?->getIcon() ?? Heroicon::OutlinedCheckCircle)
                    ->color(fn (string $state): string => EnvironmentIssueType::tryFrom($state)?->getColor() ?? 'success')
                    ->tooltip(fn (Environment $record): string => $this->issues($record)
                        ->map(fn (EnvironmentIssue $issue): string => $issue->message)
                        ->implode("\n") ?: voightTrans('models.environment.healthy')),
                ToggleColumn::make('scan_nightly')
                    ->label(voightTrans('models.environment.fields.scan_nightly'))
                    ->sortable(),
                TextColumn::make('scanned_at')
                    ->label(voightTrans('models.environment.fields.scanned_at'))
                    ->since()
                    ->dateTimeTooltip()
                    ->sortable()
                    ->placeholder(voightTrans('models.environment.never_scanned')),
                TextColumn::make('systemDetails.php_version')
                    ->label(voightTrans('models.environment.fields.php_version'))
                    ->fontFamily(FontFamily::Mono)
                    ->placeholder('—'),
                TextColumn::make('systemDetails.received_at')
                    ->label(voightTrans('models.environment.fields.system_details_received_at'))
                    ->since()
                    ->dateTimeTooltip()
                    ->placeholder(voightTrans('models.environment.never_reported'))
                    ->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('environment_packages_count')
                    ->label(voightTrans('models.package.plural'))
                    ->counts(['environmentPackages' => fn (Builder $query) => $query->select(DB::raw('count(distinct package_id)'))])
                    ->sortable(),
            ])
            ->recordUrl(fn (Environment $record): string => EnvironmentResource::getUrl('view', [
                'project' => $this->getOwnerRecord(),
                'record' => $record,
            ]))
            ->headerActions([
                CreateAction::make(),
            ])
            ->recordActions([
                EditAction::make(),
                DeleteAction::make(),
            ])
            ->toolbarActions([
                DeleteBulkAction::make(),
            ])
            ->emptyStateIcon(Heroicon::OutlinedServerStack)
            ->emptyStateHeading(voightTrans('models.environment.empty.heading'))
            ->emptyStateDescription(voightTrans('models.environment.empty.description'));
    }

    /**
     * @return Collection<int, EnvironmentIssue> worst first
     */
    private function issues(Environment $environment): Collection
    {
        return app(EnvironmentHealthService::class)->issuesFor($environment);
    }
}
