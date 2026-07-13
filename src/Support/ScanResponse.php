<?php

namespace Statikbe\FilamentVoight\Support;

use Statikbe\FilamentVoight\Enums\PackageType;

final class ScanResponse
{
    /**
     * The findings/vulnerabilities/skipped arrays originate from the scanner's
     * JSON response, so their inner values are treated as untrusted (mixed).
     *
     * @param  array<int, array<string, mixed>>  $findings
     * @param  array<string, array<string, mixed>>  $vulnerabilities
     * @param  array<int, array<string, mixed>>  $skippedPackages
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
        $findings = is_array($json['findings'] ?? null) ? array_values($json['findings']) : [];
        $vulnerabilities = is_array($json['vulnerabilities'] ?? null) ? $json['vulnerabilities'] : [];
        $skipped = $json['summary']['skipped_packages'] ?? null;

        return new self(
            findings: $findings,
            vulnerabilities: $vulnerabilities,
            skippedPackages: is_array($skipped) ? array_values($skipped) : [],
        );
    }

    /**
     * @return array<string, array<int, array{vulnerability_id: string, max_severity: string|null}>>
     */
    public function findingsByPackageKey(): array
    {
        $map = [];

        foreach ($this->findings as $finding) {
            $type = self::ecosystemToType((string) ($finding['ecosystem'] ?? ''));
            $key = $type->value . '|' . (string) ($finding['name'] ?? '') . '|' . (string) ($finding['version'] ?? '');
            $maxSeverity = $finding['max_severity'] ?? null;
            $map[$key][] = [
                'vulnerability_id' => (string) ($finding['vulnerability_id'] ?? ''),
                'max_severity' => $maxSeverity === null ? null : (string) $maxSeverity,
            ];
        }

        return $map;
    }

    /**
     * Highest numeric severity seen per vulnerability id across all findings.
     * A null value means the vulnerability was reported without a group score.
     *
     * @return array<string, string|null>
     */
    public function maxSeverityById(): array
    {
        $map = [];

        foreach ($this->findings as $finding) {
            $id = (string) ($finding['vulnerability_id'] ?? '');

            if ($id === '') {
                continue;
            }

            $severity = $finding['max_severity'] ?? null;
            $severity = $severity === null ? null : (string) $severity;

            if (! array_key_exists($id, $map)) {
                $map[$id] = $severity;

                continue;
            }

            if ($severity !== null && (float) $severity > (float) ($map[$id] ?? 0)) {
                $map[$id] = $severity;
            }
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
