# Dependency Graph

**Date:** 2026-09-30
**Status:** approved design, not yet implemented
**Prerequisite for:** `13-mcp-server.md` (upgrade analysis needs dependency paths)

Store the real dependency graph of every environment — every installed node,
every edge — so we can answer "why is this package installed?" and "which direct
dependency do I bump to get rid of it?".

---

## Problem

The sync pipeline stores a lossy tree, and two of its gaps are security gaps.

1. **Nested npm versions are dropped.** `PackageLockParser` skips every
   `node_modules/…/node_modules/x` entry. A second, older version of a package
   installed deeper in the tree is never stored. Post-sync `/locks` scans do see
   it (osv-scanner parses the raw lockfile), but
   `RecordEnvironmentAuditRunsService::record()` joins findings back through
   `environmentPackages` on `type|name|version`, so the finding is silently
   discarded. The nightly `/packages` sweep never asks about it at all.
2. **npm `is_direct` is wrong.** It reads `$lock['dependencies']`. In lockfile v2
   that is the legacy flat list of *every* package (everything is direct); in v3
   the key is absent (nothing is direct). Direct dependencies live in
   `packages[""].dependencies` / `packages[""].devDependencies`.
3. **Composer marks everything direct** (`ComposerLockParser.php:24`), and yarn
   does too without a `package.json`. The uploader (`script.sh`) only sends
   lockfiles, so in practice yarn never gets its `package.json` either.
4. **Only one parent is kept, matched by name.** `buildReverseDependencyMap()`
   keeps the first requirer (`??=`) and stores it as `parent_package_id`, a FK to
   `Package` — not to the installed node. Multiple parents and multiple installed
   versions cannot be represented.

---

## Data model

### New dependency

`staudenmeir/laravel-adjacency-list` (approved), used in **graph mode**
(`HasGraphRelationships`). Same author as the existing
`staudenmeir/eloquent-has-many-deep`. Requires recursive CTE support, which all
supported databases have (MySQL 8+, MariaDB 10.2+, PostgreSQL, SQLite 3.8.3+).

### `voight_environment_package_dependencies` (new)

An edge between two installed nodes of the same environment.

| Field      | Type   | Notes                                                    |
|------------|--------|----------------------------------------------------------|
| parent_id  | ulid   | FK → `voight_environment_packages`, cascade on delete    |
| child_id   | ulid   | FK → `voight_environment_packages`, cascade on delete    |
| constraint | string | nullable, the declared range, e.g. `^4.17`               |

- Primary key `(parent_id, child_id)`; index on `child_id` (ancestor walks go
  child → parent).
- No timestamps: edges are rewritten wholesale on every sync.
- Both ends always belong to the same environment. This is guaranteed by the
  job, not by the schema.

### `voight_environment_packages` changes

- Drop `parent_package_id` (and its FK).
- An environment may now contain **several rows for the same package** at
  different versions (one row per installed node). There is no unique constraint
  on `(environment_id, package_id)` today, so no index change is needed.

### Migrations

Follow the repo's additive stub convention:

- `create_voight_environment_package_dependencies_table.php.stub`
- `drop_parent_package_id_from_voight_environment_packages_table.php.stub`

Register both in `FilamentVoightServiceProvider::getMigrations()` and mirror them
in `tests/database/migrations/`.

### Model

`EnvironmentPackage` uses `HasGraphRelationships`:

- `getPivotTableName()` → `voight_environment_package_dependencies`
- `getPivotColumns()` → `['constraint']`
- Cycle detection enabled (`enableCycleDetection()` → `true`): npm graphs can
  contain cycles.
- Relations used by this spec: `children()` (its dependencies), `parents()` (its
  dependents), `ancestors()` and `descendants()` (recursive, with `depth` and
  `path`).
- Remove `parentPackage()` and the `parent_package_id` PHPDoc property; update
  `EnvironmentPackageFactory`.

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
 *     dependencies: array<int, array{key: string, constraint: string|null}>,
 * }> */
```

`key` is unique within one parsed lockfile. `dependencies` only contains keys
that exist in the same result; unresolvable requirements are dropped (logged at
debug level), never guessed.

### Direct-dependency fallback

When no manifest (`composer.json` / `package.json`) is available: a node is
direct when **no other node in the lockfile depends on it**. This is wrong only
for a package that is both required by the root and by another package, which is
acceptable until the uploader sends manifests.

### npm — `package-lock.json` (v2, v3)

- `key` = the `packages` path, e.g. `node_modules/a/node_modules/b`.
- `name` = the segment after the last `node_modules/` (keeps `@scope/name`), or
  the entry's `name` field when present (aliases).
- **Nested entries are included.** Entries with `link: true` (workspaces) are
  skipped.
- Edges: for every name in `dependencies`, `optionalDependencies` and
  `peerDependencies` of the entry, apply Node's resolution rule — look for
  `<own path>/node_modules/<dep>`, then walk up one `node_modules` level at a
  time to `node_modules/<dep>`. `constraint` is the declared range.
- `is_direct`: the name is in `packages[""].dependencies`,
  `devDependencies` or `optionalDependencies`, **and** the key is the top-level
  `node_modules/<name>`.
- `is_dev`: the entry's `dev` flag.
- Lockfile v1 (no `packages` key) is out of scope; the parser returns `[]` and
  logs a warning, as today.

### yarn — `yarn.lock` (v1)

- `key` = the block's first descriptor (e.g. `lodash@^4.17.0`).
- Build a descriptor map: every comma-separated descriptor of a block points to
  that block's key.
- Edges: each `dependencies` / `optionalDependencies` entry `name "range"` is
  resolved via the descriptor `name@range`. `constraint` is the range.
  The block parser must keep the range, which it currently discards.
- `is_direct` / `is_dev`: from `package.json` when present (a name listed there
  maps to the block matching `name@<declared range>`); otherwise the fallback.
- Yarn berry (`__metadata`, `resolution:`) is out of scope.

### composer — `composer.lock`

- `key` = the package name.
- Edges: `require` entries resolved by name. Platform requirements (`php`,
  `php-64bit`, `hhvm`, `ext-*`, `lib-*`, `composer`, `composer-plugin-api`,
  `composer-runtime-api`) are skipped. `constraint` is the declared range.
  `packages-dev` entries also resolve `require` (not `require-dev`, which only
  applies to the root).
- `is_direct`: listed in `composer.json` `require` or `require-dev` (companion
  file found via the existing `findCompanionFile()`); otherwise the fallback.
- `is_dev`: whether it comes from `packages-dev`, as today.

---

## Sync job

`ProcessLockFilesJob::syncPackages()` inside the existing transaction:

1. Delete the environment's `EnvironmentPackage` rows (edges cascade).
2. Resolve `Package` models keyed by **`type|name`** instead of `name`, so a
   composer and an npm package with the same name can never be merged.
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

---

## Query API

`Statikbe\FilamentVoight\Services\DependencyGraphService`, shared by Filament and
the MCP server (spec 13).

```php
/**
 * Every path from a direct dependency down to the node, shortest first.
 *
 * @return Collection<int, Collection<int, EnvironmentPackage>>  each path ordered root → node
 */
public function pathsToRoots(EnvironmentPackage $node, int $maxPaths = 10, int $maxDepth = 15): Collection;

/**
 * The distinct direct dependencies whose subtree contains the node: what you would bump.
 *
 * @return Collection<int, EnvironmentPackage>
 */
public function introducingDirectDependencies(EnvironmentPackage $node): Collection;
```

- Both use `ancestors()` constrained by `maxDepth`, with the `path` column to
  rebuild paths. A direct node returns a single path containing only itself.
- Results are capped to keep responses bounded; callers can see that a cap was
  hit through the returned count being equal to `$maxPaths`.

Querying across environments ("which projects pull in lodash < 4.17.21") needs
only `EnvironmentPackage` + `Package` and a version comparison; it belongs to the
MCP spec and is not part of this service.

---

## Filament

Minimal. The expandable tree promised in spec 08 is out of scope.

`PackageResource` → `InstallationsRelationManager`:

- The **Parent Package** column becomes **Required by**: the names of the node's
  `parents()`, eager loaded. Add a `required_by` translation key next to
  `models.package.view.columns.parent_package` and remove both now-unused
  `parent_package` keys.
- A **Why installed?** row action opens a modal listing `pathsToRoots()` as
  `a → b → c`. The modal content is a Blade view, not HTML built in PHP. All
  strings go through `voightTrans()`.

---

## Uploaders

`script.sh` is not in this repository; its change ships to projects separately.

- Also send `composer.json` and `package.json` when present next to the
  lockfiles, in the same `lockfiles[]` multipart field. The API and
  `SyncLockFileRequest` do not change.
- The artisan uploader `voight:sync-lockfile` filters on
  `FilamentVoightConfig::getAllowedLockfileNames()`, whose default includes
  `package.json` but not `composer.json`. Add `composer.json` to the default
  (and to the published config's `lockfiles.allowed_names`, if present).
- `RunOsvScanJob::resolveLockfileContents()` already allowlists lockfile names,
  so manifests are never forwarded to the Lambda.
- Until a project's uploader is updated, the direct-dependency fallback applies.

**No Lambda change is needed.** `/locks` already sees nested versions; `/packages`
receives a longer `(ecosystem, name, version)` list with the same shape.

---

## Rollout impact

After the first re-sync of an npm environment, **findings for nested versions
appear** that were silently dropped before. Finding counts, the most-vulnerable
widgets and alerts may jump. This is the intended fix, and the changelog entry
must say so.

---

## Testing

Fixtures under `tests/Fixtures/lockfiles/` taken from real projects and trimmed:

- npm v3 with a nested duplicate version, a scoped package, a `link: true`
  workspace entry, a dev-only subtree, and a cycle.
- npm v2 (legacy `dependencies` key present) to prove it is ignored.
- yarn v1 with and without `package.json`, including one name at two versions.
- composer with and without `composer.json`, including platform requirements.

Tests:

- **Parsers:** keys, edge resolution (including Node's walk-up rule and yarn
  descriptors), constraints, `is_direct` / `is_dev` with and without manifests,
  platform requirements skipped, unresolvable requirements dropped.
- **`ProcessLockFilesJob`:** nodes and edges persisted; a composer and npm
  lockfile in one sync do not cross-link; re-sync replaces edges (cascade); same
  name at two versions yields two rows; `Package` keyed by `type|name`.
- **`DependencyGraphService`:** paths ordered root → node and shortest first;
  multiple roots; direct node; depth and path caps; a cycle terminates.
- **Regression:** a nested vulnerable npm version in a `/locks` response
  produces an `AuditFinding` (HTTP faked, no live Lambda).
- **Filament:** Required by column and Why installed? modal render.
- **Migrations:** test migrations mirror the new stubs.

---

## Out of scope

- Yarn berry, pnpm and bun parsing (the uploader already sends those files; no
  parser exists yet).
- npm lockfile v1.
- `RunOsvScanJob::resolveLockfileContents()` keys files by basename, so two
  `package-lock.json` files from different directories collide. Separate fix.
- The full expandable dependency tree UI (spec 08).
