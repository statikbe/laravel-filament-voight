# INTLAR-222 — Implementation Plan (re-baselined)

**Spec (the contract):** `/Users/kristof/Sites/voight/modules/laravel-filament-voight/.claude/specs/INTLAR-222.md` — **42 live AC**, re-accepted 2026-08-28 after re-baselining against `origin/main` (CL-6, CL-7).
**Branch:** `feature/INTLAR-222-filament-resource-cleanup` (cut from `origin/main` @ f70d3ad).

> Supersedes the first plan, which was written against a tree 53 commits stale. Tasks 2, 3 and 9 of that plan are **gone** (AC 2, 3, 11 fixed upstream) and Task 1 is **replaced** (AC 1 withdrawn, AC 42 substituted).

## Binding rules for implementers

1. **The spec is the contract**, not this plan. Re-read it from disk each task. Where they disagree, the spec wins — say so.
2. Every task names its AC. Done = AC demonstrably met, not steps ticked. AC numbers are stable; withdrawn numbers are never reused.
3. **Never improvise around a gap.** Report `SPEC GAP: <description>` and stop. AC changes need user re-approval + a Change-log entry.
4. **Evidence, not assertion.** Paste command output. "Should pass" is not a result.
5. TDD: failing test first, then the fix. Existing tests that must keep passing **unmodified** are named per task.

## Verified baseline (do not re-derive)

| Check | Exit | Result |
|---|---|---|
| `php -d memory_limit=1G vendor/bin/pest --no-coverage` | 0 | **235 passed (680 assertions)** |
| `composer test` (default 128M) | **255** | `Allowed memory size of 134217728 bytes exhausted` — AC 42 |
| `vendor/bin/pint --test` | 0 | passed |
| `composer analyse` | 0 | `[OK] No errors` (129 files) |

## Global constraints

- **READ THE RIGHT VENDOR TREE.** Use `modules/laravel-filament-voight/vendor/` — this package pins **Filament v5.6.8** in its own lock, and that is what Pest and Larastan load. Do **not** read `/Users/kristof/Sites/voight/vendor/filament/`, which is `5.x-dev` and has a materially different API (e.g. it has `getCachedRelationManagers()` and `QueryBuilder::maxRules()`; v5.6.8 has neither). This mistake already cost one round in Task 4 and caused AC 22 to be withdrawn.

- **`voightTrans()` only**, never `__()`/`trans()`. Single lang file, existing block order. New tab keys under `models.<model>.tabs.<slug>` — a new sibling of `sections`/`actions`/`fields`; **no `tabs` block exists today**.
- **Severity is an appended accessor** (`Vulnerability.php:37,56`) — never `sortable()`/`searchable()`. Always route through `Severity::scoreRange()` (`Severity.php:95-104`).
- **Migrations:** never edit a `create_*.stub` (the consuming app has run them). New additive stubs only, mirrored into `tests/database/migrations/` starting at **`0001_01_01_000020`** (000014–000019 are taken).
- **QueryBuilder constraints** from `Filament\QueryBuilder\Constraints\*`. The `Filament\Tables\Filters\QueryBuilder\Constraints\*` paths are empty BC subclasses. No composer change.
- **Test auth convention (unchanged):** `$this->actingAs(new \Illuminate\Foundation\Auth\User)` for Filament tests (10 of 11 files); `Statikbe\FilamentVoight\Tests\Support\User` only when a user row must exist in the DB. `Livewire::test()`, not the pest `livewire()` helper. RMs get `['ownerRecord' => $m, 'pageClass' => X::class]`; pages get `['record' => $m->getRouteKey()]`.
- Every task ends with `vendor/bin/pint`, `composer analyse`, and its tests green.

## AC → Task map (42 live AC)

| AC | Task | AC | Task |
|---|---|---|---|
| 4, 42 | 1 | 27 | 8 |
| 10, 43, 44 | 2 | 28–39 | 9 |
| 7, 20 | 3 | 40 | 10 |
| 5, 6 | 4 | 25 (22 withdrawn) | 11 |
| 8, 9, 19 | 5 | 24 | 12 |
| 13, 14, 15, 16, 17, 18 | 6 | 21, 23 | 13 |
| 45, 46, 47 | 7 | 41 | 14 |
| 39 | **15** | 24, 50 | 12 |
| 26 | 8 | | |

**Sequencing:** 1 → 2 → 3 → 4 → 5 → 6 → 7 → 8 → 9 → 10 → 11 → 12 → 13 → 14. A sequenced chain, not a fan-out: nearly every task touches the shared lang file or the table schemas. Commit per task (per *table* within Task 9).

---

## Task 1 — Gate blockers (AC 42, 4)

**Files:** `composer.json` (scripts), `tests/ExampleTest.php` (delete)

1. Give the test script memory headroom: `"test": "@php -d memory_limit=-1 vendor/bin/pest --no-coverage"`. **Do not** weaken `failOnWarning="true"` in `phpunit.xml.dist`, and do not reintroduce a `<coverage>` request.
2. Delete `tests/ExampleTest.php`. **Keep `tests/DebugTest.php`** — real arch test banning `dd`/`dump`/`ray`. Leave it in place unless you also narrow `tests/Pest.php` (which currently applies `uses(TestCase::class)->in(__DIR__)`, so relocating alone buys nothing); if you don't narrow it, say so.
3. Evidence: `composer test` → exit 0 with results printed, 234 passing (235 − ExampleTest). `grep failOnWarning phpunit.xml.dist` → still `true`.
4. Commit: `fix(INTLAR-222): give test script memory headroom; drop skeleton test`

## Task 2 — Translations (AC 10, 43, 44)

**Files:** `resources/lang/en/filament-voight.php`; `EnvironmentsRelationManager.php`, `AlertSettingsRelationManager.php` (icons); `TeamResource/RelationManagers/UsersRelationManager.php`

1. Delete the 3 unused keys at `:107-109` (`models.project.fields.relation_manager_*`). Verify: `grep -rn relation_manager_ src/ tests/ resources/` → no hits.
2. Add `tabs` blocks. **Project needs none** (reuse `models.project.sections.*`). AuditRun: 1 key. Vulnerability: 2 (`details`, `description` — its sections are anonymous today). Customer/Team/Package: 1 each for the content tab.
3. **AC 43** — add `$icon` to `EnvironmentsRelationManager` and `AlertSettingsRelationManager` (Vulnerabilities and AuditRuns already have one; a half-iconed 8-tab strip looks unfinished).
4. **AC 44** — `UsersRelationManager` labels its name column with `models.customer.fields.name`: give it a correct key. `models.package.view.columns.*` is used as the label source by RMs in three different resources — move those shared labels to a neutral home.
5. Commit: `chore(INTLAR-222): tab keys, tab icons, fix mislabelled columns`

## Task 3 — Combined tabs on 4 resources (AC 7, 20)

**Files:** `Pages/View{Customer,Team,Package,AuditRun}.php`; `CustomerResource/RelationManagers/ProjectRelationManager.php`; `{Package,Customer,Team}Resource.php`; `ProjectResource.php`

1. On each of the four View pages add `hasCombinedRelationManagerTabsWithContent(): bool { return true; }` + `getContentTabLabel()`. Customer, Team and AuditRun have **one** RM each and today render a bare Livewire component with **no strip** — the `||` at `HasRelationManagers.php:134` is what makes this work for them.
2. `ProjectRelationManager` has no `getTitle()` → its tab label would fall back to a humanised default. Add one (6 of 7 RMs already do).
3. **AC 20** — string keys in `getRelations()` on `PackageResource:51-58`, `CustomerResource:59-64`, `TeamResource:59-64` (bare lists today → tab URLs read `?relation=0|1|2`). Remove `ProjectResource.php:62`'s `// return $schema;`, and the dead `$subNavigationPosition` (`:33`) **plus its import (`:5`)**.
4. Tests: tab labels render on each of the four pages.
5. Commit: `feat(INTLAR-222): combined top tabs on customer, team, package and audit-run views`

## Task 4 — Project 8-tab strip (AC 5, 6) ← the risky one

**Files:** `ProjectResource/Pages/ViewProject.php`, `ProjectResource/Schemas/ProjectInfoListSchema.php`

Final tab order: **General · Assignment · Settings · API Token · Environments · Alert Settings · Vulnerabilities · Audit Runs** (4 sections + 4 RMs).

`ViewRecord::content()` → `getRelationManagersContentComponent()` prepends exactly **one** synthetic content tab (`HasRelationManagers.php:134-145`), so 8 siblings need a custom `content()`.

**Four framework gotchas — the first plan got all four wrong:**

1. **Iterate `$this->getRelationManagers()`, NOT `ProjectResource::getRelations()`.** The base method filters through `canViewForRecord($record, static::class)` (`HasRelationManagers.php:50`); going via `getRelations()` **silently drops that authorization gate**.
2. **Build RM tabs with `$rmClass::getTabComponent($record, static::class)->schema([...])`** — the same call Filament makes (`RelationManager.php:157`). Hand-writing `Tab::make()` loses each RM's `getTitle()`, icon, icon position and future badge.
3. **Spread `...$rmClass::getDefaultProperties()`** into `Livewire::make()` (`RelationManager.php:319`) — it injects `['lazy' => true]` for lazy RMs; a hand-rolled array loses it.
4. **Do NOT call `livewireProperty('activeRelationManager')`.** That property is `#[Url(as: 'relation')]` and `renderingHasRelationManagers()` resets it to `array_key_first($managers)` whenever the value isn't a manager key — it would fight the four infolist tabs. Use `persistTabInQueryString()`, and keep `hasCombinedRelationManagerTabsWithContent()` **false** on `ViewProject`.

Steps:
1. Split `ProjectInfoListSchema`'s four sections (`:26` general, `:43` assignment, `:55` settings, `:64` api_token) into four static methods, bodies unchanged **except** dropping the four `h-full` `extraAttributes` (`:42, :54, :63, :119`) and the `Grid::make(2)` wrapper (`:24`). Keep the api_token section's `->visible(fn (?Project $record) => $record !== null)`.
2. Add `ViewProject::content()` building one `Tabs::make()->persistTabInQueryString()->contained(false)` with the 4 section tabs followed by the RM tabs, honouring gotchas 1–4.
3. Ensure the RMs don't also render below — **decide by test**: assert the Environments table appears exactly once.
4. Tests: 8 tab labels in order; Environments renders once; `?tab=` persists; nothing stacked below.
5. **If the custom `content()` proves fragile** (double-render, Livewire key collisions, lost authorization) — **stop and report**. Fallback is Task 3's treatment for Project too, which **changes AC 5 and needs user re-approval**.
6. Commit: `feat(INTLAR-222): single 8-tab strip on the project view page`

## Task 5 — Edit pages + Vulnerability tabs (AC 8, 9, 19)

1. `ProjectFormSchema` — wrap its four sections (`:25, :46, :68, :75`) in `Tabs`, dropping the four `h-full` attrs (`:45, :67, :74, :130`). Put the create-vs-edit gate on the API Token **Tab** so Create shows 3 and Edit shows 4.
2. `EditCustomer` / `EditTeam` — same combined-tabs pair as Task 3 (`EditRecord` supports it identically).
3. `VulnerabilityInfolistSchema` — wrap its two anonymous sections (`:20`, `:50`) in `Tabs` using the two new keys.
4. **AC 19** — add `getTitle()` to `ViewVulnerability` **and `ViewAuditRun`**. `AuditRunResource` has **no `$recordTitleAttribute`**, so its title needs a different expression than a bare `getRecordTitle()` — pick something meaningful (e.g. environment + started_at) and say what you chose.
5. Tests: 4 tabs on edit / 3 on create; Vulnerability 2 tabs; both titles render.
6. Commit: `feat(INTLAR-222): tabs on edit pages and vulnerability view`

## Task 6 — Correctness fixes (AC 13, 14, 15, 16, 17, 18)

1. **AC 13** — `ProjectRelationManager`: add `protected static ?string $relatedResource = ProjectResource::class;` (the pattern at `AuditRunsRelationManager.php:21`). That alone fixes the empty `EditAction` modal. Separately add a `configure()` parameter so the `customer_id` filter (`ProjectTableSchema:52-56`) doesn't render in a customer-scoped table.
2. **AC 14** — `OpenPackageWebsiteAction`: default `match` arm + hide when no URL. Extract `urlFor(Package): ?string`. (Latent hazard only — not reachable today.)
3. **AC 15/16** — TDD first: a bulk-delete against a Customer/Team **with** projects must leave it in the DB, and a forged single-record `mountAction` must be refused. Add a model-level `canBeDeleted(): bool` using `projects()->doesntExist()` (a query, not a hydrated collection), then use it in the bulk action **and** as a server-side guard — `->disabled()` alone is presentation-only, and there is **no policy layer** in the package.
4. **AC 17** — `ProjectTableSchema`: `->modifyQueryUsing(fn (Builder $q) => $q->with(['customer','team']))`. Assert a query budget, as `ListAuditRunsPageTest:65` does.
5. **AC 18** — `VulnerabilityResource`'s `index` is an orphan (`$shouldRegisterNavigation = false`, nothing references `getUrl('index')`). Link it from somewhere reachable, or record in code that it is deliberately deep-link-only.
6. Commit per concern.

## Task 7 — Reinstated findings (AC 45, 46, 47)

1. **AC 45** — scope `AuditRunsRelationManager`'s inherited `environment` SelectFilter to the owner project. `AuditRunTableSchema:71` uses `->relationship('environment','name')` unconstrained, so a Project page currently lists **every** environment. Test: two projects each with environments; the filter offers only the owner's.
2. **AC 46** — `PackageResource/ActiveFindingsRelationManager`: add `->recordActions([ViewFindingVulnerabilityAction::make()])` (chain ends at `->groups(...)`, `:128`). Test with `assertTableActionHasUrl`, mirroring `VulnerabilitiesRelationManagerTest:64-79`.
3. **AC 47** — `AuditRunResource/FindingsRelationManager` (zero filters today): `package_type` (from Task 8's shared class), `has_fix`, package select; make `installed_version`, `fixed_version`, `summary` searchable for parity with `VulnerabilitiesRelationManager`. Plus a **separate** row action for `KnownVulnerabilitiesRelationManager` — `ViewFindingVulnerabilityAction`'s closure is typed to `AuditFinding` and cannot serve `VulnerablePackageRange` rows.
4. Commit per AC.

## Task 8 — Extract shared filters + severity (AC 26, 27)

1. **AC 26** — create one shared filter source (e.g. `src/Filters/AuditFindingFilters.php`) with `latestOnly()`, `packageType()`, `observedAt()`, copied **verbatim** from `VulnerabilitiesRelationManager:81-126` — which is character-for-character identical to `ActiveFindingsRelationManager:81-126`. Replace all call sites (`package_type` ×3 incl. `ActiveFindingsWidget:78`). **Existing tests must pass unmodified** — `ActiveFindingsRelationManagerTest`, `VulnerabilitiesRelationManagerTest`, `ActiveFindingsWidgetTest`, `FindingsRelationManagerTest`. If any needs editing, the extraction changed behaviour and is wrong.
2. **AC 27** — add a `multiple()` severity filter ORing `Severity::scoreRange()` per selected case. Five surfaces: `VulnerabilityTableSchema` (score on the **base** table → direct `where`, no `whereHas`), `VulnerabilitiesRelationManager`, `ActiveFindingsRelationManager`, `ActiveFindingsWidget` (`voight_vulnerabilities` **already joined** at `:37-39` → plain `whereBetween`), `AuditRunResource/FindingsRelationManager`. **`KnownVulnerabilitiesRelationManager`'s filter already exists but is single-value (`:71-86`) — upgrade it to `->multiple()`, don't recreate it.**
3. TDD per surface: `Critical` → only ≥9.0; `Critical`+`High` → the union. Reuse the CVSS-bucket pattern from `KnownVulnerabilitiesRelationManagerTest`.
4. Commit: `refactor(...)` then `feat(...)`.

## Task 9 — Per-table filter sweep (AC 28–39)

TDD per filter; **commit per table**. Build only on package-known columns. Reuse `Severity::scoreRange()`, `AuditRun::latestIdsPerEnvironment()`, `Package::projects()` and `Project::findings()` (the two HasManyDeep relations no filter currently uses). The per-table filter lists are in the spec, AC 28–39 — work them in that order and do not restate them here.

Highlights that are easy to miss: **AC 32** — `EnvironmentsRelationManager` renders **no search box at all** because `name` is `sortable()` but not `searchable()`; fix that first. **AC 33** — `AlertSettingsRelationManager` has zero filters *and* zero searchable columns despite new recipients/`slack_channel`/`last_sent_at` columns. **AC 37** — `ActiveFindingsWidget` has **no sortable column at all**. **AC 38** — replace `MostVulnerableProjectsWidget`'s hardcoded `9.0` (`:37`) and `[7.0,8.9]` (`:40`). **AC 39** — the DependencySync surface is new UI; note `DependencySyncStatus` is *not* a dead enum (used in jobs/services), only its UI is missing.

## Task 10 — Filter UX defaults (AC 40)

`deferFilters()` + `persistFiltersInSession()` on the heavy tables. None of `deferFilters`, `persistFiltersInSession`, `persistSortInSession`, `filtersLayout`, `filtersFormColumns` appear anywhere in `src/` today. Test that a filter survives a re-mount.

## Task 11 — QueryBuilder global config (AC 25; AC 22 WITHDRAWN)

**AC 22 is withdrawn** — Filament v5.6.8's `QueryBuilder` (403 lines) has **no** `maxRules`, `maxNestingDepth` or `exceedsRuleLimits`; those are `5.x-dev` only. Do **not** call them; do **not** add a capability check (the user chose plain withdrawal). Unbounded rule count/nesting for authenticated panel users is an accepted, documented risk.

What remains: in `FilamentVoightServiceProvider::packageBooted()`, add `QueryBuilder::configureUsing()` setting only `constraintPickerColumns()` and `constraintPickerWidth()` — both exist (`vendor/filament/tables/src/Filters/QueryBuilder.php:253,290`), and `configureUsing()` comes from `Filament\Support\Concerns\Configurable`.

**AC 25** — add a code comment recording that `SelectConstraint::options()` is UI-only: the submitted value reaches `whereIn` without being validated against the options list, so it is never an authorization boundary. Any team/customer scoping belongs in `modifyQueryUsing`. Add a test proving a forged option value cannot widen results beyond what `modifyQueryUsing` allows.

## Task 12 — Index migrations (AC 24)

New additive stub(s) + mirrors in `tests/database/migrations/` starting at **`0001_01_01_000020`**. Priority: **`voight_vulnerabilities.vulnerability_score`** first (every severity filter + 6 of 7 `defaultSort`s), then `voight_audit_findings` composites `['audit_run_id','vulnerability_id']` and `['package_id','audit_run_id']`, `voight_environments.scanned_at`, `voight_audit_runs.status`/`.trigger`/`.completed_at`, `voight_packages.latest_version_updated_at`, `voight_vulnerabilities.published_at`, `voight_projects.is_muted`. Extend `tests/database/MigrationTest.php`. Verify `git diff --stat database/migrations/` shows **only new files**.

## Task 13 — Query builders on 4 list resources (AC 21, 23)

`ProjectTableSchema`, `PackageTableSchema`, `VulnerabilityTableSchema`, `AuditRunTableSchema`. Import from `Filament\QueryBuilder\Constraints\*`. `RelationshipConstraint` does **not** support `nullable()` — use `->emptyable()`; leave `->multiple()` off `BelongsTo`. Layout `FiltersLayout::AboveContentCollapsible` + `filtersFormColumns(3)` — the builder calls `columnSpanFull()` on itself (`QueryBuilder.php:85`) and is unusable in the default dropdown.

**Open risk to close here:** on `VulnerabilityTableSchema` the AC 27 severity filter and this query builder apply to the same query. Test that they compose. If they conflict, the plain severity filter wins and severity becomes a QueryBuilder constraint instead — record the decision in the spec Change log.

## Task 15 — DependencySyncResource (AC 39)

**Added late — orchestrator oversight (CL-15).** CL-14 amended AC 39 from a relation manager to a standalone resource after Task 9 proved a relation manager impossible (a 5th Project manager renders 9 tabs, failing AC 5/49's tab-count, icon and double-render assertions). The amendment was recorded but **no task was scheduled**; caught by the Task 14 implementer.

**Blocked on Task 13** — needs `resources/lang/en/filament-voight.php` (Task 13 may add constraint labels) and must run gates on a settled tree.

Build a standalone `DependencySyncResource` (list + view, read-only) in the existing `Dependencies` navigation group — the group `PackageResource` already uses (`navigation.dependencies`).

**Model surface** (`src/Models/DependencySync.php`): `status` cast to `DependencySyncStatus`, `lockfile_paths` cast to array, `synced_at` datetime, plus `lockfile_hash`, `package_count`, `error_message`, and `environment()` BelongsTo.

**Filters AC 39 names:** `status` (the enum), `has_error` (on nullable `error_message`), `synced_at` range, `package_count`.

**Already in place, do not recreate:** `models.dependency_sync` has `label`, `plural` and six `fields.*` keys. Register in `FilamentVoightPlugin.php:25-32`.

**Follow the read-only precedent** — `PackageResource`/`VulnerabilityResource`/`AuditRunResource` all set `canCreate(): false` and define the infolist on the **View page**, not the resource. Give the View page the combined-tabs treatment from AC 7 only if it has a relation manager; it has none, so a plain schema `Tabs` or no tabs at all is correct — decide and justify.

**Why a resource and not a relation manager:** cross-project triage ("show me every failed sync") is the enum's real operational value, and `DependencySyncStatus` is *not* a dead enum — it drives `ProcessLockFilesJob`, `RunOsvScanJob` and `LockFileSyncService`. Only its UI was missing.

## Task 14 — Docs (AC 41)

`specs/02-project-management.md` mandates "All forms are wrapped in `Section` components" (`:59`) and names the four Project sections (`:25`) — the tabs work contradicts both. Update. Mark in `specs/05-search.md` / `08-reporting.md` which documented filters now exist and which remain future work.

---

## Phase 6 gates (full branch)

1. `composer test` → exit 0, strictly more tests than the 234 baseline.
2. `vendor/bin/pint --test` → exit 0.
3. `composer analyse` → exit 0.
4. Smell audit on the branch diff.
5. **Security review — fires:** AC 15/16 change a delete-authorization path; AC 21–25 add user-controllable query construction whose rule tree lives in tamperable Livewire state; AC 24 adds migrations; Task 4 gotcha 1 concerns an authorization filter. **Brief the reviewer that two items are known and deliberately deferred** — the plaintext token in a `->persistent()` notification, and the package-wide absence of a policy layer — so they aren't re-reported as new.
6. Senior branch review against the spec → must return 🟢 Ship.

Every gate run (including re-runs) is appended to the spec's Gate ledger with exit code + pasted output. Max 3 fix iterations per gate, then stop and present options.

## Close-out

42-row spec-compliance checklist (AC · criterion · implemented in · verified by · evidence), no blanks. Then `git fetch` (the intake fetch will be stale), check whether `origin/main` advanced past the merge-base, and only then decide integration. Jira comment + transition payloads shown for confirmation before any write.
