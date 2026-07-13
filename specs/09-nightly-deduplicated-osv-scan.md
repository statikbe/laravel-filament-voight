# Nightly deduplicated OSV scan — design

**Date:** 2026-07-09
**Status:** approved design, not yet implemented
**Counterpart spec:** `voight-osv-scanner-lambda/docs/specs/packages-endpoint.md`
(authoritative for the Lambda API contract)

## Problem

A nightly scan already exists. `FilamentVoightServiceProvider.php:81-83` registers
`$schedule->command('voight:run-osv-scan')->daily()`, which selects **every**
`Environment` and dispatches one `RunOsvScanJob` each
(`RunOsvScanCommand.php:58`). Each job uploads that environment's lockfiles to the
Lambda's `/locks` endpoint (`RunOsvScanJob::callOsvScanner()`).

That re-scans the same `package@version` once per environment that contains it.

## Evidence

Measured across 34 real Statik client projects (mean 791 packages each). The
distinct package set grows sublinearly with project count, fitting Heaps' law at
`distinct = 9.39 x scanned^0.689` (R² = 0.995). Excluding composer dev
dependencies barely moves the exponent (β = 0.687 vs 0.690), so this is not an
artifact of shared dev tooling.

| Projects | Package-scans today | Distinct | Redundancy | Work eliminated |
|---------:|--------------------:|---------:|-----------:|----------------:|
| 3        | 2,360               | 1,993    | 1.18x      | 15.6%           |
| **14 (today)**   | 11,067      | 5,745    | **1.93x**  | **48%**         |
| 34 (measured)| 26,878          | 10,062   | 2.67x      | 62.6%           |
| **75 (planned)** | 59,290      | 18,263   | **3.25x**  | **69%**         |

The model predicts 1.18x at N=3; the three actually-synced production lockfiles
(`PUBMUS`, `INTVOI`, `OKRADM`) measure **exactly 1.18x**. That agreement is what
justifies extrapolating to 75.

Secondary wins, independent of the redundancy factor:

- Nightly upload volume at 75 projects: **~60.8 MB → ~0.72 MB**.
- Lambda invocations: **75 → ~4**. `osv-scanner` process spawns: **~150 → ~4**.

Redundancy is nearly absent at small N, which is why a three-project sample was
misleading. It only emerges with scale.

### Why version drift matters twice

Deduping on exact `(ecosystem, name, version)` eliminates 15.6% of work at N=3;
deduping on `(ecosystem, name)` alone would eliminate 34.8%. The gap is version
drift — 24% of package names already appear at more than one version across just
three projects, because projects were deployed at different times.

The same drift inflates the scanner's response: one unfixed advisory matches
every drifted version. `postcss` appears at 29 distinct versions all matching
`GHSA-qx2v-qp2m-jg93`; `laravel/framework` at 20 versions matching
`GHSA-5vg9-5847-vvmq`. Measured decomposition of the 3.94x response duplication:
**3.59x from version drift**, only **1.10x from multi-package advisories**.

Drift cannot be removed, but its effect on the response is fully removed by
deduplicating vulnerabilities by id (see the Lambda spec §3.6).

## Approach

Dedupe changes only **how we ask the scanner**. Storage, findings, alerting and
widgets are untouched, because `AuditRun::latestIdsPerEnvironment()` is consumed
by `RecentAuditRunsWidget`, `ActiveFindingsWidget`, `MostVulnerableProjectsWidget`,
`AuditRunStatusWidget`, `ProjectResource` and `PackageResource`. Per-environment
`AuditRun` + `AuditFinding` rows must continue to exist, and the alerting spec
(`specs/06-alerting.md`) triggers on new `AuditFinding` creation.

```
voight:run-osv-scan --nightly
        │
        ├─ 1. environments where scan_nightly = true
        ├─ 2. SELECT DISTINCT (p.type, p.name, ep.version)        ← the dedupe
        │       FROM voight_environment_packages ep
        │       JOIN voight_packages p ON p.id = ep.package_id
        │      WHERE ep.environment_id IN (...)
        │
        ├─ 3. chunk (default 5,000) → POST /packages per chunk
        │
        ├─ 4. merge chunk responses:
        │       upsert Vulnerability + VulnerablePackageRange once, globally
        │       build map (type,name,version) → [vulnerability_id, max_severity]
        │
        └─ 5. per in-scope environment:
               AuditRun(running) → join its environment_packages against the map
               → AuditFinding rows → AuditRun(completed), environment.scanned_at
```

**No dependency hashing is needed.** An OSV finding is inherently keyed by
`(ecosystem, name, version)`, and `voight_environment_packages` already stores
exactly that per environment. Mapping deduplicated findings back to projects is a
plain database join.

### Rejected alternatives

- **Synthetic aggregate `composer.lock`** (dedupe with zero Lambda change). A
  composer lockfile cannot hold one package name at two versions, and 24% of our
  names need exactly that. Verified that a CycloneDX SBOM does handle it:
  `lodash@4.17.15` returns 6 vulnerabilities, `lodash@4.17.20` returns 5, as
  separate entries.
- **Lambda proxies OSV `querybatch` directly.** `querybatch` returns only
  vulnerability ids and `modified` timestamps, so the Lambda would have to hydrate
  each id via `/v1/vulns/{id}` and reimplement osv-scanner's severity grouping —
  logic `extractVulnerabilityScore()` already depends on.
- **Keep per-environment uploads, scan incrementally.** The point of a nightly run
  is catching newly published advisories against unchanged packages, so we would
  need OSV's modified-since feed to know what to recheck. More moving parts, and
  it never shrinks the payload.

## Components

### 1. Migration: `voight_environments.scan_nightly`

`Environment` is `(project_id, name)` with a free-text `name`, auto-created from
whatever string the client's `voight.sh` posts (`LockFileSyncService:53-63`).
Nothing marks an environment as production, and names will drift
(`production` / `prod` / `live`).

Add `boolean scan_nightly` to `create_voight_environments_table.php.stub`.

**Default `true`**, preserving today's behaviour (every environment scanned) for
existing installs; operators opt *out* per environment. A `false` default would
silently disable the nightly sweep on upgrade, which is the worse failure.

Per project convention, mirror the stub into
`tests/database/migrations/0001_01_01_000005_create_voight_environments_table.php`.

Expose it as a toggle on the Environment form. The label and helper text go
through translation helpers, per project convention — no bare strings.

### 2. `DeduplicatedPackageSetQuery`

Returns the distinct in-scope package set. Single responsibility, testable
without the network:

```php
/** @return Collection<int, array{type: PackageType, name: string, version: string}> */
public function forEnvironments(Collection $environments): Collection
```

Backed by a `DISTINCT` join, not by loading `EnvironmentPackage` models — at 75
projects this is ~59k rows collapsing to ~18k.

### 3. `OsvScannerClient`

Extracted from `RunOsvScanJob`, which currently owns HTTP, parsing and persistence
in one class. Two methods, one per Lambda endpoint:

- `scanPackages(array $packages, string $batchId): ScanResponse` → `POST /packages`
- `scanLockfiles(array $files, Project $p, Environment $e): ScanResponse` → `POST /locks`

Both return the same `ScanResponse` DTO (`findings[]`, `vulnerabilities{}`),
because the Lambda now returns one unified shape for both endpoints.

### 4. `RunNightlyOsvScanJob`

Orchestrates the sweep. Chunks the package set (`scanner.batch_size`, default
5,000), calls `scanPackages()` per chunk, and delegates persistence.

Chunk size is bounded by Lambda's **hard, non-raisable 6 MB synchronous response
limit**, not by scan time. At the measured density of 0.344 MB per 1,000
components, 5,000 components ≈ 1.72 MB. Scan time is not the constraint:
9,891 components scan in 15.9s, and scaling is sublinear.

### 5. `RecordEnvironmentAuditRunsService`

Given the merged `(type,name,version) → findings` map, for each in-scope
environment: create the `AuditRun` (with `trigger` set, see component 7), join
`environment_packages` against the map, create `AuditFinding` rows, mark complete,
set `scanned_at`.

`upsertVulnerability()`, `extractFixedVersion()`, `extractVulnerabilityScore()`,
`upsertVulnerablePackageRange()` and `buildAffectedRange()` move here from
`RunOsvScanJob` **unchanged**. They each consume one vulnerability record and
filter `affected[]` by package name themselves, which is exactly what the unified
response provides.

`buildGroupScoreMap()` is deleted. `groups[]` no longer exists in the response;
its output arrives directly as `findings[].max_severity`.

### 6. Commit-pinned dependency fallback

PURLs are version-based and cannot express branch/commit pins (`dev-main`,
`dev-master`). In the measured corpus, 171 of 10,062 distinct versions (1.7%) are
of this form.

**This path is exercised in production, not hypothetical.** Of the 35 commit-pinned
versions in the three synced production lockfiles, 33 come from `INTVOI` (a library
repository) but **2 come from `PUBMUS`, a deployed application**. Scaled to 75
projects, expect a handful of environments to need the fallback every night.

The Lambda returns these in `summary.skipped_packages`. Any environment owning a
skipped package is additionally scanned through the existing per-environment
`/locks` path in the same run, and its `AuditFinding` rows merge into the same
`AuditRun`. A project must never stop being scanned without a signal.

Note that no commit-pinned version appeared in more than one environment, which is
expected — a branch pin resolves to whatever commit was current at install time.
They are inherently un-dedupable, so routing them to `/locks` costs nothing.

### 7. `AuditRun.trigger` column + `AuditRunTrigger` enum

Three genuinely distinct paths now create `AuditRun` rows, and once they share a
table they cannot be told apart after the fact:

| Trigger | Source | Path |
|---|---|---|
| `PostSync` | `ProcessLockFilesJob:85` after a deploy sync | per-environment `/locks` |
| `Nightly` | `RunNightlyOsvScanJob` (the sweep) | deduplicated `/packages` |
| `Manual` | `voight:run-osv-scan --project=…` | per-environment `/locks` |

Add a nullable `string trigger` to `create_voight_audit_runs_table.php.stub` (and
mirror into `tests/database/migrations/…_create_voight_audit_runs_table.php`).
Nullable because pre-existing rows have no known trigger; a backfill would be
guesswork.

Add `AuditRunTrigger` following the existing enum convention
(`AuditRunStatus.php`): backed string enum, `HasOptions` trait, TitleCase cases
(`PostSync`, `Nightly`, `Manual`), `label()` via `voightTrans('enums.audit_run_trigger.…')`,
and a `color()`. Add the translation keys to the lang files — no bare strings.

**Record it, do not branch on it.** No code reads `trigger` to decide behaviour
(alerting frequency, retention, dedupe). It is observability metadata only —
surfaced in `RecentAuditRunsWidget` and answering "why did this environment get
scanned N times today". Behavioural use is deferred until a real requirement
appears (YAGNI).

## Data flow and consistency

Vulnerabilities and `VulnerablePackageRange` rows are global (unique by
`(source, source_id)` and `(vulnerability_id, package_id)`), so they are upserted
once from the merged chunk responses before any fan-out.

Fan-out then runs per environment. Each environment's `AuditRun` gets its own
`AuditFinding` rows, so `AuditRun::latestIdsPerEnvironment()` keeps working and
`ActiveFindingsWidget` keeps resolving current findings per environment.

`AuditFinding::firstOrCreate` is keyed on
`(audit_run_id, package_id, vulnerability_id)`. Because a fresh `AuditRun` is
created per environment per sweep, findings are re-created each night rather than
mutated — matching current behaviour, and preserving "new finding" alert
semantics.

## Error handling

- **A chunk fails.** Retry that chunk only (`tries = 3`, existing backoff
  `[30, 120, 300]`). Chunks are idempotent: the same package set yields the same
  findings. This is the main practical argument for chunking over streaming.
- **A chunk still fails after retries.** Abort the sweep before any fan-out. Do
  **not** create `AuditRun`s from a partial package map — a missing chunk would
  look like "vulnerabilities resolved" and could suppress alerts. Fail loudly.
- **`scanner.url` unset.** Existing behaviour: fail the command with a clear
  message (`RunOsvScanCommand::handle()`).
- **An environment has no completed `DependencySync`.** It contributes no packages
  and gets no `AuditRun`. Log it; do not fail the sweep.
- **Overlap.** The current schedule has no `withoutOverlapping()`, so a slow sweep
  can be re-entered. Add `->withoutOverlapping()`.

## Scheduling

`$schedule->command('voight:run-osv-scan --nightly')->daily()->withoutOverlapping()`

The existing `voight:run-osv-scan` (with `--project` / `--environment`) keeps its
current per-environment behaviour for manual and post-sync use. `ProcessLockFilesJob:85`
continues to dispatch `RunOsvScanJob` after each sync — a deploy should still be
scanned immediately, and one environment is not worth batching.

**The scheduler must actually be running** in the host app (`schedule:run` on cron,
or `schedule:work`). The package registering a schedule entry does not create cron.
Worth stating in the README, since the nightly scan may never have run.

## Testing

- `DeduplicatedPackageSetQuery` collapses the same `package@version` across
  environments, and keeps the same name at differing versions separate.
- Fan-out maps a finding on `(Packagist, laravel/framework, v10.9.0)` to exactly
  those environments carrying that version, and not to an environment on a
  different version of the same package.
- `max_severity` from `findings[]` reaches `Vulnerability.vulnerability_score`,
  and the `database_specific.severity` fallback still applies when it is null.
  (Regression guard: if `max_severity` were dropped, every finding would score
  `0.0` and no alert would ever fire.)
- A chunk failure after retries aborts before creating any `AuditRun`.
- An environment containing a `dev-main` package is routed to the `/locks` path.
- `scan_nightly = false` excludes an environment from the sweep but not from
  post-sync scanning.
- Fake the HTTP client with a recorded unified-shape response; no live Lambda.

## Sequencing

The Lambda's `/locks` response shape is a breaking change, so the two repositories
ship together:

1. Lambda: implement `/packages`, reshape `/locks`, align `osv-scanner` to 2.3.5,
   add the Function URL. (See counterpart spec.)
2. Laravel: this design, pointing `scanner.url` at the new deployment.

Deploy the Lambda to a new stack, then release the Laravel change pointing at it,
so rolling back one rolls back both.

## Open item

The Lambda's `template.yaml:16` pins `OsvScannerVersion: "1.9.2"` while
`Dockerfile:18` defaults to `2.3.5`, so the deployed binary is v1.9.2. This is
tracked in the counterpart spec §2 and must be resolved there before this design
is implemented. Verified locally against 2.3.5: SBOM input works via
`scan source --format json -L <file>.cdx.json` (the flag is `-L`, not `-S`),
`groups[].max_severity` is preserved, ecosystems return as `Packagist` / `npm`,
scoped npm PURLs require `%40`, and the legacy `--lockfile` invocation the handler
uses today still works — so upgrading does not break `/locks`.
