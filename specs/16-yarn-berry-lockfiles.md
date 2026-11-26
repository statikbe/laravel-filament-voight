# Yarn Berry Lockfiles

**Date:** 2026-11-25
**Status:** implemented
**Builds on:** `12-dependency-graph.md` (parser contract, dev propagation),
`14-environment-health.md` (sync warnings)

Parse Yarn Berry (v2+) `yarn.lock` files into installed packages and edges, the
same way Yarn v1 is parsed today, so Berry projects get findings, a dependency
graph and nightly scans.

---

## Problem

The OSV scanner lambda reads Berry lockfiles, but Voight drops what it finds.

1. **Berry is rejected at parse time.** `YarnLockParser::parse()` throws
   `UnsupportedLockfileException(SyncWarning::YarnBerryUnsupported)` when the
   content has `__metadata:`. `ProcessLockFilesJob` records the warning and
   skips the file, so the environment stores no installed packages for it.
2. **Post-sync `/locks` findings are discarded.** `RunOsvScanJob` sends the raw
   `yarn.lock` to the lambda, which does find vulnerabilities in it. But
   `RecordEnvironmentAuditRunsService::record()` attaches findings only to
   installed packages it has stored (`type|name|version`). With none stored, every
   finding is thrown away.
3. **The nightly sweep never asks.** `RunNightlyOsvScanJob::scanDeduplicated()`
   builds its query from stored installed packages, so it sends nothing for a
   Berry environment. The run completes clean.

The health warning from spec 14 makes this visible, but Yarn 3 and 4 are now
the common case for Yarn projects, so the warning shows up on most of them.

---

## Berry lockfile format

A YAML subset written by Yarn (`syml`): two-space indentation, quoted or plain
keys, `key: value` pairs. Excerpt (Yarn 4, `__metadata.version: 8`):

```yaml
__metadata:
  version: 8
  cacheKey: 10c0

"chalk@npm:^4.1.0, chalk@npm:^4.1.2":
  version: 4.1.2
  resolution: "chalk@npm:4.1.2"
  dependencies:
    ansi-styles: "npm:^4.1.0"
    supports-color: "npm:^7.1.0"
  checksum: 10c0/…
  languageName: node
  linkType: hard

"my-app@workspace:.":
  version: 0.0.0-use.local
  resolution: "my-app@workspace:."
  dependencies:
    chalk: "npm:^4.1.2"
    typescript: "npm:^5.4.0"
  languageName: unknown
  linkType: soft
```

Differences from v1 that matter here:

- Block headers are comma-separated **descriptors** with a protocol
  (`name@npm:^4.1.0`). `resolution` names the one installed **locator**
  (`name@npm:4.1.2`).
- **Workspaces are blocks.** Every workspace, including the root
  (`@workspace:.`), has its own block with its declared dependencies. Dev
  dependencies are merged into `dependencies`; the lockfile does not say which
  are dev.
- **Optional dependencies** sit in `dependencies` and are flagged by
  `dependenciesMeta.<name>.optional: true`.
- **Peer dependencies** are listed (`peerDependencies`) but are resolved per
  consumer through virtual packages that are not in the lockfile.
- **Patches** (`patch:` protocol, e.g. `typescript@patch:typescript@npm%3A^5.4.0#optional!builtin<compat/typescript>`)
  get their own block next to the original `npm:` block they wrap.
- **Range formatting differs by version.** Yarn 4 writes dependency ranges with
  the protocol (`"npm:^4.1.0"`); Yarn 2/3 (`__metadata.version` ≤ 6) write them
  without it (`^4.1.0`), while the block headers still carry `npm:`.
- **Soft links** (`linkType: soft`: `workspace:`, `portal:`, `link:`) are local
  directories, not installed packages.

---

## Design

### Parser selection

`ProcessLockFilesJob::parseYarnLock()` picks the parser:

- `YarnBerryLockParser::detects($content)` (a `^__metadata:` line, the check
  `YarnLockParser` uses today) → `YarnBerryLockParser`.
- Otherwise → `YarnLockParser` (v1), with the existing workspaces warning.

`YarnLockParser` drops its Berry check and `@throws`. The workspaces warning
(`YarnWorkspacesUnsupported`) is only added on the v1 path: Berry lockfiles
contain their workspaces, so direct dependencies are correct (see
[`is_direct`](#is_direct)).

### Reading the file

`Symfony\Component\Yaml\Yaml::parse()`. Berry's `syml` output is valid YAML, so
a real YAML parser avoids hand-parsing quoting, escaping and nested maps.

**New dependency:** `symfony/yaml` moves from a transitive dev dependency to
`require` in `composer.json`, with `^6.4 || ^7.0 || ^8.0` to match
`symfony/css-selector`.

The parser reads, per top-level entry: the key (comma-separated descriptors),
`version`, `resolution`, `linkType`, `dependencies` (name → range) and the names
with `optional: true` in `dependenciesMeta`. Everything else is ignored. The
`__metadata` entry is skipped.

**Scalar typing.** YAML gives unquoted numbers a numeric type. Yarn writes
semver versions unquoted (`version: 4.1.2`). Those have two dots and stay
strings, but a non-semver `version` or a Yarn 2/3 range such as `2` or `1.10`
would become an int or float. Rules:

- `version`: used when it is a string. Otherwise the version is taken from the
  `resolution` locator (the part after the protocol, e.g. `npm:1.10` → `1.10`).
- Dependency ranges: cast to string. A float range loses its formatting
  (`1.10` → `1.1`), so its descriptor may not resolve and the edge is dropped
  like any unresolved requirement. Yarn 4 quotes every range (`"npm:^1.10"`),
  so only rare Yarn 2/3 lockfiles are affected.

**Unparseable files.** A `ParseException`, or a result that is not an array, is
caught by the parser and rethrown as
`UnsupportedLockfileException(SyncWarning::LockfileUnreadable)`. The job skips
that file with a warning and the rest of the sync still completes. Letting the
exception through would fail the whole sync, including a `composer.lock` next to
it.

### Nodes

One node per block with `linkType: hard` whose resolution is not `patch:`.

- `key` = the `resolution` locator (unique per installed package, unlike the
  v1 key, which is the first descriptor).
- `name` = the package name in the **resolution**, not the descriptor, so an
  alias (`string-width-cjs@npm:string-width@^4.2.0` resolving to
  `string-width@npm:4.2.3`) is stored and scanned as `string-width`. The name
  is everything before the `@` that follows the optional `@scope/` prefix.
- `version` = the `version` field.
- `type` = `PackageType::Npm`.

Not nodes:

- **Soft links** (`workspace:`, `portal:`, `link:`). Workspace blocks are
  still read as [roots](#is_direct).
- **Patch blocks.** The node is the original `npm:` block they wrap: same name
  and version, which is what OSV matches on. Edges to a patch descriptor are
  redirected (see below).

Non-registry hard packages (`git`, `github:`, `https:` tarballs, `file:`
archives) are nodes with their `version` field, as in v1.

### Edges

Build a descriptor map: every descriptor of a block points to that block's
`resolution`. For each entry in a node's (or workspace's) `dependencies`:

1. **Normalise the range.** If it does not start with a protocol
   (`/^[a-z][a-z0-9+.-]*:/i`), prefix `npm:`. This covers Yarn 2/3 lockfiles.
2. **Look up** `name@range` in the descriptor map.
3. **Redirect patches.** If the match is a patch block, decode the descriptor it
   wraps (`patch:typescript@npm%3A^5.4.0#…` → `typescript@npm:^5.4.0`) and look
   that up instead. Patches are only redirected one level; a patch of a patch
   resolves to nothing and is dropped.
4. Unresolved entries are dropped, as the `LockfileParser` contract requires.

`constraint` is the range as written in the lockfile (before normalisation).
`kind` is `DependencyKind::Optional` when the name is marked optional in
`dependenciesMeta`, otherwise `DependencyKind::Dependency`. No peer edges: the
lockfile does not record which installed version satisfies a peer, the same
reason v1 has none.

### `is_direct`

A node is direct when a **workspace block** (any `@workspace:` resolution)
resolves one of its dependencies to it. This handles monorepos correctly
without the members' `package.json`, which v1 cannot do.

A lockfile without workspace blocks (should not happen with Yarn, but test
fixtures and hand-edited files) falls back to `markUnrequiredPackagesDirect()`.

### `is_dev`

The lockfile merges dev dependencies into each workspace's `dependencies`, so
the dev flag comes from manifests:

- **Root workspace** (`@workspace:.`): with the companion `package.json`, a
  name listed only in `devDependencies` makes its resolved node a dev root.
  Without it, every root is production.
- **Other workspaces:** their `package.json` files are not uploaded, so their
  dependencies count as production roots. This may mark some dev tooling as
  production. That is the safe side: a production vulnerability is never hidden
  as dev.

Then `propagateDevFlag()` from spec 12: a node is dev when it cannot be reached
from any production root.

### Sync warnings

- **New `SyncWarning::LockfileUnreadable`** (`lockfile_unreadable`, context
  `file`): a lockfile the parser recognises but cannot read. Translation label
  "Unreadable lockfile", message ":file could not be read; its packages were not
  imported." Added to the warning table in spec 14.
- **`SyncWarning::YarnBerryUnsupported`** is no longer produced. The case and
  its translation stay, because Voight is a package and installs that don't run
  the reprocess step below still have it stored on their latest sync.
  `EnvironmentHealthService` reads warnings with `tryFrom()`. The docblock says
  the case only appears on syncs processed before this change.

### Existing environments

No re-sync needed. Uploaded lockfiles stay on the lockfiles disk and
`DependencySync.lockfile_paths` points to them. After upgrading, run:

```bash
php artisan voight:reprocess-lockfiles
```

This re-runs `ProcessLockFilesJob` on every environment's latest completed sync.
The job stores the Berry packages and edges, overwrites the sync's `warnings`
(which removes `YarnBerryUnsupported`) and starts the post-sync scan, which
then records the Berry findings. `--project` limits it to one project. Without
`--fresh`, existing audit runs are kept.

Add this upgrade step to `CHANGELOG.md`.

---

## Out of scope

- **pnpm lockfiles.** Still `SyncWarning::UnsupportedLockfile`. (Bun is parsed since
  `18-bun-lockfiles.md`.)
- **Peer dependency edges** (see [Edges](#edges)).
- **Dev flags for non-root workspaces.** Would need the uploader to send every
  member's `package.json`.
- **Git and tarball versions in the nightly sweep.** A git dependency's
  `version` field is the `package.json` version, not a registry release, so the
  `/packages` sweep can match the wrong advisories. This already applies to
  v1; `RunNightlyOsvScanJob::partitionEnvironments()` only routes `dev-` style
  versions to `/locks`.
- **`RunOsvScanJob::resolveLockfileContents()` keying by basename** (spec 12,
  out of scope). Unchanged.

---

## Testing

Fixtures go in `tests/Fixtures/lockfiles/yarn-berry/`, written in the exact
format Yarn 4 and Yarn 3 produce (checksums are not checked by the parser):

- `v8/yarn.lock` + `v8/package.json` (Yarn 4): a root workspace and one member
  workspace, a dev-only dependency with a transitive dependency, a shared
  transitive package reached from both prod and dev, an `npm:` alias, an
  optional dependency (`dependenciesMeta`), a `patch:` package (e.g.
  `typescript`) and a `portal:` or `link:` dependency.
- `v6/yarn.lock` (Yarn 3): unprefixed dependency ranges.

`YarnBerryLockParserTest`:

- `detects()` is true for Berry content and false for v1.
- Nodes: keys are resolutions; aliases use the real name; soft links, patch
  blocks and `__metadata` are not nodes; `version` comes from the block.
- Edges: resolved through descriptors; Yarn 2/3 unprefixed ranges resolve; a
  patch descriptor resolves to the original block; `constraint` keeps the
  written range; optional `kind` from `dependenciesMeta`; unresolved entries
  are dropped; no peer edges.
- `is_direct`: dependencies of the root and of a member workspace are direct,
  transitive ones are not; no workspace blocks → fallback.
- `is_dev`: with `package.json`, a root dev dependency and its exclusive
  transitive dependencies are dev, a package shared with prod is not; without
  `package.json` everything is production; a member workspace's dependencies
  are production.
- CRLF content parses the same as LF.
- A non-string `version` falls back to the resolution's version.
- Invalid YAML throws `UnsupportedLockfileException` with
  `SyncWarning::LockfileUnreadable`.

`YarnLockParserTest`: delete "rejects a yarn berry lockfile as unsupported"
(detection moves to the job); v1 tests unchanged.

`ProcessLockFilesJobTest`:

- Replace "warns about a yarn berry lockfile": a Berry `yarn.lock` stores its
  packages and edges and adds no warning.
- A Berry project whose `package.json` has `workspaces` gets no
  `YarnWorkspacesUnsupported` warning; a v1 one still does.
- An unreadable Berry `yarn.lock` adds `LockfileUnreadable`; a `composer.lock`
  in the same sync is still imported.

`ReprocessLockfilesCommandTest`: reprocessing a sync whose stored `yarn.lock`
is Berry replaces its `YarnBerryUnsupported` warning with imported packages.

`RecordEnvironmentAuditRunsServiceTest`: a `/locks` result for a Berry environment records findings against its
installed packages.
