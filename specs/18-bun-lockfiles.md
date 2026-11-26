# Bun Lockfiles

**Date:** 2026-11-26
**Status:** implemented
**Builds on:** `12-dependency-graph.md` (parser contract, dev propagation),
`14-environment-health.md` (sync warnings), `16-yarn-berry-lockfiles.md`

Parse Bun's text lockfile (`bun.lock`, Bun 1.2+) into installed packages and
edges, so Bun projects get findings, a dependency graph and nightly scans.

---

## Problem

`voight.sh` already uploads `bun.lock`, but nothing reads it:

1. `ProcessLockFilesJob` has no `bun.lock` arm, so the file gets
   `SyncWarning::UnsupportedLockfile` ("bun.lock is not supported yet; its
   packages were not imported.") and no installed packages are stored.
2. `RunOsvScanJob::resolveLockfileContents()` leaves `bun.lock` out of
   `$scannable`, so the post-sync `/locks` scan never sees it.
3. The nightly sweep builds its query from stored packages, so it sends nothing.
4. `bun.lock` is missing from `lockfiles.allowed_names` (config and the
   `FilamentVoightConfig` default).

---

## Format

JSONC: JSON with trailing commas, no comments. Excerpt (Bun 1.3.11,
`lockfileVersion: 1`):

```jsonc
{
  "lockfileVersion": 1,
  "workspaces": {
    "": {
      "name": "bun-app",
      "dependencies": { "chalk": "^4.1.2", "lib": "workspace:*",
                        "string-width-cjs": "npm:string-width@^4.2.0", },
      "devDependencies": { "debug": "^4.3.4", },
      "optionalDependencies": { "is-number": "^7.0.0", },
    },
    "packages/lib": {
      "name": "lib",
      "dependencies": { "chalk": "^5.3.0", },
      "devDependencies": { "is-odd": "^3.0.1", },
    },
  },
  "packages": {
    "chalk": ["chalk@4.1.2", "", { "dependencies": { "ansi-styles": "^4.1.0" } }, "sha512-…"],
    "string-width-cjs": ["string-width@4.2.3", "", { "dependencies": { … } }, "sha512-…"],
    "lib": ["lib@workspace:packages/lib"],
    "is-even": ["is-even@github:jonschlinkert/is-even#585f800", { "dependencies": { … } }, "…", "sha512-…"],
    "debug/ms": ["ms@2.1.3", "", {}, "sha512-…"],
    "lib/chalk": ["chalk@5.6.2", "", {}, "sha512-…"],
  }
}
```

- **`packages` keys are install paths.** `debug/ms` is the `ms` nested under
  `debug`; `lib/chalk` is the `chalk` nested under workspace `lib`. A scoped
  name takes two segments (`@babel/core/semver`).
- **The first array element is the resolved locator** `name@reference`. Its
  name is the real package name, so the alias `string-width-cjs` installs
  `string-width`.
- **The metadata object** (`dependencies`, `optionalDependencies`,
  `peerDependencies`, `os`, `bin`, …) is the first object in the array: index 2
  for registry packages, index 1 for git/github ones.
- **Workspaces** declare `dependencies`, `devDependencies` and
  `optionalDependencies` separately, so direct and dev flags need no
  `package.json`.

The binary `bun.lockb` (Bun < 1.2) is out of scope; `voight.sh` does not upload it.

---

## Design

### Reading the file

`BunLockParser implements LockfileParser` (uses `DerivesFlagsFromGraph`).

1. Remove trailing commas with a string-aware regex (match a JSON string or a
   `,` followed by whitespace and `}`/`]`; keep strings, drop commas).
2. `json_decode(..., true)`. Invalid JSON, a non-array result or a missing/non-array
   `packages` → `UnsupportedLockfileException(SyncWarning::LockfileUnreadable)`.

`$manifestContent` is ignored.

### Nodes

One node per `packages` entry whose locator reference is not `workspace:`,
`link:` or `file:` (local directories, not installed packages).

- `key` = the `packages` key (the install path).
- `name` = locator name (everything before the `@` that follows an optional
  `@scope/` prefix).
- `version` = locator reference with a leading `npm:` removed. Registry packages
  give plain semver; git/github ones keep their reference
  (`github:jonschlinkert/is-even#585f800`), as there is no other version.
- `type` = `PackageType::Npm`.

### Edges

For each name in a node's `dependencies` (`Dependency`), `optionalDependencies`
(`Optional`) and `peerDependencies` (`Peer`), resolve it the way Node's
`node_modules` lookup does, starting from the requester's key:

`a/b` requiring `c` → try `a/b/c`, then `a/c`, then `c`.

The first key that exists and is a node wins. Unresolved names are dropped
(uninstalled optional peers, workspace links). `constraint` is the range as
written. When a name appears in more than one map, the first edge is kept
(dependencies → optional → peer).

### `is_direct` / `is_dev`

Every workspace entry is a root. Its requester key is `''` for the root
workspace (`""`) and its `name` for the others (that is the prefix Bun uses,
e.g. `lib/chalk`). Its `dependencies`, `optionalDependencies` and
`devDependencies` resolve with the same lookup.

- A resolved node is **direct**.
- It is **dev** when every workspace that reaches it lists it only under
  `devDependencies`. A package listed as production by any workspace stays
  production.

Then `propagateDevFlag()` sets `is_dev` on transitive packages. Finally, a
direct dev package that is reachable from a production direct package becomes
production (e.g. root dev `supports-color`, also required by production
`chalk`): a production package is never hidden as dev. Without a
`workspaces` block (hand-written files), fall back to
`markUnrequiredPackagesDirect()`.

### Wiring

- `ProcessLockFilesJob`: `'bun.lock' => (new BunLockParser)->parse($content)`.
- `RunOsvScanJob`: add `bun.lock` to `$scannable`. **Requires the lambda
  release from `voight-osv-scanner-lambda`
  `docs/superpowers/specs/2026-11-26-bun-lockfiles-design.md`.** It accepts the
  name and strips git entries, whose short commits make the OSV API fail the
  scan. An older lambda rejects the whole `/locks` input, so deploy the lambda
  first.
- `RunNightlyOsvScanJob::partitionEnvironments()`: an environment with an
  installed version containing `:` (bun git/github locators such as
  `github:owner/repo#sha`; registry versions never contain one) is scanned via
  `/locks`, like `dev-` versions, so they never reach `/packages`.
- `lockfiles.allowed_names`: add `bun.lock` in `config/filament-voight.php`
  and `FilamentVoightConfig::getAllowedLockfileNames()`.
- `specs/16-yarn-berry-lockfiles.md` out-of-scope line: bun is now parsed.

### Existing environments

Run `php artisan voight:reprocess-lockfiles` after upgrading. Stored `bun.lock`
files are parsed, and the `UnsupportedLockfile` warning is replaced. Add this to `CHANGELOG.md`.

---

## Out of scope

- `bun.lockb` (binary) and pnpm.
- Which version satisfies a peer: like npm, the nearest installed copy is used.
- Vulnerabilities in git dependencies: the lambda skips them (Bun records only
  a short commit). They are still stored and shown.

---

## Testing

Fixtures in `tests/Fixtures/lockfiles/bun/`, generated with Bun 1.3:
`bun.lock` with a root and a member workspace, a root dev dependency with a
nested transitive dependency (`debug/ms`), a package shared by prod and dev, an
alias, a root optional dependency, a package with `optionalDependencies` and
`peerDependencies`, a github dependency, a workspace-nested package
(`lib/chalk`), and a scoped package.

`BunLockParserTest`:

- Nodes: keys are install paths; aliases use the real name; workspace/link/file
  entries are not nodes; github version keeps its reference.
- Edges: nested lookup prefers the nearest copy (`debug` → `debug/ms`, root `ms`
  for others); workspace-nested lookup (`lib` → `lib/chalk`); scoped keys; kinds
  (dependency/optional/peer); unresolved names dropped.
- `is_direct`/`is_dev`: root deps, root dev, member deps and member dev;
  a package that is prod in one workspace and dev in another is prod;
  transitive dev propagation; fallback without `workspaces`.
- Trailing commas inside strings are kept; invalid content throws
  `LockfileUnreadable`.

`ProcessLockFilesJobTest`: a `bun.lock` stores packages and edges with no
warning; an unreadable one adds `LockfileUnreadable` and the rest of the sync is
still imported.

`RunOsvScanJobTest` (or equivalent): `bun.lock` is sent to `/locks`.

`RunNightlyOsvScanJobTest`: an environment with a `github:…#sha` version is
scanned via `/locks`, not `/packages`.

`ReprocessLockfilesCommandTest`: reprocessing a stored `bun.lock` replaces the
`UnsupportedLockfile` warning with imported packages.
