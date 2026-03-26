<?php

use Illuminate\Support\Str;
use Statikbe\FilamentVoight\FilamentVoightPlugin;

function voightTrans(string $translationKey, array $replace = [], ?string $locale = null): string
{
    $namespace = Str::after(FilamentVoightPlugin::ID, 'laravel-');

    return trans($namespace . '::' . $namespace . '.' . $translationKey, $replace, $locale);
}
