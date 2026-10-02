# Environment Health

**Date:** 2026-10-02
**Status:** implemented
**Related:** `12-dependency-graph.md` (first producer of sync warnings)

Show on the project page when an environment's data cannot be trusted: a sync
or scan failed, a lockfile was skipped or only partly understood, or nothing was
ever synced.

---

## Problem

Problems are recorded but never shown, or not recorded at all.

1. **Failed syncs are invisible.** `ProcessLockFilesJob` stores
   `DependencySync.error_message`, but no Filament page displays it.
2. **Failed scans have no reason.** `RunOsvScanJob::recordFailedRun()` creates a
   `Failed` `AuditRun` without the error; the reason only reaches the log.
3. **Nightly sweep failures are not recorded per environment.**
   `RunNightlyOsvScanJob` throws and retries; when it finally fails, no
   environment shows that its nightly scan did not happen.
4. **Unsupported input passes silently.** `pnpm-lock.yaml` is an allowed upload
   name but has no parser, so the `match` in `parseLockFiles()` returns `[]`: a
   pnpm project syncs "successfully" with zero packages and zero findings. npm
   lockfile v1 and yarn berry do the same, and spec 12 adds yarn workspaces.
   Each looks like a healthy, vulnerability-free environment.

---

## Approach

**Derived, not stored.** Problems stay on the records where they happen
(`DependencySync`, `AuditRun`). A service derives the current issues from each
environment's latest records. An issue disappears as soon as the next sync or
scan succeeds; there is no notice table, no resolve or dismiss lifecycle, and
nothing to clean up.

Trade-off: no history of past issues and no "acknowledge". Those need a notices
table with a resolved state, which can be added later on top of the same
issue types.

---

## Data model

### `voight_dependency_syncs`: add `warnings`

| Field    | Type | Notes                                             |
|----------|------|---------------------------------------------------|
| warnings | json | nullable, list of `{code, context}`, see below    |

Cast to `array`. Shape:

```php
/** @var array<int, array{code: string, context: array<string, string>}>|null */
```

`code` is a `SyncWarning` value; `context` holds the values the translated
message needs (e.g. `file`). Messages are never stored, so they follow the
viewer's locale and can be reworded later.

### `voight_audit_runs`: add `error_message`

| Field         | Type   | Notes                                      |
|---------------|--------|--------------------------------------------|
| error_message | string | nullable, truncated to 500 chars like syncs |

### Migrations

- `add_warnings_to_voight_dependency_syncs_table.php.stub`
- `add_error_message_to_voight_audit_runs_table.php.stub`

Register both in `FilamentVoightServiceProvider::getMigrations()` and mirror them
in `tests/database/migrations/`. Update the `DependencySync` and `AuditRun`
PHPDoc properties and factories.

### `SyncWarning` enum (new)

`src/Enums/SyncWarning.php`, string-backed, `HasLabel`; the label takes the
warning's `context` (`voightTrans('enums.sync_warning.<value>', $context)`).

| Case                         | Context | Raised when                                                       |
|------------------------------|---------|-------------------------------------------------------------------|
| `UnsupportedLockfile`        | `file`  | an uploaded lockfile has no parser (e.g. `pnpm-lock.yaml`)        |
| `NpmLockfileV1Unsupported`   | `file`  | `package-lock.json` without a `packages` key                      |
| `YarnBerryUnsupported`       | `file`  | `yarn.lock` with `__metadata`                                     |
| `YarnWorkspacesUnsupported`  | `file`  | the `package.json` next to a `yarn.lock` has a `workspaces` key   |
| `LockfileMissingOnDisk`      | `file`  | a path in `lockfile_paths` cannot be read (today only logged)     |

New cases are added here as new checks appear.

---

## Producing warnings

### Sync

`ProcessLockFilesJob::parseLockFiles()` collects warnings and `handle()` stores
them on the sync in the same `update()` that sets `Completed`:

- **Unsupported format inside a parser:** parsers throw
  `Statikbe\FilamentVoight\Parsers\UnsupportedLockfileException` (next to the
  parsers; the package has no `Exceptions` directory), which
  carries a `SyncWarning`. The job catches it, adds the warning with the file
  name, logs it at warning level (as the existing log lines do) and skips the
  file. Used for npm v1 and yarn berry. A parser never returns `[]` to mean
  "unsupported" any more.
- **Detected by the job:** `UnsupportedLockfile` (the `default` arm of the
  `match`, for lockfile names — manifests such as `package.json` and
  `composer.json` are companions, never warnings), `LockfileMissingOnDisk`
  (the existing `! $content` branch), and `YarnWorkspacesUnsupported` (the job
  already reads the companion `package.json`).
- Warnings never fail the sync: the rest of the environment's data is still
  useful.
- A failed sync keeps using `status` + `error_message`; its warnings are not
  stored.

### Scan

- `RunOsvScanJob::recordFailedRun()` stores `mb_substr($e->getMessage(), 0, 500)`
  in `error_message`. The "no completed sync" early `fail()` does not create an
  audit run; that case is covered by `NeverSynced` below.
- `RunNightlyOsvScanJob` gets a `failed(Throwable $e)` method: once its retries
  are exhausted, it creates one `Failed` `AuditRun` (trigger `Nightly`, with
  `error_message`) for every environment in the sweep. Intermediate attempts do
  not, so a sweep that succeeds on retry leaves no failed runs behind.

---

## Deriving issues

### `EnvironmentIssueType` enum (new)

`src/Enums/EnvironmentIssueType.php`, with `HasLabel`, `HasColor`, `HasIcon`
like `AuditRunStatus`:

| Case          | Color     | Source                                                          |
|---------------|-----------|-----------------------------------------------------------------|
| `SyncFailed`  | `danger`  | latest finished sync is `Failed`; message: its `error_message`  |
| `ScanFailed`  | `danger`  | latest finished audit run is `Failed`; message: its `error_message` |
| `SyncWarning` | `warning` | one per warning on the latest finished sync, if it `Completed`  |
| `NeverSynced` | `info`    | the environment has no finished sync at all                     |

"Finished" means `Completed` or `Failed`: a sync or scan that is still running
neither raises nor clears an issue.

Order (and "worst"): `danger` before `warning` before `info`, then by type as
listed.

### `EnvironmentIssue` value object

`src/Support/EnvironmentIssue.php`, next to `ScanResponse`:

```php
public function __construct(
    public EnvironmentIssueType $type,
    public string $message,       // translated
    public ?Carbon $occurredAt,   // finished/completed time of the source record
) {}
```

### `EnvironmentHealthService`

`Statikbe\FilamentVoight\Services\EnvironmentHealthService`:

```php
/** @return Collection<int, EnvironmentIssue> worst first */
public function issuesFor(Environment $environment): Collection;
```

It reads two new `Environment` relations, so a project's environments can be
eager loaded in two queries instead of N:

- `latestFinishedDependencySync()` — `HasOne` via `ofMany`, status in
  `Completed` / `Failed`.
- `latestFinishedAuditRun()` — same, on `AuditRun`.

`NeverSynced` is simply "no latest finished sync". An environment whose only
syncs failed shows `SyncFailed`, which already says more.

---

## Filament

### Project view page

At the top of `ProjectInfoListSchema`, above the existing grid: one Filament
`Callout` per environment that has issues, hidden when there are none.

- Heading: the environment name.
- Status: the worst issue's color (`danger()` / `warning()` / `info()`), icon
  from its `EnvironmentIssueType`.
- Description: the issue messages. More than one message is rendered through a
  Blade view (a short list), not HTML built in PHP.
- The environments are loaded once with both latest relations eager loaded.

### `EnvironmentsRelationManager`

A `health` `IconColumn` after `name`: the worst issue's icon and color, a
check icon in `success` when there are none, and all messages as the tooltip.
The table query eager loads both latest relations.

All strings go through `voightTrans()`: issue type labels, `SyncWarning`
labels, the column label, and the "no issues" tooltip.

---

## Testing

- **Migrations:** test migrations mirror the new stubs.
- **`ProcessLockFilesJob`:** each `SyncWarning` case is stored with its `file`
  context and the sync still completes; a `package.json` or `composer.json`
  never produces `UnsupportedLockfile`; a failed sync stores no warnings.
- **Parsers:** npm v1 and yarn berry throw `UnsupportedLockfileException` with
  the right case.
- **`RunOsvScanJob`:** a failed scan stores `error_message`.
- **`RunNightlyOsvScanJob`:** `failed()` creates one failed run per swept
  environment; a sweep that succeeds on retry creates none.
- **`EnvironmentHealthService`:** each issue type; a newer successful sync or
  scan clears the matching issue; a running sync or scan neither raises nor
  clears one; ordering worst first; no N+1 when loading a project's
  environments (query count).
- **Filament:** callouts render per affected environment with the right status
  and are absent for a healthy project; the health column shows icon and
  tooltip.

---

## Out of scope

- Storing issue history, acknowledging or dismissing issues.
- Alerting on issues (spec 06/10 channels). Possible later, reusing
  `EnvironmentHealthService`.
- An issue indicator on the projects list or dashboard widgets.
- Staleness ("no sync for N days") — a natural next `EnvironmentIssueType`,
  needing a configurable threshold.
- A pnpm parser.
