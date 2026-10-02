<?php

namespace Statikbe\FilamentVoight\Resources\ProjectResource\Schemas;

use Filament\Schemas\Components\Callout;
use Filament\Schemas\Components\Group;
use Illuminate\Support\Collection;
use Statikbe\FilamentVoight\Models\Environment;
use Statikbe\FilamentVoight\Models\Project;
use Statikbe\FilamentVoight\Services\EnvironmentHealthService;
use Statikbe\FilamentVoight\Support\EnvironmentIssue;

/**
 * One callout per environment of the project that has issues; nothing when all are healthy.
 */
class EnvironmentHealthCallouts
{
    public static function make(): Group
    {
        return Group::make()
            ->components(fn (?Project $record): array => $record === null ? [] : self::callouts($record))
            ->columnSpanFull();
    }

    /**
     * @return array<int, Callout>
     */
    private static function callouts(Project $project): array
    {
        $healthService = app(EnvironmentHealthService::class);

        return $project->environments()
            ->with(Environment::HEALTH_RELATIONS)
            ->orderBy('name')
            ->get()
            ->map(function (Environment $environment) use ($healthService): ?Callout {
                $issues = $healthService->issuesFor($environment);

                return $issues->isEmpty() ? null : self::callout($environment, $issues);
            })
            ->filter()
            ->values()
            ->all();
    }

    /**
     * @param  Collection<int, EnvironmentIssue>  $issues  worst first
     */
    private static function callout(Environment $environment, Collection $issues): Callout
    {
        $worstType = $issues->first()->type;

        return Callout::make($environment->name)
            ->status($worstType->getColor())
            ->icon($worstType->getIcon())
            ->description(view('filament-voight::environment-health.messages', ['issues' => $issues]));
    }
}
