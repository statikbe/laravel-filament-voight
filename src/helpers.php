<?php

use Statikbe\FilamentVoight\FilamentVoightPlugin;

function voightTrans(string $translationKey, array $replace = [], ?string $locale = null): string
{
    return trans(FilamentVoightPlugin::ID . '::' . FilamentVoightPlugin::ID . '.' . $translationKey, $replace, $locale);
}

function voightTransChoice(string $translationKey, int $count, array $replace = [], ?string $locale = null): string
{
    return trans_choice(FilamentVoightPlugin::ID . '::' . FilamentVoightPlugin::ID . '.' . $translationKey, $count, $replace, $locale);
}
