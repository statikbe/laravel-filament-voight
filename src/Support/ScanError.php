<?php

namespace Statikbe\FilamentVoight\Support;

final class ScanError
{
    public function __construct(
        public string $code,
        public string $message,
        public bool $retryable,
    ) {}

    /**
     * @param  array<string, mixed>  $error
     */
    public static function fromArray(array $error): self
    {
        return new self(
            code: (string) ($error['code'] ?? 'unknown'),
            message: (string) ($error['message'] ?? 'Scanner reported a failure without details'),
            retryable: (bool) ($error['retryable'] ?? true),
        );
    }
}
