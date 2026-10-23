<?php

namespace Statikbe\FilamentVoight\Resources\Concerns;

use Filament\Schemas\Components\Tabs\Tab;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

/**
 * Preset tabs whose badge counts the same scope the tab applies, over the page's own base query.
 */
trait HasCountedTabs
{
    /**
     * @return Builder<Model>
     */
    protected function getTabBaseQuery(): Builder
    {
        return static::getResource()::getEloquentQuery();
    }

    /**
     * @param  callable(Builder<Model>): mixed  $scope
     */
    protected function countedTab(string $labelKey, callable $scope): Tab
    {
        return Tab::make(voightTrans($labelKey))
            ->modifyQueryUsing(function (Builder $query) use ($scope): Builder {
                $scope($query);

                return $query;
            })
            ->badge(fn (): int => $this->getTabBaseQuery()->where(fn (Builder $query) => $scope($query))->count())
            ->deferBadge();
    }

    protected function allTab(): Tab
    {
        return Tab::make(voightTrans('tabs.all'))
            ->badge(fn (): int => $this->getTabBaseQuery()->count())
            ->deferBadge();
    }
}
