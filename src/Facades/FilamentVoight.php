<?php

namespace Statikbe\FilamentVoight\Facades;

use Illuminate\Support\Facades\Facade;
use Statikbe\FilamentVoight\FilamentVoightConfig;

/**
 * @method static FilamentVoightConfig config()
 *
 * @see \Statikbe\FilamentVoight\FilamentVoight
 */
class FilamentVoight extends Facade
{
    protected static function getFacadeAccessor(): string
    {
        return \Statikbe\FilamentVoight\FilamentVoight::class;
    }
}
