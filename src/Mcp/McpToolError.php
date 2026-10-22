<?php

namespace Statikbe\FilamentVoight\Mcp;

use RuntimeException;

/**
 * An error the model can act on. Tools turn it into an MCP error result with this message.
 */
class McpToolError extends RuntimeException
{
    public static function unauthenticated(): self
    {
        return new self('Unauthenticated: this MCP server requires a Voight user token.');
    }

    public static function notAllowed(): self
    {
        return new self('You are not allowed to do this.');
    }

    /**
     * Also used for projects the caller may not see, so project codes do not leak.
     */
    public static function projectNotFound(string $projectCode): self
    {
        return new self("Project `{$projectCode}` not found. Use `list_projects` to find project codes.");
    }
}
