# Dependency Graph

**Date:** 2026-09-30 (revised 2026-10-01)
**Status:** approved design, not yet implemented
**Prerequisite for:** `13-mcp-server.md` (upgrade analysis needs dependency paths)
**Related:** `14-environment-health.md` (sync warnings for unsupported input)

Store the real dependency graph of every environment — every installed node,
every edge — so we can answer "why is this package installed?" and "which direct
dependency do I bump to get rid of it?".

---

## Problem

The sync pipeline stores a lossy tree, and three of its gaps are security gaps.

1. **Nested npm versions are dropped.** `PackageLockParser` skips every
   `node_modules/…/node_modules/x` entry. A second, older version of a package
   installed deeper in the tree is never stored. Post-sync `/locks` scans do see
   it (osv-scanner parses the raw lockfile), but
   `RecordEnvironmentAuditRunsService::record()` joins findings back through
   `environmentPackages` on `type|name|version`, so the finding is silently
   discarded. The nightly `/packages` sweep never asks about it at all.
2. **Only one finding per package per run.** `createAuditFinding()` uses
   `firstOrCreate` on `(audit_run_id, package_id, vulnerability_id)`. When two
   installed versions of one package share a vulnerability, the second version
   is never recorded — which would undo half of fix 1.
3. **npm `is_direct` is wrong.** It reads `$lock['dependencies']`. In lockfile v2
   that is the legacy flat list of *every* package (everything is direct); in v3
   the key is absent (nothing is direct). Direct dependencies live in
   `packages[""].dependencies` / `packages[""].devDependencies`.
4. **Composer marks everything direct** (`ComposerLockParser.php:24`), and yarn
   does too without a `package.json`. The uploader (`script.sh`) only sends
   lockfiles, so in practice yarn never gets its `package.json` either.
5. **Only one parent is kept, matched by name.** `buildReverseDependencyMap()`
   keeps the first requirer (`??=`) and stores it as `parent_package_id`, a FK to
   `Package` — not to the installed node. Multiple parents and multiple installed
   versions cannot be represented.
6. **`Package` models are resolved by name only.** `resolvePackageModels()` keys
   by `name`, so an npm package can be attached to a composer `Package` with the
   same name (the `(name, type)` unique index exists, but the lookup ignores
   `type`).

---

## Data model

No new dependency. Graph traversal is done in PHP (see [Query API](#query-api));
the database only stores nodes and edges.

### `voight_environment_package_dependencies` (new)

An edge between two installed nodes of the same environment.

| Field      | Type   | Notes                                                    |
|------------|--------|----------------------------------------------------------|
| parent_id  | ulid   | FK → `voight_environment_packages`, cascade on delete    |
| child_id   | ulid   | FK → `voight_environment_packages`, cascade on delete    |
| constraint | string | nullable, the declared range, e.g. `^4.17`               |
| kind       | string | `DependencyKind`: `dependency`, `optional` or `peer`     |

- Primary key `(parent_id, child_id)`; index on `child_id` (ancestor walks go
  child → parent).
- No timestamps: edges are rewritten wholesale on every sync.
- Both ends always belong to the same environment. This is guaranteed by the
  job, not by the schema.

### `DependencyKind` enum (new)

`src/Enums/DependencyKind.php`, backed by string, with `HasLabel` like the other
enums (labels via `voightTrans('enums.dependency_kind.*')`):

- `Dependency` — a regular requirement (npm `dependencies`, yarn
  `dependencies`, composer `require`).
- `Optional` — npm / yarn `optionalDependencies`.
- `Peer` — npm `peerDependencies`. The parent does not bring its own copy; it
  plugs into the one the project has. Bumping a peer parent never changes which
  version of the child is installed, so a peer hop must never be presented as
  "bump the parent to fix the child".

### `voight_environment_packages` changes

- Drop `parent_package_id` (and its FK).
- An environment may now contain **several rows for the same package**: one row
  per installed node. That includes two rows with the *same* version, when npm
  installs it at two nested paths. There is no unique constraint on
  `(environment_id, package_id)` today, so no index change is needed.

### Migrations

Follow the repo's additive stub convention:

- `create_voight_environment_package_dependencies_table.php.stub`
- `drop_parent_package_id_from_voight_environment_packages_table.php.stub`

Register both in `FilamentVoightServiceProvider::getMigrations()` and mirror them
in `tests/database/migrations/`.

### Model

`EnvironmentPackage` gets two plain `belongsToMany` relations on the edge table,
both with `withPivot('constraint', 'kind')` and `kind` cast to `DependencyKind`:

- `children()` — its dependencies (`parent_id` → `child_id`).
- `parents()` — its dependents (`child_id` → `parent_id`).

Remove `parentPackage()` and the `parent_package_id` PHPDoc property; update
`EnvironmentPackageFactory`.

Recursive walks are not Eloquent relations; they live in `DependencyGraphService`.

---

## Parser contract

Edge resolution depends on the lockfile format, so each parser resolves its own
edges. The job only maps parser keys to database IDs.

Every parser returns:

```php
/** @return array<int, array{
 *     key: string,
 *     name: string,
 *     version: string,
 *     type: PackageType,
 *     is_direct: bool,
 *     is_dev: bool,
 *     dependencies: array<int, array{key: string, constraint: string|null, kind: DependencyKind}>,
 * }> */
```

`key` is unique within one parsed lockfile. `dependencies` only contains keys
that exist in the same result; unresolvable requirements are dropped (logged at
debug level), never guessed.

### Direct-dependency fallback

When no manifest (`composer.json` / `package.json`) is available: a node is
direct when **no other node in the lockfile depends on it**. This is wrong only
for a package that is both required by the root and by another package, which is
acceptable until the uploader sends manifests. In fallback mode every root is
treated as a production dependency for `is_dev` propagation.

### Dev propagation

When a lockfile has no per-entry dev flag, `is_dev` is derived from the graph:
a node is dev when **every direct dependency that reaches it is dev**, i.e. it
is not reachable from any production root. Each parser that needs it runs this
over its own result before returning (shared helper, one forward walk from the
production roots).

### npm — `package-lock.json` (v2, v3)

- `key` = the `packages` path, e.g. `node_modules/a/node_modules/b`.
- `name` = the entry's `name` field when present (aliases), otherwise the
  segment after the last `node_modules/` (keeps `@scope/name`). Edge resolution
  always uses the **path segment**, because that is the name Node resolves.
- **Nested entries are included.** Entries with `link: true` are skipped.
- **Workspaces:** entries whose path is not under `node_modules/` (e.g.
  `packages/foo`) are workspace members, not installed packages. They are not
  nodes, but their `dependencies`, `devDependencies` and `optionalDependencies`
  count as declared by the root (see `is_direct`).
- Edges: for every name in `dependencies`, `optionalDependencies` and
  `peerDependencies` of the entry, apply Node's resolution rule — look for
  `<own path>/node_modules/<dep>`, then walk up one `node_modules` level at a
  time. A path inside a workspace (`packages/foo/node_modules/x`) walks up to
  `packages/foo/node_modules/<dep>` and then to the root `node_modules/<dep>`.
  `constraint` is the declared range; `kind` follows the map the name came
  from. A name listed in several maps of one entry yields one edge, with
  precedence `dependency` > `optional` > `peer`. Unresolved optional and peer
  dependencies are expected and dropped silently.
- `is_direct`: the node is what the root or a workspace member resolves its
  declared dependency to: `node_modules/<name>` for the root,
  `packages/foo/node_modules/<name>` falling back to `node_modules/<name>` for a
  member.
- `is_dev`: `dev: true` **or** `devOptional: true` on the entry. No propagation
  needed; npm already marks transitive dev packages.
- Lockfile v1 (no `packages` key) is out of scope; the parser throws
  `UnsupportedLockfileException` (`SyncWarning::NpmLockfileV1Unsupported`,
  spec 14). Today it silently returns `[]`.

### yarn — `yarn.lock` (v1)

- `key` = the block's first descriptor (e.g. `lodash@^4.17.0`).
- Build a descriptor map: every comma-separated descriptor of a block points to
  that block's key.
- Edges: each `dependencies` / `optionalDependencies` entry `name "range"` is
  resolved via the descriptor `name@range`. `constraint` is the range; `kind`
  is `dependency` or `optional`. Yarn v1 does not install peers, so there are no
  peer edges.
  The block parser must keep the range, which it currently discards.
- `is_direct`: from `package.json` when present (a name listed there maps to the
  block matching `name@<declared range>`); otherwise the fallback.
- `is_dev`: roots from `devDependencies` are dev, then [dev
  propagation](#dev-propagation). Without `package.json` everything is
  production.
- Yarn berry (`__metadata`, `resolution:`) and yarn workspaces are out of scope.
  - Berry: the parser throws `UnsupportedLockfileException`
    (`SyncWarning::YarnBerryUnsupported`, spec 14).
  - Workspaces: `yarn.lock` does not list workspace members, so their
    declarations are invisible without each member's `package.json`. When the
    root `package.json` has a `workspaces` key, `ProcessLockFilesJob` adds
    `SyncWarning::YarnWorkspacesUnsupported` to the sync (spec 14) and the
    parser continues with the fallback for the members' dependencies. No
    exception: the sync still produces useful data, and failing it would hide
    every finding of that environment.

### composer — `composer.lock`

- `key` = the package name, lowercased (Composer names are case-insensitive).
- **Name index:** every package is indexed under its own name and under every
  name in its `replace` and `provide` maps. A real package of that name wins
  over a replacer/provider. This is required: `laravel/framework` replaces all
  `illuminate/*` packages, and without it most edges into the framework are
  lost.
- Edges: `require` entries resolved through the name index. A provided name with
  several providers gets an edge to each. Platform requirements (`php`,
  `php-64bit`, `hhvm`, `ext-*`, `lib-*`, `composer`, `composer-plugin-api`,
  `composer-runtime-api`) are skipped. `constraint` is the declared range
  (`self.version` is kept literally); `kind` is always `dependency`.
  `packages-dev` entries also resolve
  `require` (not `require-dev`, which only applies to the root).
- `is_direct`: listed in `composer.json` `require` or `require-dev` (companion
  file found via the existing `findCompanionFile()`), resolved through the same
  name index; otherwise the fallback.
- `is_dev`: whether it comes from `packages-dev`, as today. Composer already
  puts dev-only transitive packages there.

---

## Sync job

`ProcessLockFilesJob::syncPackages()` inside the existing transaction:

1. Delete the environment's `EnvironmentPackage` rows (edges cascade).
2. Resolve `Package` models keyed by **`type|name`** instead of `name`.
3. Insert nodes in chunks of 500, generating the ULID up front and recording
   `lockfile + key → id`. Keys are namespaced per lockfile, because one
   environment can sync a `composer.lock` and a `package-lock.json` together.
4. Insert edges in chunks of 500 by mapping each parser edge through that map.
   Duplicate `(parent, child)` pairs are removed before inserting.
5. `buildReverseDependencyMap()` is removed.

`package_count` counts nodes, so it will rise for npm projects with nested
versions.

`parseLockFiles()` passes the companion `composer.json` to `ComposerLockParser`
the same way it already passes `package.json` to `YarnLockParser`.
`PackageLockParser` needs no manifest, because `packages[""]` holds the root.

### Audit findings

`RecordEnvironmentAuditRunsService::record()`:

- `createAuditFinding()` keys `firstOrCreate` on
  `(audit_run_id, package_id, vulnerability_id, installed_version)`, so each
  vulnerable installed version gets its own finding.
- Iterate distinct `type|name|version` keys instead of nodes, so two nodes with
  the same version do not do the same work twice.

`AuditFinding` keeps pointing to `Package` + `installed_version`, not to a node:
nodes are recreated on every sync and findings must outlive them.

---

## Query API

`Statikbe\FilamentVoight\Services\DependencyGraphService`, shared by Filament and
the MCP server (spec 13).

```php
/**
 * The installed nodes in the environment that match a finding.
 *
 * @return Collection<int, EnvironmentPackage>
 */
public function nodesFor(Environment $environment, Package $package, string $version): Collection;

/**
 * Paths from a direct dependency down to the node, shortest first.
 */
public function pathsToRoots(EnvironmentPackage $node, int $maxPaths = 10, int $maxDepth = 15): DependencyPathResult;

/**
 * The distinct direct dependencies whose subtree contains the node: what you would bump.
 *
 * @return Collection<int, EnvironmentPackage>
 */
public function introducingDirectDependencies(EnvironmentPackage $node, int $maxDepth = 15): Collection;
```

Value objects in `src/Support/`, next to `ScanResponse`:

- `DependencyPath`: `steps` — ordered root → node, each
  `array{node: EnvironmentPackage, constraint: string|null, kind: DependencyKind|null}`,
  where `constraint` and `kind` describe the edge leading **into** that node
  (`null` for the root).
- `DependencyPathResult`: `paths` (`Collection<int, DependencyPath>`) and
  `truncated` (`bool`).

### Traversal

1. Load the environment's edges once: one query on the edge table joined to
   `voight_environment_packages` on `environment_id`, giving
   `child_id → [parent_id, constraint, kind]`. Load the referenced nodes with their
   `package` in a second query.
2. Breadth-first search upward from the node over partial paths. Each partial
   path carries its own visited set, so a cycle ends that path instead of
   looping.
3. A partial path is **complete** when its head is a direct node; it is not
   extended further, since that direct node is what you bump. A head with no
   parents that is not direct (an orphan left by a dropped unresolvable edge)
   also completes the path, so it is never lost.
4. Stop when `maxPaths` complete paths are found, when the next path would
   exceed `maxDepth`, or after a fixed budget of 10,000 expanded partial paths.
   `truncated` is `true` when any limit stopped the search with work remaining.
5. Because the search is breadth-first, paths come out shortest first.

A direct node returns one path containing only itself (it may also be reachable
transitively; the direct path is the answer).

Peer edges are traversed like any other: they are a real reason a package is
installed. Callers use each step's `kind` to word the result.

`introducingDirectDependencies()` is the same walk, collecting the distinct complete-path heads without the path
cap (`maxDepth` and the expansion budget still apply).

Querying across environments ("which projects pull in lodash < 4.17.21") needs
only `EnvironmentPackage` + `Package` and a version comparison; it belongs to the
MCP spec and is not part of this service.

---

## Filament

Minimal. The expandable tree promised in spec 08 is out of scope; it only needs
`children()` one level at a time when it is built.

`PackageResource` → `InstallationsRelationManager`:

- The **Parent Package** column becomes **Required by**: the names of the node's
  `parents()`, eager loaded. Add a `required_by` translation key next to
  `models.package.view.columns.parent_package` and remove both now-unused
  `parent_package` keys.
- A **Why installed?** row action (see below).

### Why installed? action

One reusable `WhyInstalledAction` (under `PackageResource/Actions/`, like
`VulnerabilityResource/Actions/ViewFindingVulnerabilityAction`), used in three
places:

| Where                                               | Record               | Nodes shown                                              |
|-----------------------------------------------------|----------------------|----------------------------------------------------------|
| `PackageResource` → `InstallationsRelationManager`  | `EnvironmentPackage` | that node                                                |
| `AuditRunResource` → `FindingsRelationManager`      | `AuditFinding`       | `nodesFor(run environment, package, installed_version)`  |
| `PackageResource` → `ActiveFindingsRelationManager` | `AuditFinding`       | same                                                     |

- The modal lists `pathsToRoots()` per node as
  `a@1.0 → b@2.1 (^2.0) → c@3.4 (~3.4)`. Optional hops are labelled
  *optional*, peer hops *peer*, using the `DependencyKind` label.
- A "more paths not shown" note appears when `truncated`.
- Findings belong to an audit run, but nodes reflect the latest sync. When
  `nodesFor()` returns nothing (the version is no longer installed), the modal
  says so instead of showing an empty list.
- The modal content is a Blade view, not HTML built in PHP. All strings go
  through `voightTrans()`.

### Counts

With several nodes per package and environment, `counts('environmentPackages')`
no longer means "packages" or "environments":

- `EnvironmentsRelationManager` (`environment_packages_count`): count distinct
  `package_id`. The column keeps its label.
- `PackageTableSchema` (`environment_packages_count`): count distinct
  `environment_id`, matching what `PackageInfolistSchema` already does.

---

## Uploaders

`script.sh` is not in this repository; its change ships to projects separately.

- Also send `composer.json` and `package.json` when present next to the
  lockfiles, in the same `lockfiles[]` multipart field. The API and
  `SyncLockFileRequest` do not change.
- The artisan uploader `voight:sync-lockfile` filters on
  `FilamentVoightConfig::getAllowedLockfileNames()`, whose default includes
  `package.json` but not `composer.json`. Add `composer.json` to the default
  and to the published config's `lockfiles.allowed_names`.
- `RunOsvScanJob::resolveLockfileContents()` already allowlists lockfile names,
  so manifests are never forwarded to the Lambda.
- Until a project's uploader is updated, the direct-dependency fallback applies.

**No Lambda change is needed.** `/locks` already sees nested versions; `/packages`
receives a longer `(ecosystem, name, version)` list with the same shape.

---

## Rollout

### Existing data

Collected data is not precious: every environment's latest lockfiles are still on
the Voight disk, so the graph is rebuilt from them instead of migrated.

New command `voight:reprocess-lockfiles`, modelled on `voight:run-osv-scan`:

- Options `--project=` and `--environment=` limit the selection, as in
  `voight:run-osv-scan`. Without them, every environment.
- For each selected environment, re-dispatch `ProcessLockFilesJob` for its latest
  completed `DependencySync` (the files on disk always belong to that sync).
  Environments without one are listed as skipped. The job already chains a
  post-sync `RunOsvScanJob`, so findings are rebuilt too.
- `--fresh` first deletes, for the selected environments, all
  `EnvironmentPackage` rows (edges cascade) and all `AuditRun` rows (findings
  cascade). `Package`, `Vulnerability`, `VulnerablePackageRange` and
  `AlertNotificationLog` are kept.
- The upgrade notes say: run the migrations, then
  `php artisan voight:reprocess-lockfiles --fresh`.

Alerts are **not** suppressed during the rebuild. `NewFindingFilter` dedupes on
`(alert setting, vulnerability, package)` and its log is kept, so only genuinely
new package/vulnerability pairs — the nested versions that were dropped before —
are sent.

`Package` rows wrongly shared across types (problem 6) end up with no
installations after the rebuild; they are harmless and not cleaned up.

### Impact

After the first re-sync of an npm environment, **findings for nested versions
appear** that were silently dropped before, and packages installed at two
vulnerable versions get two findings. Finding counts, the most-vulnerable
widgets and alerts may jump. This is the intended fix, and the changelog entry
must say so.

---

## Testing

Fixtures under `tests/Fixtures/lockfiles/` taken from real projects and trimmed:

- npm v3 with a nested duplicate version, the same version at two nested paths,
  a scoped package, an alias, a workspace member with a `link: true` entry, a
  `devOptional` entry, a dev-only subtree, a cycle, and a name listed in both
  `dependencies` and `peerDependencies` of one entry.
- npm v2 (legacy `dependencies` key present) to prove it is ignored.
- yarn v1 with and without `package.json`, including one name at two versions
  and a transitive dependency of a dev dependency; a root `package.json` with a
  `workspaces` key.
- composer with and without `composer.json`, including platform requirements,
  a `replace` (`laravel/framework` + a package requiring `illuminate/support`),
  a `provide`, and a mixed-case requirement.

Tests:

- **Parsers:** keys, edge resolution (Node's walk-up rule including workspaces,
  yarn descriptors, composer replace/provide), constraints, edge `kind` and its
  precedence, `is_direct` /
  `is_dev` with and without manifests, dev propagation, platform requirements
  skipped, unresolvable requirements dropped.
- **`ProcessLockFilesJob`:** nodes and edges persisted; a composer and npm
  lockfile in one sync do not cross-link; re-sync replaces edges (cascade); same
  name at two versions yields two rows; `Package` keyed by `type|name`; a yarn root `package.json` with
  `workspaces` stores `YarnWorkspacesUnsupported` and still syncs.
- **`DependencyGraphService`:** paths ordered root → node and shortest first;
  constraint and kind per step; peer edges traversed; multiple roots; direct node; orphan node; `maxPaths`,
  `maxDepth` and expansion budget each set `truncated`; a result that exactly
  fills `maxPaths` is not `truncated`; a cycle terminates; `nodesFor()` returns
  every matching node.
- **Audit findings:** a nested vulnerable npm version in a `/locks` response
  produces an `AuditFinding`; two versions of one package with the same
  vulnerability produce two findings (HTTP faked, no live Lambda).
- **`voight:reprocess-lockfiles`:** dispatches one job per environment for its
  latest completed sync, skips environments without one; `--project` /
  `--environment` filters; `--fresh` deletes nodes and audit runs of the
  selected environments only and keeps notification logs.
- **Filament:** Required by column; Why installed? on all three tables
  (including the truncated note, peer/optional labels, and a finding whose
  version is no longer installed); distinct counts.
- **Migrations:** test migrations mirror the new stubs.

---

## Out of scope

- Yarn berry, yarn workspaces, pnpm and bun parsing (the uploader already sends
  those files; no parser exists yet).
- npm lockfile v1.
- `RunOsvScanJob::resolveLockfileContents()` keys files by basename, so two
  `package-lock.json` files from different directories collide. Separate fix.
- The full expandable dependency tree UI (spec 08).
