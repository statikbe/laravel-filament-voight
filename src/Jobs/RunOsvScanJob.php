<?php

namespace Statikbe\FilamentVoight\Jobs;

use DateTime;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use RuntimeException;
use Statikbe\FilamentVoight\Enums\AuditRunStatus;
use Statikbe\FilamentVoight\Enums\DependencySyncStatus;
use Statikbe\FilamentVoight\Enums\PackageType;
use Statikbe\FilamentVoight\Enums\Severity;
use Statikbe\FilamentVoight\Enums\VulnerabilitySource;
use Statikbe\FilamentVoight\Facades\FilamentVoight;
use Statikbe\FilamentVoight\Models\AuditFinding;
use Statikbe\FilamentVoight\Models\AuditRun;
use Statikbe\FilamentVoight\Models\Environment;
use Statikbe\FilamentVoight\Models\Package;
use Statikbe\FilamentVoight\Models\Vulnerability;
use Statikbe\FilamentVoight\Models\VulnerablePackageRange;

class RunOsvScanJob implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    public function __construct(
        public Environment $environment,
    ) {}

    private ?string $scannerUrl = null;

    public function handle(): void
    {
        $this->scannerUrl = FilamentVoight::config()->getScannerUrl();

        if (! $this->scannerUrl) {
            return;
        }

        $auditRun = AuditRun::create([
            'environment_id' => $this->environment->id,
            'status' => AuditRunStatus::Running,
            'started_at' => now(),
        ]);

        try {
            $lockfilePaths = $this->resolveLockfilePaths();

            if (empty($lockfilePaths)) {
                $auditRun->update([
                    'status' => AuditRunStatus::Completed,
                    'completed_at' => now(),
                ]);

                return;
            }

            $scanResponse = $this->callOsvScanner($lockfilePaths);
            $this->processResults($auditRun, $scanResponse);

            $auditRun->update([
                'status' => AuditRunStatus::Completed,
                'completed_at' => now(),
            ]);
        } catch (\Throwable $e) {
            $auditRun->update([
                'status' => AuditRunStatus::Failed,
                'completed_at' => now(),
            ]);

            throw $e;
        }
    }

    /**
     * @return array<string>
     */
    private function resolveLockfilePaths(): array
    {
        $latestSync = $this->environment
            ->dependencySyncs()
            ->where('status', DependencySyncStatus::Completed)
            ->latest()
            ->first();

        if (! $latestSync) {
            return [];
        }

        return $latestSync->lockfile_paths ?? [];
    }

    /**
     * @param  array<string>  $lockfilePaths
     * @return array<string, mixed>
     */
    private function callOsvScanner(array $lockfilePaths): array
    {
        $disk = Storage::disk(FilamentVoight::config()->getLockfilesDisk());
        $project = $this->environment->project;

        $request = Http::withToken(FilamentVoight::config()->getScannerToken() ?? '');

        foreach ($lockfilePaths as $path) {
            $content = $disk->get($path);

            if (! $content) {
                continue;
            }

            $filename = basename($path);
            $request = $request->attach($filename, $content, $filename);
        }

        $response = $request->post($this->scannerUrl, [
            'project_code' => $project->project_code,
            'environment' => $this->environment->name,
        ]);

        if (! $response->successful()) {
            throw new RuntimeException("OSV scanner returned HTTP {$response->status()}: {$response->body()}");
        }

        return $response->json();
    }

    /**
     * @param  array<string, mixed>  $scanResponse
     */
    private function processResults(AuditRun $auditRun, array $scanResponse): void
    {
        foreach ($scanResponse['results'] ?? [] as $fileResult) {
            if (($fileResult['status'] ?? '') !== 'vulnerable') {
                continue;
            }

            foreach ($fileResult['scan']['results'] ?? [] as $result) {
                foreach ($result['packages'] ?? [] as $pkgData) {
                    $this->processPackageVulnerabilities($auditRun, $pkgData);
                }
            }
        }
    }

    /**
     * @param  array<string, mixed>  $pkgData
     */
    private function processPackageVulnerabilities(AuditRun $auditRun, array $pkgData): void
    {
        $pkgInfo = $pkgData['package'] ?? [];
        $packageName = $pkgInfo['name'] ?? null;
        $installedVersion = $pkgInfo['version'] ?? null;
        $ecosystem = $pkgInfo['ecosystem'] ?? '';

        if (! $packageName) {
            return;
        }

        $packageType = $this->resolvePackageType($ecosystem);
        $package = Package::where('name', $packageName)->where('type', $packageType)->first();

        $groupScores = $this->buildGroupScoreMap($pkgData['groups'] ?? []);

        foreach ($pkgData['vulnerabilities'] ?? [] as $vulnData) {
            $vulnId = $vulnData['id'] ?? '';
            $vulnerability = $this->upsertVulnerability($vulnData, $groupScores[$vulnId] ?? null);
            $fixedVersion = $this->extractFixedVersion($vulnData, $packageName);

            if ($package) {
                $this->upsertVulnerablePackageRange($vulnerability, $package, $vulnData, $fixedVersion);

                AuditFinding::firstOrCreate(
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
        }
    }

    /**
     * @param  array<string, mixed>  $vulnData
     */
    private function upsertVulnerability(array $vulnData, ?float $groupScore = null): Vulnerability
    {
        $sourceId = $vulnData['id'] ?? '';
        $publishedAt = isset($vulnData['published']) ? new DateTime($vulnData['published']) : null;
        $modifiedAt = isset($vulnData['modified']) ? new DateTime($vulnData['modified']) : null;

        return Vulnerability::updateOrCreate(
            [
                'source' => VulnerabilitySource::Osv,
                'source_id' => $sourceId,
            ],
            [
                'aliases' => $vulnData['aliases'] ?? [],
                'summary' => $vulnData['summary'] ?? $sourceId,
                'details' => $vulnData['details'] ?? null,
                'vulnerability_score' => $this->extractVulnerabilityScore($vulnData, $groupScore),
                'published_at' => $publishedAt,
                'modified_at' => $modifiedAt,
            ],
        );
    }

    /**
     * @param  array<string, mixed>  $vulnData
     */
    private function extractVulnerabilityScore(array $vulnData, ?float $groupScore = null): float
    {
        // Prefer the numeric CVSS score from groups.max_severity
        if ($groupScore !== null && $groupScore > 0.0) {
            return $groupScore;
        }

        // Fall back to the string severity label
        $severityString = $vulnData['database_specific']['severity'] ?? null;

        if ($severityString) {
            return Severity::fromString((string) $severityString)->toRepresentativeScore();
        }

        return 0.0;
    }

    /**
     * @param  array<int, mixed>  $groups
     * @return array<string, float>
     */
    private function buildGroupScoreMap(array $groups): array
    {
        $map = [];

        foreach ($groups as $group) {
            $score = isset($group['max_severity']) ? (float) $group['max_severity'] : null;

            if ($score === null) {
                continue;
            }

            foreach ($group['ids'] ?? [] as $id) {
                $map[(string) $id] = $score;
            }
        }

        return $map;
    }

    /**
     * @param  array<string, mixed>  $vulnData
     */
    private function extractFixedVersion(array $vulnData, string $packageName): ?string
    {
        foreach ($vulnData['affected'] ?? [] as $affected) {
            if (($affected['package']['name'] ?? '') !== $packageName) {
                continue;
            }

            foreach ($affected['ranges'] ?? [] as $range) {
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
     * @param  array<string, mixed>  $vulnData
     */
    private function upsertVulnerablePackageRange(
        Vulnerability $vulnerability,
        Package $package,
        array $vulnData,
        ?string $fixedVersion,
    ): void {
        foreach ($vulnData['affected'] ?? [] as $affected) {
            if (($affected['package']['name'] ?? '') !== $package->name) {
                continue;
            }

            VulnerablePackageRange::firstOrCreate(
                [
                    'vulnerability_id' => $vulnerability->id,
                    'package_id' => $package->id,
                ],
                [
                    'affected_range' => $this->buildAffectedRange($affected['ranges'] ?? []),
                    'fixed_version' => $fixedVersion,
                ],
            );
        }
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

    private function resolvePackageType(string $ecosystem): PackageType
    {
        return match (strtolower($ecosystem)) {
            'npm' => PackageType::Npm,
            default => PackageType::Composer,
        };
    }
}
