# Audit Run Resource

A read-only Filament resource for the scan history: when each audit run happened,
what triggered it, whether it succeeded, and what it found. Audit runs are
machine-generated, so nothing in this feature creates or edits them — the resource
exists to read the audit trail and drill into a run's findings.

It also gives immediate alert emails somewhere specific to link to. Until now
`AuditSummary` pointed every message at the project, because no audit run detail
page existed.

---

## Scope

This covers the **run-centric** half of tracking findings: the runs themselves and
their findings. Tracking an individual vulnerability's lifecycle across runs
(first seen, still open, resolved) is deliberately **out of scope** — there is no
persisted notion of "resolved" today and deriving one across runs is its own
design problem. That becomes a follow-up spec.

---

## Data model

No migrations. Two relations are added to existing models.

`Project` needs its runs. Unlike `findings()`, this is a plain two-hop and needs
no `HasManyDeep`:

```php
public function auditRuns(): HasManyThrough
{
    return $this->hasManyThrough(AuditRun::class, Environment::class);
}
```

`AuditRun` needs its vulnerabilities, so the worst severity in a run can be
aggregated in the list query rather than per row:

```php
public function vulnerabilities(): HasManyThrough
{
    return $this->hasManyThrough(
        Vulnerability::class,
        AuditFinding::class,
        'audit_run_id',
        'id',
        'id',
        'vulnerability_id',
    );
}
```

---

## Max severity per run

The worst finding in a run is the most useful triage signal in the table, so it
must not cost a query per row. `PackageInfolistSchema` already establishes the
pattern for a single record — join to `voight_vulnerabilities` and bucket the max
`vulnerability_score` through `Severity::fromScore()`. For a list, the same result
comes from one aggregate on the relation above:

```php
->withMax('vulnerabilities as max_vulnerability_score', 'vulnerability_score')
```

The column exposes the state **as a `Severity` enum** rather than formatting it by
hand. `Severity` implements `HasLabel`, `HasColor` and `HasIcon`, so `->badge()`
resolves the label and colour on its own:

```php
TextColumn::make('max_severity')
    ->state(fn (AuditRun $record): ?Severity => $record->max_vulnerability_score === null
        ? null
        : Severity::fromScore((float) $record->max_vulnerability_score))
    ->badge()
    ->placeholder('—')
    ->sortable(['max_vulnerability_score'])
```

A run with no findings shows the placeholder, not a `None` badge — "we found
nothing" and "we found something harmless" are different facts.

---

## Shared duration formatting

`RecentAuditRunsWidget` already computes a run's duration with a private
`formatDuration()` helper. The new table wants the same column, so rather than
duplicating it the logic moves onto the model as a `formattedDuration()`
accessor, and the widget is updated to use it. This is the one piece of existing
code this feature touches beyond additions.

---

## `AuditRunResource`

Read-only, mirroring `VulnerabilityResource`: `canCreate()` returns `false`, there
is no edit page, and only `index` and `view` are registered. Schemas live in
dedicated classes per the project convention.

| Path | Role |
| --- | --- |
| `src/Resources/AuditRunResource.php` | Resource definition |
| `src/Resources/AuditRunResource/Schemas/AuditRunTableSchema.php` | Shared table |
| `src/Resources/AuditRunResource/Schemas/AuditRunInfolistSchema.php` | Run detail |
| `src/Resources/AuditRunResource/Pages/ListAuditRuns.php` | Index |
| `src/Resources/AuditRunResource/Pages/ViewAuditRun.php` | Detail |
| `src/Resources/AuditRunResource/RelationManagers/FindingsRelationManager.php` | That run's findings |

**Table columns:** project, environment, trigger, status, max severity, findings
count, started at, completed at, duration. `trigger` and `status` use a bare
`->badge()` — both enums implement the Filament contracts, so no
`formatStateUsing`/`color` closures are needed.

**Filters:** status, trigger, environment. **Default sort:** `started_at` desc.

**Query:** eager-loads `environment.project`, and uses
`withCount('auditFindings')` plus the `withMax` above so the list stays a single
query regardless of row count.

**Findings relation manager:** severity, CVSS, source ID, summary, package,
installed version, fixed version — the drill-down that makes the mail link land
somewhere worth reading.

Registered in `FilamentVoightPlugin::getResources()` with `navigationSort = 5`,
after `PackageResource`.

---

## `AuditRunsRelationManager` on projects

Added to `ProjectResource::getRelations()` as `'auditRun'`, alongside the existing
`environment`, `alert` and `vulnerability` managers.

It **reuses `AuditRunTableSchema`** so columns cannot drift between the global list
and the per-project list. The project column is redundant in this context and is
hidden here. Read-only, with a row action linking through to the full
`AuditRunResource` view page.

---

## Notification mail links

`AuditSummary` currently hardcodes `detailUrl` to the project view. It gains a
`detailLabel` alongside it, and both are set by whichever named constructor built
the summary:

| Constructor | Links to | Label |
| --- | --- | --- |
| `fromRunFindings()` | that run's `AuditRunResource` view page | "View audit run" |
| `fromProjectOutstanding()` | the project (unchanged) | "View project" |

A digest keeps pointing at the project on purpose: it summarises the latest run of
*every* environment, so there is no single run it could honestly link to.

Because the label travels with the URL, `resources/views/mail/audit-summary.blade.php`
and the Slack block builder in `AuditAlertNotification` render `detailLabel`
without branching on notification type. Both URLs are built with
`isAbsolute: true` and the alerts panel id, exactly as the project URL is today —
these are generated from a queue with no incoming request.

`specs/10-notifications.md` carries a note saying no AuditRun detail page exists;
it is corrected as part of this work.

---

## Translations

All user-facing strings go through `voightTrans()` from the start. New keys in
`resources/lang/en/filament-voight.php`:

- `models.audit_run.label` / `.plural`
- `models.audit_run.fields.*` for trigger, max severity, findings count, duration
  (status, started_at and completed_at already exist for the widgets)
- `notifications.shared.view_audit_run` / `.view_project` for the mail buttons

---

## Testing

Follow the existing conventions — resource page tests under
`tests/Feature/Resources/`, relation manager tests alongside them.

- **`ListAuditRunsPageTest`** — renders; the max severity column reflects the
  worst finding in a run; a run with no findings renders the placeholder.
- **`ViewAuditRunPageTest`** — renders for a real run; a nonexistent record is
  rejected (assert the request fails rather than a specific status, per
  `ViewPackagePageTest`).
- **`FindingsRelationManagerTest`** — lists only the findings belonging to that
  run, not the environment's other runs.
- **`AuditRunsRelationManagerTest`** — lists runs across all of a project's
  environments, and excludes another project's runs.
- **`AuditSummaryTest`** (extend) — `fromRunFindings()` produces the run URL and
  the run label; `fromProjectOutstanding()` still produces the project URL and
  label.
- **`AuditRunTest`** (extend) — `formattedDuration()`, including the null cases
  where a run never completed.

A single query assertion guards the N+1 risk on the list: rendering a page of runs
must not scale queries with row count.
