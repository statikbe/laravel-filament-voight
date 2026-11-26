<?php

namespace Statikbe\FilamentVoight\Support;

use Attribute;
use BackedEnum;

/**
 * Declares a VoightIcon case's default icon and what it represents, so host apps (and LLMs) can pick a fitting replacement.
 */
#[Attribute(Attribute::TARGET_CLASS_CONSTANT)]
final readonly class IconDefinition
{
    public function __construct(
        public string | BackedEnum $default,
        public string $description,
    ) {}
}
