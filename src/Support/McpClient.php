<?php

namespace Statikbe\FilamentVoight\Support;

use Filament\Support\Icons\Heroicon;

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
        public Heroicon $icon,
        public string $buttonLabel,
        public ?string $buttonUrl = null,
        public ?string $copies = null,
        public bool $opensNewTab = false,
        public ?string $snippet = null,
        public ?string $hint = null,
    ) {}
}
