<?php

namespace Statikbe\FilamentVoight\Resources\PackageResource\Pages;

use Filament\Resources\Pages\ViewRecord;
use Filament\Schemas\Components\EmbeddedSchema;
use Filament\Schemas\Components\Tabs\Tab;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Illuminate\Contracts\Support\Htmlable;
use Statikbe\FilamentVoight\Models\Package;
use Statikbe\FilamentVoight\Resources\PackageResource;
use Statikbe\FilamentVoight\Resources\PackageResource\Actions\OpenPackageWebsiteAction;
use Statikbe\FilamentVoight\Resources\PackageResource\Schemas\PackageInfolistSchema;

class ViewPackage extends ViewRecord
{
    protected static string $resource = PackageResource::class;

    public function infolist(Schema $schema): Schema
    {
        return PackageInfolistSchema::configure($schema);
    }

    protected function getHeaderActions(): array
    {
        return [
            OpenPackageWebsiteAction::make(),
        ];
    }

    public function getTitle(): string | Htmlable
    {
        return $this->getRecordTitle();
    }

    public function getSubheading(): ?string
    {
        /** @var Package $package */
        $package = $this->getRecord();

        return collect([$package->type->label(), $package->latest_version])
            ->filter()
            ->implode(' · ') ?: null;
    }

    public function hasCombinedRelationManagerTabsWithContent(): bool
    {
        return true;
    }

    public function getContentTabComponent(): Tab
    {
        return Tab::make($this->getContentTabLabel())
            ->icon($this->getContentTabIcon())
            ->schema([
                EmbeddedSchema::make('infolist'),
            ]);
    }

    public function getContentTabLabel(): ?string
    {
        return voightTrans('models.package.view.overview_tab');
    }

    public function getContentTabIcon(): Heroicon
    {
        return Heroicon::OutlinedSquares2x2;
    }
}
