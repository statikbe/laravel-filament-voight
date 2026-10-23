<?php

namespace Statikbe\FilamentVoight\Resources\EnvironmentResource\Pages;

use Filament\Pages\Page;
use Filament\Resources\Concerns\HasTabs;
use Filament\Schemas\Components\EmbeddedTable;
use Filament\Schemas\Components\Tabs\Tab;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Concerns\InteractsWithTable;
use Filament\Tables\Contracts\HasTable;
use Filament\Tables\Table;
use Illuminate\Contracts\Support\Htmlable;
use Illuminate\Database\Eloquent\Builder;
use Livewire\Attributes\Url;
use Statikbe\FilamentVoight\Enums\EnvironmentStage;
use Statikbe\FilamentVoight\Facades\FilamentVoight;
use Statikbe\FilamentVoight\Models\Environment;
use Statikbe\FilamentVoight\Resources\Concerns\HasCountedTabs;
use Statikbe\FilamentVoight\Resources\EnvironmentResource\Schemas\EnvironmentTableSchema;
use Statikbe\FilamentVoight\Resources\ProjectResource;

/**
 * Cross-project overview. The nested EnvironmentResource cannot list without a parent project,
 * so this is a plain page that links its rows to the nested view page.
 */
class ListEnvironments extends Page implements HasTable
{
    use HasCountedTabs;
    use HasTabs;
    use InteractsWithTable;

    protected static string | \BackedEnum | null $navigationIcon = Heroicon::OutlinedServerStack;

    protected static ?int $navigationSort = 2;

    protected static ?string $slug = 'environments';

    #[Url(as: 'tab')]
    public ?string $activeTab = null;

    public function mount(): void
    {
        $this->loadDefaultActiveTab();
    }

    public static function canAccess(): bool
    {
        return ProjectResource::canViewAny();
    }

    public static function getNavigationGroup(): ?string
    {
        return voightTrans('navigation.monitoring');
    }

    public static function getNavigationBadge(): ?string
    {
        $query = static::getBaseQuery();
        Environment::constrainToFailing($query);
        $count = $query->count();

        return $count > 0 ? (string) $count : null;
    }

    public static function getNavigationBadgeColor(): string
    {
        return 'danger';
    }

    public static function getNavigationBadgeTooltip(): string | Htmlable | null
    {
        return voightTrans('navigation.badges.failing_environments');
    }

    /**
     * Follows whatever scoping the host applies to the project list.
     *
     * @return Builder<Environment>
     */
    protected static function getBaseQuery(): Builder
    {
        $environmentModel = FilamentVoight::config()->getEnvironmentModel();

        return $environmentModel::query()->whereIn('project_id', ProjectResource::getEloquentQuery()->select('id'));
    }

    /**
     * @return array<string, Tab>
     */
    public function getTabs(): array
    {
        return [
            'all' => $this->allTab(),
            'production' => $this->countedTab('tabs.environments.production', fn (Builder $query) => Environment::constrainToStages($query, EnvironmentStage::Production)),
            'failing' => $this->countedTab('tabs.environments.failing', Environment::constrainToFailing(...)),
            'warnings' => $this->countedTab('tabs.environments.warnings', Environment::constrainToSyncWarnings(...)),
        ];
    }

    protected function getTabBaseQuery(): Builder
    {
        return static::getBaseQuery();
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
        return $schema->components([$this->getTabsContentComponent(), EmbeddedTable::make()]);
    }

    public function table(Table $table): Table
    {
        return EnvironmentTableSchema::configure(
            $table->query(static::getBaseQuery())->modifyQueryUsing($this->modifyQueryWithActiveTab(...)),
        );
    }
}
