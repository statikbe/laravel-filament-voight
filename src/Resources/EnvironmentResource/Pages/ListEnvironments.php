<?php

namespace Statikbe\FilamentVoight\Resources\EnvironmentResource\Pages;

use Filament\Pages\Page;
use Filament\Schemas\Components\EmbeddedTable;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Concerns\InteractsWithTable;
use Filament\Tables\Contracts\HasTable;
use Filament\Tables\Table;
use Illuminate\Contracts\Support\Htmlable;
use Statikbe\FilamentVoight\Facades\FilamentVoight;
use Statikbe\FilamentVoight\Resources\EnvironmentResource\Schemas\EnvironmentTableSchema;
/**
 * Cross-project overview. The nested EnvironmentResource cannot list without a parent project,
 * so this is a plain page that links its rows to the nested view page.
 */
use Statikbe\FilamentVoight\Resources\ProjectResource;

class ListEnvironments extends Page implements HasTable
{
    use InteractsWithTable;

    protected static string | \BackedEnum | null $navigationIcon = Heroicon::OutlinedServerStack;

    protected static ?int $navigationSort = 4;

    protected static ?string $slug = 'environments';

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
        return EnvironmentTableSchema::configure(
            $table->query($environmentModel::query()->whereIn('project_id', ProjectResource::getEloquentQuery()->select('id'))),
        );
    }
}
