<?php

namespace Statikbe\FilamentVoight\Resources\EnvironmentResource\Pages;

use Closure;
use Filament\Pages\Page;
use Filament\Schemas\Components\EmbeddedTable;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Concerns\InteractsWithTable;
use Filament\Tables\Contracts\HasTable;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Contracts\Support\Htmlable;
use Illuminate\Database\Eloquent\Builder;
use Statikbe\FilamentVoight\Facades\FilamentVoight;
use Statikbe\FilamentVoight\Models\Environment;
use Statikbe\FilamentVoight\Models\EnvironmentSystemDetail;
use Statikbe\FilamentVoight\Resources\EnvironmentResource;
use Statikbe\FilamentVoight\Resources\ProjectResource;

/**
 * Cross-project overview. The nested EnvironmentResource cannot list without a parent project,
 * so this is a plain page that links its rows to the nested view page.
 */
class ListEnvironments extends Page implements HasTable
{
    use InteractsWithTable;

    protected static string | \BackedEnum | null $navigationIcon = Heroicon::OutlinedServerStack;

    protected static ?int $navigationSort = 4;

    protected static ?string $slug = 'environments';

    private const array VERSION_COLUMNS = ['php_version', 'laravel_version', 'filament_version', 'livewire_version'];

    public static function canAccess(): bool
    {
        return ProjectResource::canViewAny();
    }

    public static function getNavigationGroup(): ?string
    {
        return voightTrans('navigation.management');
    }

    public static function getNavigationLabel(): string
    {
        return voightTrans('models.environment.plural');
    }

    public function getTitle(): string | Htmlable
    {
        return voightTrans('models.environment.plural');
    }

    public function content(Schema $schema): Schema
    {
        return $schema->components([EmbeddedTable::make()]);
    }

    public function table(Table $table): Table
    {
        $environmentModel = FilamentVoight::config()->getEnvironmentModel();

        // Follows whatever scoping the host applies to the project list.
        $projectIds = fn (): Builder => ProjectResource::getEloquentQuery()->select('id');

        $columns = [
            TextColumn::make('project.name')
                ->label(voightTrans('models.environment.fields.project'))
                ->searchable(),
            TextColumn::make('name')
                ->label(voightTrans('models.environment.fields.name'))
                ->searchable()
                ->sortable(),
        ];

        // Sorts through a correlated subquery so no join is needed next to the eager-loaded relation.
        $sortBy = fn (string $column): Closure => fn (Builder $query, string $direction): Builder => $query->orderBy(
            EnvironmentSystemDetail::query()->select($column)->whereColumn('environment_id', (new $environmentModel)->getQualifiedKeyName()),
            $direction,
        );

        foreach (self::VERSION_COLUMNS as $column) {
            $columns[] = TextColumn::make("systemDetails.{$column}")
                ->label(voightTrans("models.environment.fields.{$column}"))
                ->placeholder('—')
                ->sortable(query: $sortBy($column));
        }

        $columns[] = TextColumn::make('systemDetails.received_at')
            ->label(voightTrans('models.environment.fields.system_details_received_at'))
            ->since()
            ->placeholder(voightTrans('models.environment.never_reported'))
            ->sortable(query: $sortBy('received_at'));

        return $table
            ->query($environmentModel::query()->whereIn('project_id', $projectIds())->with(['project', 'systemDetails']))
            ->columns($columns)
            ->filters(array_map(
                fn (string $column): SelectFilter => SelectFilter::make($column)
                    ->label(voightTrans("models.environment.fields.{$column}"))
                    ->options(fn (): array => EnvironmentSystemDetail::query()
                        ->whereIn('environment_id', $environmentModel::query()->whereIn('project_id', $projectIds())->select('id'))
                        ->whereNotNull($column)
                        ->distinct()
                        ->orderBy($column)
                        ->pluck($column, $column)
                        ->all())
                    ->query(fn (Builder $query, array $data): Builder => $query->when(
                        filled($data['value'] ?? null),
                        fn (Builder $query): Builder => $query->whereHas('systemDetails', fn (Builder $query): Builder => $query->where($column, $data['value'])),
                    )),
                self::VERSION_COLUMNS,
            ))
            ->recordUrl(fn (Environment $record): string => EnvironmentResource::getUrl('view', [
                'project' => $record->project,
                'record' => $record,
            ]))
            ->defaultSort('name');
    }
}
