<?php

namespace Statikbe\FilamentVoight\Resources\Components;

use Filament\Infolists\Components\TextEntry;
use Statikbe\FilamentVoight\Enums\EnvironmentStage;

/**
 * An environment name as a badge coloured by its stage.
 */
class EnvironmentNameEntry extends TextEntry
{
    protected function setUp(): void
    {
        parent::setUp();

        $this
            ->badge()
            ->color(fn (?string $state): string | array => EnvironmentStage::fromName($state)->getColor());
    }
}
