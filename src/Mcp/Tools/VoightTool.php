<?php

namespace Statikbe\FilamentVoight\Mcp\Tools;

use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\JsonSchema\Types\Type;
use Illuminate\Validation\Rule;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\ResponseFactory;
use Laravel\Mcp\Server\Tool;
use Statikbe\FilamentVoight\Enums\Severity;
use Statikbe\FilamentVoight\Mcp\McpAccess;
use Statikbe\FilamentVoight\Mcp\McpToolError;

/**
 * Tools stay thin: validate, authorize, call a service, shape the response.
 */
abstract class VoightTool extends Tool
{
    public const int DEFAULT_LIMIT = 25;

    public const int MAX_LIMIT = 100;

    public const array SEVERITIES = ['low', 'medium', 'high', 'critical'];

    public function handle(Request $request, McpAccess $access): Response | ResponseFactory
    {
        try {
            return $this->run($request, $access);
        } catch (McpToolError $error) {
            return Response::error($error->getMessage());
        }
    }

    /**
     * @throws McpToolError
     */
    abstract protected function run(Request $request, McpAccess $access): Response | ResponseFactory;

    protected function limitSchema(JsonSchema $schema): Type
    {
        return $schema->integer()->min(1)->max(self::MAX_LIMIT)->default(self::DEFAULT_LIMIT)
            ->description('Maximum number of results (default ' . self::DEFAULT_LIMIT . ', max ' . self::MAX_LIMIT . '). `truncated: true` means more exist.');
    }

    protected function minSeveritySchema(JsonSchema $schema): Type
    {
        return $schema->string()->enum(self::SEVERITIES)
            ->description('Only count findings at least this severe: low ≥ 0.1, medium ≥ 4.0, high ≥ 7.0, critical ≥ 9.0 (CVSS score).');
    }

    /**
     * @return array<string, array<int, mixed>>
     */
    protected function sharedRules(): array
    {
        return [
            'limit' => ['nullable', 'integer', 'min:1', 'max:' . self::MAX_LIMIT],
            'min_severity' => ['nullable', 'string', Rule::in(self::SEVERITIES)],
        ];
    }

    protected function limit(Request $request): int
    {
        return (int) ($request->get('limit') ?? self::DEFAULT_LIMIT);
    }

    protected function minSeverity(Request $request): ?Severity
    {
        $value = $request->get('min_severity');

        return is_string($value) ? Severity::from($value) : null;
    }
}
