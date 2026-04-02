<?php

namespace Statikbe\FilamentVoight\Facades;

use Illuminate\Support\Facades\Facade;
use Statikbe\FilamentVoight\FilamentVoight as FilamentVoightManager;
use Statikbe\FilamentVoight\FilamentVoightConfig;

/**
 * @method static FilamentVoightConfig config()
 *
 * @see FilamentVoightManager
 */
class FilamentVoight extends Facade
{
    protected static function getFacadeAccessor(): string
    {
        return FilamentVoightManager::class;
    }
}
