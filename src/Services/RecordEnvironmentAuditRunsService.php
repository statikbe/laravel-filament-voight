<?php

namespace Statikbe\FilamentVoight\Services;

use DateTime;
use Statikbe\FilamentVoight\Enums\AuditRunStatus;
use Statikbe\FilamentVoight\Enums\AuditRunTrigger;
use Statikbe\FilamentVoight\Enums\Severity;
use Statikbe\FilamentVoight\Enums\VulnerabilitySource;
use Statikbe\FilamentVoight\Facades\FilamentVoight;
use Statikbe\FilamentVoight\Models\AuditFinding;
use Statikbe\FilamentVoight\Models\AuditRun;
use Statikbe\FilamentVoight\Models\Environment;
use Statikbe\FilamentVoight\Models\Package;
use Statikbe\FilamentVoight\Models\Vulnerability;
use Statikbe\FilamentVoight\Models\VulnerablePackageRange;

class RecordEnvironmentAuditRunsService
{
    /**
     * Upsert each vulnerability once, globally. The score prefers the numeric
     * CVSS from the finding's max_severity and falls back to the OSV
     * database_specific severity label.
     *
     * @param  array<string, array<string, mixed>>  $rawVulnerabilities  keyed by vulnerability id
     * @param  array<string, string|null>  $maxSeverityById  vulnerability id => numeric CVSS string
     * @return array<string, Vulnerability>
     */
    public function upsertVulnerabilities(array $rawVulnerabilities, array $maxSeverityById = []): array
    {
        $models = [];

        foreach ($rawVulnerabilities as $id => $record) {
            $id = (string) $id;
            $models[$id] = $this->upsertVulnerability($record, $maxSeverityById[$id] ?? null);
        }

        return $models;
    }

    /**
     * Create one AuditRun for the environment and an AuditFinding for every one
     * of its packages that appears in the findings map.
     *
     * @param  array<string, array<int, array{vulnerability_id: string, max_severity: string|null}>>  $findingsMap  "type|name|version" => findings
     * @param  array<string, Vulnerability>  $vulnerabilityModels  vulnerability id => model
     * @param  array<string, array<string, mixed>>  $rawVulnerabilities  vulnerability id => raw OSV record
     */
    public function record(
        Environment $environment,
        array $findingsMap,
        array $vulnerabilityModels,
        array $rawVulnerabilities,
        AuditRunTrigger $trigger,
    ): AuditRun {
        /** @var class-string<AuditRun> $auditRunModel */
        $auditRunModel = FilamentVoight::config()->getAuditRunModel();

        $auditRun = $auditRunModel::create([
            'environment_id' => $environment->id,
            'status' => AuditRunStatus::Running,
            'trigger' => $trigger,
            'started_at' => now(),
        ]);

        foreach ($environment->environmentPackages()->with('package')->get() as $environmentPackage) {
            $package = $environmentPackage->package;

            if (! $package) {
                continue;
            }

            $key = $package->type->value . '|' . $package->name . '|' . $environmentPackage->version;

            foreach ($findingsMap[$key] ?? [] as $finding) {
                $vulnerability = $vulnerabilityModels[$finding['vulnerability_id']] ?? null;

                if (! $vulnerability) {
                    continue;
                }

                $affected = $this->affectedFor($rawVulnerabilities[$vulnerability->source_id] ?? [], $package->name);
                $fixedVersion = $this->extractFixedVersion($affected);

                $this->upsertVulnerablePackageRange($vulnerability, $package, $affected, $fixedVersion);
                $this->createAuditFinding($auditRun, $package, $vulnerability, $environmentPackage->version, $fixedVersion);
            }
        }

        $auditRun->update(['status' => AuditRunStatus::Completed, 'completed_at' => now()]);
        $environment->update(['scanned_at' => now()]);

        return $auditRun;
    }

    /**
     * @param  array<string, mixed>  $record
     */
    private function upsertVulnerability(array $record, ?string $maxSeverity): Vulnerability
    {
        $sourceId = (string) ($record['id'] ?? '');

        /** @var class-string<Vulnerability> $model */
        $model = FilamentVoight::config()->getVulnerabilityModel();

        return $model::updateOrCreate(
            ['source' => VulnerabilitySource::Osv, 'source_id' => $sourceId],
            [
                'aliases' => $record['aliases'] ?? [],
                'summary' => $record['summary'] ?? $sourceId,
                'details' => $record['details'] ?? null,
                'vulnerability_score' => $this->resolveScore($record, $maxSeverity),
                'published_at' => isset($record['published']) ? new DateTime((string) $record['published']) : null,
                'modified_at' => isset($record['modified']) ? new DateTime((string) $record['modified']) : null,
            ],
        );
    }

    /**
     * @param  array<string, mixed>  $record
     */
    private function resolveScore(array $record, ?string $maxSeverity): float
    {
        if ($maxSeverity !== null && (float) $maxSeverity > 0.0) {
            return (float) $maxSeverity;
        }

        $severityString = $record['database_specific']['severity'] ?? null;

        if ($severityString) {
            return Severity::fromString((string) $severityString)->toRepresentativeScore();
        }

        return 0.0;
    }

    private function createAuditFinding(
        AuditRun $auditRun,
        Package $package,
        Vulnerability $vulnerability,
        string $installedVersion,
        ?string $fixedVersion,
    ): void {
        /** @var class-string<AuditFinding> $model */
        $model = FilamentVoight::config()->getAuditFindingModel();

        $model::firstOrCreate(
            [
                'audit_run_id' => $auditRun->id,
                'package_id' => $package->id,
                'vulnerability_id' => $vulnerability->id,
            ],
            [
                'installed_version' => $installedVersion,
                'fixed_version' => $fixedVersion,
            ],
        );
    }

    /**
     * @param  array<int, array<string, mixed>>  $affected  affected[] entries for one package
     */
    private function upsertVulnerablePackageRange(
        Vulnerability $vulnerability,
        Package $package,
        array $affected,
        ?string $fixedVersion,
    ): void {
        if ($affected === []) {
            return;
        }

        /** @var class-string<VulnerablePackageRange> $model */
        $model = FilamentVoight::config()->getVulnerablePackageRangeModel();

        $ranges = [];

        foreach ($affected as $entry) {
            foreach ($entry['ranges'] ?? [] as $range) {
                $ranges[] = $range;
            }
        }

        $model::firstOrCreate(
            ['vulnerability_id' => $vulnerability->id, 'package_id' => $package->id],
            [
                'affected_range' => $this->buildAffectedRange($ranges),
                'fixed_version' => $fixedVersion,
            ],
        );
    }

    /**
     * Filter a raw OSV record's affected[] down to entries for one package.
     *
     * @param  array<string, mixed>  $rawVulnerability
     * @return array<int, array<string, mixed>>
     */
    private function affectedFor(array $rawVulnerability, string $packageName): array
    {
        return array_values(array_filter(
            $rawVulnerability['affected'] ?? [],
            fn ($entry): bool => is_array($entry) && ($entry['package']['name'] ?? '') === $packageName,
        ));
    }

    /**
     * @param  array<int, array<string, mixed>>  $affected  affected[] entries for one package
     */
    private function extractFixedVersion(array $affected): ?string
    {
        foreach ($affected as $entry) {
            foreach ($entry['ranges'] ?? [] as $range) {
                foreach ($range['events'] ?? [] as $event) {
                    if (isset($event['fixed'])) {
                        return (string) $event['fixed'];
                    }
                }
            }
        }

        return null;
    }

    /**
     * @param  array<int, mixed>  $ranges
     */
    private function buildAffectedRange(array $ranges): string
    {
        $parts = [];

        foreach ($ranges as $range) {
            $introduced = null;
            $fixed = null;

            foreach ($range['events'] ?? [] as $event) {
                if (isset($event['introduced'])) {
                    $introduced = $event['introduced'];
                }
                if (isset($event['fixed'])) {
                    $fixed = $event['fixed'];
                }
            }

            if ($introduced !== null && $fixed !== null) {
                $parts[] = ">={$introduced} <{$fixed}";
            } elseif ($introduced !== null) {
                $parts[] = ">={$introduced}";
            }
        }

        return implode(', ', $parts) ?: '*';
    }
}
