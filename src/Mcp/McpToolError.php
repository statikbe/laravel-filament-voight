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

    public static function invalidVersionFilter(string $filter): self
    {
        return new self("Invalid version filter `{$filter}`. Use one or more comparisons (<, <=, >, >=, =) followed by a version, separated by spaces, e.g. `<4.17.21` or `>=7.0 <7.4`.");
    }

    /**
     * Also used for projects the caller may not see, so project codes do not leak.
     */
    public static function projectNotFound(string $projectCode): self
    {
        return new self("Project `{$projectCode}` not found. Use `list_projects` to find project codes.");
    }
}
