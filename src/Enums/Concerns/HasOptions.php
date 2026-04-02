<?php

namespace Statikbe\FilamentVoight\Enums\Concerns;

trait HasOptions
{
    /**
     * @return array<string, string>
     */
    public static function options(): array
    {
        return collect(self::cases())->mapWithKeys(fn (self $case) => [$case->value => $case->label()])->all();
    }
}
