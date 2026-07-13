<?php

namespace Statikbe\FilamentVoight\Support;

use Statikbe\FilamentVoight\Enums\PackageType;

final class ScanResponse
{
    /**
     * @param  array<int, array{ecosystem: string, name: string, version: string, vulnerability_id: string, max_severity: string|null}>  $findings
     * @param  array<string, array<string, mixed>>  $vulnerabilities
     * @param  array<int, array{ecosystem: string, name: string, version: string, reason: string}>  $skippedPackages
     */
    public function __construct(
        public array $findings,
        public array $vulnerabilities,
        public array $skippedPackages = [],
    ) {}

    /**
     * @param  array<string, mixed>  $json
     */
    public static function fromArray(array $json): self
    {
        return new self(
            findings: array_values($json['findings'] ?? []),
            vulnerabilities: $json['vulnerabilities'] ?? [],
            skippedPackages: $json['summary']['skipped_packages'] ?? [],
        );
    }

    /**
     * @return array<string, array<int, array{vulnerability_id: string, max_severity: string|null}>>
     */
    public function findingsByPackageKey(): array
    {
        $map = [];

        foreach ($this->findings as $finding) {
            $type = self::ecosystemToType($finding['ecosystem'] ?? '');
            $key = $type->value . '|' . ($finding['name'] ?? '') . '|' . ($finding['version'] ?? '');
            $map[$key][] = [
                'vulnerability_id' => $finding['vulnerability_id'] ?? '',
                'max_severity' => $finding['max_severity'] ?? null,
            ];
        }

        return $map;
    }

    public static function ecosystemToType(string $ecosystem): PackageType
    {
        return match (strtolower($ecosystem)) {
            'npm' => PackageType::Npm,
            default => PackageType::Composer,
        };
    }
}
