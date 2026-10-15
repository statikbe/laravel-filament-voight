<?php

namespace Statikbe\FilamentVoight\Resources\Components;

use Filament\Tables\Columns\TextColumn;
use Statikbe\FilamentVoight\Enums\EnvironmentStage;

/**
 * An environment name as a badge coloured by its stage.
 */
class EnvironmentNameColumn extends TextColumn
{
    protected function setUp(): void
    {
        parent::setUp();

        $this
            ->badge()
            ->color(fn (?string $state): string | array => EnvironmentStage::fromName($state)->getColor());
    }
}
