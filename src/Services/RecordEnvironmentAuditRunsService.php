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
use Statikbe\FilamentVoight\Models\EnvironmentPackage;
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

        $installedVersions = $environment->environmentPackages()
            ->with('package')
            ->get()
            ->unique(fn (EnvironmentPackage $environmentPackage): string => $environmentPackage->package_id . '|' . $environmentPackage->version);

        foreach ($installedVersions as $environmentPackage) {
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
                $fixedVersion = $this->extractFixedVersion($affected, $environmentPackage->version);

                $this->upsertVulnerablePackageRange($vulnerability, $package, $affected);
                $this->createAuditFinding($auditRun, $package, $vulnerability, $environmentPackage->version, $fixedVersion);
            }
        }

        $auditRun->update(['status' => AuditRunStatus::Completed, 'completed_at' => now()]);
        $environment->update(['scanned_at' => now()]);

        return $auditRun;
    }

    public function recordFailed(string $environmentId, AuditRunTrigger $trigger, string $message): AuditRun
    {
        /** @var class-string<AuditRun> $auditRunModel */
        $auditRunModel = FilamentVoight::config()->getAuditRunModel();

        return $auditRunModel::create([
            'environment_id' => $environmentId,
            'status' => AuditRunStatus::Failed,
            'trigger' => $trigger,
            'started_at' => now(),
            'completed_at' => now(),
            'error_message' => mb_substr($message, 0, 500),
        ]);
    }

    /**
     * @param  array<int, string>  $environmentIds
     */
    public function recordFailedForEnvironments(array $environmentIds, AuditRunTrigger $trigger, string $message): void
    {
        /** @var class-string<Environment> $environmentModel */
        $environmentModel = FilamentVoight::config()->getEnvironmentModel();

        // Environments deleted since the scan started have nothing to record against.
        foreach ($environmentModel::query()->whereKey($environmentIds)->pluck('id') as $environmentId) {
            $this->recordFailed((string) $environmentId, $trigger, $message);
        }
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
                'installed_version' => $installedVersion,
            ],
            [
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

        // Shared by every environment, so it cannot depend on one installed version.
        // updateOrCreate so a corrected advisory heals rows written by the old logic.
        $model::updateOrCreate(
            ['vulnerability_id' => $vulnerability->id, 'package_id' => $package->id],
            [
                'affected_range' => $this->buildAffectedRange($ranges),
                'fixed_version' => $this->highestFixedVersion($affected),
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
     * Fixed version for the range line containing the installed version, so a
     * package with one range per major line yields the fix for its own line.
     * Never returns a version at or below the installed one.
     *
     * @param  array<int, array<string, mixed>>  $affected  affected[] entries for one package
     */
    private function extractFixedVersion(array $affected, string $installedVersion): ?string
    {
        $intervals = $this->versionIntervals($affected);

        foreach ($intervals as $interval) {
            if ($this->versionCompare($installedVersion, $interval['introduced']) < 0) {
                continue;
            }

            if ($interval['fixed'] !== null && $this->versionCompare($installedVersion, $interval['fixed']) >= 0) {
                continue;
            }

            if ($interval['last_affected'] !== null && $this->versionCompare($installedVersion, $interval['last_affected']) > 0) {
                continue;
            }

            // The matching line has no fix when 'fixed' is null.
            return $interval['fixed'];
        }

        // Installed version matches no range (e.g. only listed in affected[].versions):
        // offer the nearest fix above it.
        $candidates = array_filter(
            array_column($intervals, 'fixed'),
            fn (?string $fixed): bool => $fixed !== null && $this->versionCompare($fixed, $installedVersion) > 0,
        );

        usort($candidates, fn (string $a, string $b): int => $this->versionCompare($a, $b));

        return $candidates[0] ?? null;
    }

    /**
     * Highest fixed version across all ranges: a stable, environment-independent value.
     *
     * @param  array<int, array<string, mixed>>  $affected
     */
    private function highestFixedVersion(array $affected): ?string
    {
        $fixed = array_filter(array_column($this->versionIntervals($affected), 'fixed'));

        usort($fixed, fn (string $a, string $b): int => $this->versionCompare($b, $a));

        return $fixed[0] ?? null;
    }

    /**
     * Flatten OSV SEMVER/ECOSYSTEM ranges into introduced/fixed intervals. GIT ranges
     * hold commit hashes, which cannot be compared with package versions.
     *
     * @param  array<int, array<string, mixed>>  $affected
     * @return array<int, array{introduced: string, fixed: string|null, last_affected: string|null}>
     */
    private function versionIntervals(array $affected): array
    {
        $intervals = [];

        foreach ($affected as $entry) {
            foreach ($entry['ranges'] ?? [] as $range) {
                if (! in_array($range['type'] ?? null, ['SEMVER', 'ECOSYSTEM'], true)) {
                    continue;
                }

                $open = null;

                foreach ($range['events'] ?? [] as $event) {
                    if (isset($event['introduced'])) {
                        if ($open !== null) {
                            $intervals[] = $open;
                        }

                        $open = ['introduced' => (string) $event['introduced'], 'fixed' => null, 'last_affected' => null];
                    } elseif ($open !== null && (isset($event['fixed']) || isset($event['last_affected']))) {
                        $open['fixed'] = isset($event['fixed']) ? (string) $event['fixed'] : null;
                        $open['last_affected'] = isset($event['last_affected']) ? (string) $event['last_affected'] : null;
                        $intervals[] = $open;
                        $open = null;
                    }
                }

                if ($open !== null) {
                    $intervals[] = $open;
                }
            }
        }

        return $intervals;
    }

    /**
     * Composer "v" prefixes are stripped; PHP's version_compare orders -rc/-beta tags before the release.
     * ponytail: not full semver precedence (e.g. numeric vs alphanumeric prerelease identifiers); swap in composer/semver if that matters.
     */
    private function versionCompare(string $a, string $b): int
    {
        return version_compare(ltrim($a, 'vV'), ltrim($b, 'vV'));
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
            $lastAffected = null;

            foreach ($range['events'] ?? [] as $event) {
                if (isset($event['introduced'])) {
                    $introduced = $event['introduced'];
                }
                if (isset($event['fixed'])) {
                    $fixed = $event['fixed'];
                }
                if (isset($event['last_affected'])) {
                    $lastAffected = $event['last_affected'];
                }
            }

            if ($introduced !== null && $fixed !== null) {
                $parts[] = ">={$introduced} <{$fixed}";
            } elseif ($introduced !== null && $lastAffected !== null) {
                $parts[] = ">={$introduced} <={$lastAffected}";
            } elseif ($introduced !== null) {
                $parts[] = ">={$introduced}";
            }
        }

        // Separate OSV ranges are alternatives, so join them with Composer's OR operator.
        return implode(' || ', $parts) ?: '*';
    }
}
