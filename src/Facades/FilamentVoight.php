<?php

namespace Statikbe\FilamentVoight\Facades;

use Illuminate\Support\Facades\Facade;
use Statikbe\FilamentVoight\FilamentVoight as FilamentVoightManager;
use Statikbe\FilamentVoight\FilamentVoightConfig;

/**
 * @method static FilamentVoightConfig config()
 * @method static void resolveMcpAdminUsing(\Closure $resolver)
 * @method static void resolveMcpVisibleProjectsUsing(\Closure $resolver)
 * @method static bool isMcpAdmin(\Illuminate\Contracts\Auth\Authenticatable $user)
 * @method static \Closure|null getMcpVisibleProjectsResolver()
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
