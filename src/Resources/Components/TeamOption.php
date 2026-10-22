<?php

namespace Statikbe\FilamentVoight\Resources\Components;

use Statikbe\FilamentVoight\Models\Team;

/**
 * A team as a select option: its avatar followed by its name. Selects using
 * it must call allowHtml().
 */
class TeamOption
{
    public static function label(Team $team, string $size = 'sm'): string
    {
        return view('filament-voight::team.avatar-name', ['team' => $team, 'size' => $size])->render();
    }

    /**
     * @return array<string, string>
     */
    public static function options(): array
    {
        return Team::query()
            ->orderBy('name')
            ->get()
            ->mapWithKeys(fn (Team $team): array => [$team->id => self::label($team)])
            ->all();
    }
}
