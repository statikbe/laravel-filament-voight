<?php

namespace Statikbe\FilamentVoight\Commands;

use Illuminate\Console\Command;

class FilamentVoightCommand extends Command
{
    public $signature = 'laravel-filament-voight';

    public $description = 'Filament Voight base command';

    public function handle(): int
    {
        $this->comment('All done');

        return self::SUCCESS;
    }
}
