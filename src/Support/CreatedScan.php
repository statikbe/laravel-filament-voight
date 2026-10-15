<?php

namespace Statikbe\FilamentVoight\Support;

final class CreatedScan
{
    /**
     * @param  array<string, string>  $uploadHeaders  headers the presigned upload was signed with
     */
    public function __construct(
        public string $scanId,
        public string $uploadUrl,
        public array $uploadHeaders,
    ) {}
}
