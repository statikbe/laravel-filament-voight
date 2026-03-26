<?php

use Statikbe\FilamentVoight\FilamentVoightPlugin;

function voightTrans(string $translationKey, array $replace = [], ?string $locale = null): string
{
    return trans(FilamentVoightPlugin::ID . '::' . $translationKey, $replace, $locale);
}
