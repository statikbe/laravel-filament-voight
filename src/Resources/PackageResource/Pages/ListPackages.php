<?php

namespace Statikbe\FilamentVoight\Resources\PackageResource\Pages;

use Filament\Resources\Pages\ListRecords;
use Filament\Schemas\Components\Tabs\Tab;
use Illuminate\Database\Eloquent\Builder;
use Statikbe\FilamentVoight\Models\AuditFinding;
use Statikbe\FilamentVoight\Resources\Concerns\HasCountedTabs;
use Statikbe\FilamentVoight\Resources\PackageResource;

class ListPackages extends ListRecords
{
    use HasCountedTabs;

    protected static string $resource = PackageResource::class;

    /**
     * @return array<string, Tab>
     */
    public function getTabs(): array
    {
        return [
            'vulnerable_in_use' => $this->countedTab(
                'tabs.packages.vulnerable_in_use',
                fn (Builder $query): Builder => $query->whereHas('findings', fn (Builder $f): Builder => AuditFinding::constrainToCurrent($f)),
            ),
            'all' => $this->allTab(),
        ];
    }
}
