<?php

namespace Statikbe\FilamentVoight\Support;

use BackedEnum;
use Illuminate\Contracts\Support\Htmlable;

/**
 * One card on the MCP setup page. The button either opens $buttonUrl, copying
 * $copies on the way when set, or only copies $copies.
 */
final readonly class McpClient
{
    public function __construct(
        public string $key,
        public string $name,
        public string $description,
        public string | BackedEnum | Htmlable $icon,
        public string $buttonLabel,
        public ?string $buttonUrl = null,
        public ?string $copies = null,
        public bool $opensNewTab = false,
        public ?string $snippet = null,
        public ?string $hint = null,
    ) {}
}
