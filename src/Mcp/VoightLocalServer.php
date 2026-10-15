<?php

namespace Statikbe\FilamentVoight\Mcp;

/**
 * The local server, over stdio (`php artisan mcp:start voight`). Runs as the machine's
 * operator: sees every project and skips policies.
 */
class VoightLocalServer extends VoightServer
{
    protected function access(): McpAccess
    {
        return McpAccess::local();
    }
}
