<?php

namespace Statikbe\FilamentVoight;

class FilamentVoight
{
    public function config(): FilamentVoightConfig
    {
        return app(FilamentVoightConfig::class);
    }
}
