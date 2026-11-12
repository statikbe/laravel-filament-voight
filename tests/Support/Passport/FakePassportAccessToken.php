<?php

namespace Statikbe\FilamentVoight\Tests\Support\Passport;

/**
 * Stands in for Laravel\Passport\AccessToken (Passport 13+), which this package
 * does not depend on: same attribute access, aliased to Passport's class name
 * by registerFakePassportAccessToken().
 */
class FakePassportAccessToken
{
    /**
     * @param  array<string, mixed>  $attributes
     */
    public function __construct(protected array $attributes = []) {}

    public function __isset(string $key): bool
    {
        return isset($this->attributes[$key]);
    }

    public function __get(string $key): mixed
    {
        return $this->attributes[$key] ?? null;
    }
}
