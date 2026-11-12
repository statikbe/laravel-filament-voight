<?php

namespace Statikbe\FilamentVoight\Support;

use Filament\Support\Icons\Heroicon;
use Statikbe\FilamentVoight\Facades\FilamentVoight;

/**
 * Builds the per-client setup instructions for the remote MCP server, so the
 * commands and install links live in one place. Every client signs in through
 * OAuth, so no link or command ever holds a credential.
 *
 * Formats follow each client's docs:
 * - claude.ai: https://claude.com/docs/connectors/custom/remote-mcp
 * - ChatGPT: https://developers.openai.com/api/docs/guides/custom-mcp-server
 * - Cursor: https://cursor.com/docs/mcp/install-links
 * - VS Code: https://code.visualstudio.com/api/extension-guides/ai/mcp
 * - Claude Code: https://code.claude.com/docs/en/mcp
 * - Codex CLI: https://developers.openai.com/codex/mcp
 */
final readonly class McpClientSetup
{
    public const string SERVER_NAME = 'voight';

    public const string CLAUDE_CONNECTORS_URL = 'https://claude.ai/customize/connectors';

    public const string CHATGPT_PLUGINS_URL = 'https://chatgpt.com/plugins';

    public function __construct(
        public string $url,
        public bool $oauthAvailable,
    ) {}

    public static function make(): self
    {
        $config = FilamentVoight::config();

        return new self(url($config->getMcpPath()), $config->mcpOAuthAvailable());
    }

    /**
     * The cards in display order.
     *
     * @return list<McpClient>
     */
    public function clients(): array
    {
        return [
            $this->claude(),
            $this->chatGpt(),
            $this->cursor(),
            $this->vsCode(),
            $this->claudeCode(),
            $this->codex(),
        ];
    }

    public function claudeCodeCommand(): string
    {
        return 'claude mcp add --transport http ' . self::SERVER_NAME . ' ' . $this->url;
    }

    public function codexCommand(): string
    {
        return 'codex mcp add ' . self::SERVER_NAME . ' --url ' . $this->url;
    }

    public function cursorInstallUrl(): string
    {
        $config = base64_encode((string) json_encode(['url' => $this->url], JSON_UNESCAPED_SLASHES));

        return 'cursor://anysphere.cursor-deeplink/mcp/install?name=' . self::SERVER_NAME . '&config=' . rawurlencode($config);
    }

    public function vsCodeInstallUrl(): string
    {
        $config = ['name' => self::SERVER_NAME, 'type' => 'http', 'url' => $this->url];

        return 'vscode:mcp/install?' . rawurlencode((string) json_encode($config, JSON_UNESCAPED_SLASHES));
    }

    private function claude(): McpClient
    {
        return new McpClient(
            key: 'claude',
            name: voightTrans('pages.mcp_access.clients.claude.name'),
            description: voightTrans('pages.mcp_access.clients.claude.description'),
            icon: Heroicon::OutlinedChatBubbleLeftRight,
            buttonLabel: voightTrans('pages.mcp_access.clients.claude.button'),
            buttonUrl: self::CLAUDE_CONNECTORS_URL,
            copies: $this->url,
            opensNewTab: true,
            snippet: $this->url,
            hint: voightTrans('pages.mcp_access.clients.claude.hint'),
        );
    }

    private function chatGpt(): McpClient
    {
        return new McpClient(
            key: 'chatgpt',
            name: voightTrans('pages.mcp_access.clients.chatgpt.name'),
            description: voightTrans('pages.mcp_access.clients.chatgpt.description'),
            icon: Heroicon::OutlinedChatBubbleOvalLeftEllipsis,
            buttonLabel: voightTrans('pages.mcp_access.clients.chatgpt.button'),
            buttonUrl: self::CHATGPT_PLUGINS_URL,
            copies: $this->url,
            opensNewTab: true,
            snippet: $this->url,
            hint: voightTrans('pages.mcp_access.clients.chatgpt.hint'),
        );
    }

    private function cursor(): McpClient
    {
        return new McpClient(
            key: 'cursor',
            name: 'Cursor',
            description: voightTrans('pages.mcp_access.clients.cursor.description'),
            icon: Heroicon::OutlinedCodeBracketSquare,
            buttonLabel: voightTrans('pages.mcp_access.clients.cursor.button'),
            buttonUrl: $this->cursorInstallUrl(),
            hint: voightTrans('pages.mcp_access.clients.oauth_sign_in_hint'),
        );
    }

    private function vsCode(): McpClient
    {
        return new McpClient(
            key: 'vscode',
            name: 'VS Code',
            description: voightTrans('pages.mcp_access.clients.vscode.description'),
            icon: Heroicon::OutlinedCodeBracket,
            buttonLabel: voightTrans('pages.mcp_access.clients.vscode.button'),
            buttonUrl: $this->vsCodeInstallUrl(),
            hint: voightTrans('pages.mcp_access.clients.oauth_sign_in_hint'),
        );
    }

    private function claudeCode(): McpClient
    {
        $command = $this->claudeCodeCommand();

        return new McpClient(
            key: 'claude-code',
            name: 'Claude Code',
            description: voightTrans('pages.mcp_access.clients.claude_code.description'),
            icon: Heroicon::OutlinedCommandLine,
            buttonLabel: voightTrans('pages.mcp_access.clients.copy_command'),
            copies: $command,
            snippet: $command,
            hint: voightTrans('pages.mcp_access.clients.claude_code.oauth_hint'),
        );
    }

    private function codex(): McpClient
    {
        $command = $this->codexCommand();

        return new McpClient(
            key: 'codex',
            name: 'Codex CLI',
            description: voightTrans('pages.mcp_access.clients.codex.description'),
            icon: Heroicon::OutlinedCommandLine,
            buttonLabel: voightTrans('pages.mcp_access.clients.copy_command'),
            copies: $command,
            snippet: $command,
            hint: voightTrans('pages.mcp_access.clients.codex.oauth_hint'),
        );
    }
}
