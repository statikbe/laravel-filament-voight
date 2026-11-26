# Sortable Version Keys

**Date:** 2026-11-26
**Status:** implemented
**Builds on:** `12-dependency-graph.md` (installed packages), `13-mcp-server.md`
(package usage tools), `15-environment-system-details.md` (PHP version)

Store a byte-sortable key next to every version, so version filters and version
sorting run in SQL instead of on PHP collections.

---

## Why

Version filters load every candidate row and match it in PHP with
`VersionConstraint::matches()` (`version_compare`). On production
(68,034 installed rows) that is:

- **Slow.** The query loads the rows of every installation of a package before it filters, and does it again for counts.
- **Inaccurate in counts and paging.** `PackageUsageService::find()` filters,
  groups and paginates in memory. Filament filters push a `whereIn(id, [...])`
  of PHP-matched ids back into the query.
- **Rough in sorting.** `ProjectStackService` sorts versions with
  `REPLACE(version, 'v', '') + 0`, which only orders by major.minor.

**Goal:** a version range is one indexed SQL condition. Counts, pagination
and sorting come from the database and are exact.

**Non-goals:** npm/Composer range syntax (`^1.2`, `~1.2`, `1.x`, `||`). The
existing filter inputs stay: comparisons like `>=7.0 <7.4` (MCP) and a
min/max pair (Filament).

**Anti-goals:** a non-comparable version (`dev-main`) silently matching or
sorting as `0`; any result that differs between MySQL and SQLite.

---

## Current state

`VersionConstraint::matches()` filters in PHP at:

| Where | What |
|---|---|
| `InstalledVersionRangeOperator::matchedInstallationIds()` | Installations relation manager on a package (filter "installed version in range") |
| `ProjectPackageOperator::apply()` (via `PackageUsageService::projectIdsWithPackage()`) | Project list filter: projects with package X in range |
| `ProjectPackageOperator::projectIdsWithCurrentFindings()` | Same filter with severities: matches `audit_findings.installed_version` |
| `PackageUsageService::find()` | MCP `find_package_usage`: filter, group, paginate in memory |
| `PackageUsageService::projectIdsWithPackage()` | Also used by MCP `list_projects` (`ProjectQueryService`, `package_version`) |

Version sorting/filtering in SQL today:

| Where | What |
|---|---|
| `ProjectStackService::orderByVersion()` | Project table sort by Laravel/Filament/… version (`REPLACE(...)+0`) |
| `ProjectStackService::orderByPhpVersion()` | Project table sort by PHP version (`+0`) |
| `ProjectStackService::constrainToPhpVersions()` | PHP minor filter (`= 8.3 OR LIKE '8.3.%'`) |
| `EnvironmentTableSchema` | Environment table sort by `systemDetails.php_version` (string order) |

### Production data (2026-11-26)

`voight_environment_packages.version`, 68,034 rows:

| Shape | Rows |
|---|---|
| plain `1.2.3` (1–3 parts) | 67,685 |
| pre-release `1.2.3-…` | 201 |
| four-part `1.2.3.4` | 68 |
| `dev-*` branches | 50 |
| `X.x-dev` / `X.Y.x-dev` branches | 28 |
| git / URL (`:`) | 0 |

Longest numeric part: 8 digits (`caniuse-lite` `1.0.30001826`).

---

## Approaches

1. **Numeric columns** (`major`, `minor`, `patch`, `part4`, plus pre-release
   columns). Readable, but each range bound becomes a nested OR over the
   columns, the index helps less, and semver pre-release order
   (`beta.2 < beta.10 < rc`) needs several more columns.
2. **One sortable key per version (chosen).** A string whose byte order is
   version order. A range is `key >= :lo AND key < :hi`, sorting is `ORDER BY
   key`, and one composite index serves both. One PHP encoder holds all
   version rules. Opaque in the database; parts are limited to 10 digits.
3. **SQL pre-filter + PHP finish** (e.g. match the major in SQL). Rejected:
   keeps the count and pagination problems.

---

## Design

### The key: `Support\VersionKey`

`VersionKey::for(string $version): ?string` returns the key, or `null` when the
version cannot be compared. Rules, in order:

1. **Normalise.** Trim, lowercase, drop one leading `v`, drop build metadata
   (`+` and everything after it).
2. **Branch versions.** `dev-…` → `null`. `X.x-dev`, `X.Y.x-dev`,
   `X.Y.Z.x-dev` (Composer branch aliases) → the given parts, every part from
   the `x` onward set to `9999999999`, pre-release `dev`. So `7.4.x-dev` sorts
   as the newest 7.4 dev build: inside `>=7.4 <7.5`, outside `>=7.5`.
3. **Core.** 1 to 4 dot-separated numeric parts; missing parts are `0`
   (`1.2` = `1.2.0` = `1.2.0.0`). Each part is zero-padded to **10 digits**.
   More than 4 parts, a part over 10 digits, or a non-numeric part → `null`.
   Parts are joined with `.`. The core is always 43 characters.
4. **Suffix.** What follows the core, after an optional `-` (Composer also
   writes `1.0.0beta2`):
   - nothing → marker `~` (release);
   - Composer patch suffixes `p`, `pl`, `patch` + optional number → marker `~`
     then the encoded identifiers (they sort **after** the release, as in Composer);
   - anything else → marker `-` then the encoded pre-release identifiers.
5. **Pre-release identifiers.** Split on `.`. Each one is:
   - all digits → `0` + the number zero-padded to 10 digits (over 10 → `null`);
   - otherwise → `1` + the identifier (only `[a-z0-9-]` allowed, else `null`).
     An identifier that is letters followed by digits (Composer's `rc10`,
     `beta2`) is split into the letters and the number first, so `RC2 < RC10`
     as in Composer and `version_compare`. `rc10` and `rc.10` get the same key.
   Identifiers are joined with `!`, which sorts below every identifier
   character, so `beta.1` < `beta-x` keeps semver order.
6. Total length over 191 → `null`.

Byte order: `!` (33) < `-` (45) < `.` (46) < digits < letters < `~` (126).

Resulting order (semver precedence, with Composer's patch rule):

```
1.0.0-alpha < 1.0.0-alpha.1 < 1.0.0-beta < 1.0.0-beta.2 < 1.0.0-beta.10
  < 1.0.0-rc.1 < 1.0.0 = v1.0 < 1.0.0-p1 < 1.0.0.1 < 1.0.1 < 1.0.x-dev < 1.1.0
```

Known difference: a plain `1.0.0-dev` sorts after `1.0.0-beta` (alphabetical);
Composer ranks `dev` below `alpha`. Not seen in production data.

Bun versions (`18-bun-lockfiles.md`): git locators (`github:…#sha`) →
`null`. Registry tarball URLs whose file is `<name>-<version>.tgz` → the key of
`<version>`; any other URL → `null`. This logic lives in `VersionKey`, not the
parser: `version` keeps the stored string.

### Columns

| Table | Source | New column |
|---|---|---|
| `voight_environment_packages` | `version` | `version_sort` + index `(package_id, version_sort)` |
| `voight_audit_findings` | `installed_version` | `installed_version_sort` + index `(package_id, installed_version_sort)` |
| `voight_environment_system_details` | `php_version` | `php_version_sort` + index |

All `VARCHAR(191)`, nullable. The column must compare bytes:

- MySQL (production): `->charset('ascii')->collation('ascii_bin')`.
- Postgres: `->collation('C')` (the default `en_US.UTF-8` collation does not
  order `!`, `-`, `.`, `~` by byte). Not covered by a test run.
- SQLite (tests): `BINARY` by default, nothing to set.

For **`php_version`** the encoder drops the distro suffix: everything from the
first `-` on (`8.3.12-1+ubuntu22.04.1` → `8.3.12`), so distro builds are not
treated as pre-releases.

### Keeping keys current

- **Eloquent writes** (`firstOrCreate`, `updateOrCreate`, factories): the
  models set the key in a `saving` hook from the source column
  (`EnvironmentPackage`, `AuditFinding`, `EnvironmentSystemDetail`). Model
  classes are configurable (`FilamentVoight::config()->get…Model()`), so the
  hook lives in a trait the default models use.
- **Bulk inserts** bypass models: `ProcessLockFilesJob::syncPackages()` adds
  `'version_sort' => VersionKey::for($parsed['version'])` to each row.
- **Custom models:** host apps that configure their own model classes must use
  the trait; README says so.
- **Backfill and rebuild: `voight:rebuild-version-keys`.** Recomputes every key
  in the three tables in chunks (`chunkById`, 1,000) through `VersionKey`, as
  plain query updates (no model events). Each migration adds its column and index, then
  calls the command's service, so a fresh install and an upgrade both end up
  filled. Run it again after a release changes the encoder, which the CHANGELOG
  then says. Migrations skip when the column exists, like the other `add_*`
  stubs. About 68k rows plus findings on production, well inside a deploy.

### SQL filtering: `VersionConstraint`

`VersionConstraint` keeps `parse()` and `between()` but compares keys:

- `apply(Builder $query, string $column): Builder` adds one `where($column,
  $operator, VersionKey::for($bound))` per comparison (`=` for equality). A
  bound that has no key (e.g. `dev-main`) is rejected: by `parse()` with the
  existing invalid-filter error (MCP), and by form validation on the Filament
  min/max inputs (`between()` is only reached with valid bounds).
- `matches()` is removed once no caller is left.
- **Inverse** (Filament "not in range"): rows **not** matching, **including**
  rows with a `null` key. Today's inverse also returns them.

Call sites:

| Where | Change |
|---|---|
| `InstalledVersionRangeOperator::apply()` | `$constraint->apply(...)` on `version_sort` (or `whereNot` + `orWhereNull` for inverse); drop `matchedInstallationIds()` |
| `PackageUsageService::projectIdsWithPackage()` | returns a subquery builder of `project_id` with the constraint applied in SQL; callers use `whereIn(column, $subquery)` |
| `ProjectPackageOperator` (findings path) | constraint on `installed_version_sort` in the findings query; select `project_id` as a subquery |
| `PackageUsageService::find()` | constraint in SQL; grouping (one row per project, environment, type, version, with `MAX(is_direct)`, `MIN(is_dev)`), the `include_dev` filter, `total` and `offset`/`limit` move to SQL |
| `ProjectQueryService` (`package_version`) | follows `projectIdsWithPackage()` |

### SQL sorting

| Where | Change |
|---|---|
| `ProjectStackService::orderByVersion()` | subquery selects `version_sort` instead of `REPLACE(version,'v','')+0` |
| `ProjectStackService::orderByPhpVersion()` | `php_version_sort` |
| `ProjectStackService::constrainToPhpVersions()` | `8.3` → `php_version_sort >= key('8.3') AND < key('8.4')` |
| `EnvironmentTableSchema` PHP version sort | `php_version_sort` |

`null` keys sort last in both directions (`ORDER BY key IS NULL, key`) so
`dev-main` never sits above `13.0.0`.

`constrainToUpgradeStatuses()` and `orderByUpgradeStatus()` keep the
`REPLACE(...)+0` major cast: upgrade status is a major-only comparison and must
agree with `packagesBehind()`, which stays in PHP. Update the class docblock to
say only those two use the cast.

### Behaviour changes (CHANGELOG)

- `1.0` equals `1.0.0` (was: lower, because of `version_compare`).
- `7.4.x-dev` falls inside `>=7.4 <7.5`. `dev-main` never matches a range and
  sorts last.
- Pre-release order follows semver (`beta.2 < beta.10`); `version_compare`
  was mostly the same.
- Version filters on bounds that are not versions (e.g. `dev-main`) are
  rejected instead of matching unpredictably.
- Publish and run the three new migrations (they fill the keys).
- Custom model classes must use the new trait.
- New `voight:rebuild-version-keys` command.

---

## Out of scope

- npm/Composer range syntax (`^`, `~`, `x` ranges, `||`).
- `voight_packages.latest_version` and `vulnerable_package_ranges.fixed_version`
  (no filter or sort reads them).
- `ProjectStackService::packagesBehind()` (major regex in PHP, per project, not a query problem).

## Left to the builder

- Names of the trait and migration stubs (follow `add_*_to_voight_*_table`).
- Whether the backfill shares code with the `saving` hook.
- Exact SQL for grouping in `find()`, as long as `total` and paging come from SQL.

---

## Testing

`VersionKeyTest` (unit, the order is the spec):

- The ordered list above, asserted as `sort()`-ed keys equal the list order,
  plus: `1.0` = `1.0.0` = `v1.0.0.0`; build metadata ignored; `1.0.0beta2`
  = `1.0.0-beta2`; `caniuse-lite` `1.0.30001826` > `1.0.30001720`.
- `null` for: `dev-main`, `dev-feature/x`, `1.2.3.4.5`, an 11-digit part,
  `github:owner/repo#sha`, a non-registry URL, `1.0.0-béta`.
- Registry tarball URL → key of its version.
- PHP: `8.3.12-1+ubuntu22.04.1` = `8.3.12`.
- `1.0.0-RC2` < `1.0.0-RC10`; `rc10` = `rc.10`.

Every row of these tests runs against the database too (SQLite in the suite):
insert the keys, `ORDER BY` them, and compare the order to the PHP sort, so
byte order and SQL order agree.

`VersionConstraintTest`: `apply()` on a table of mixed versions for `>=7.0 <7.4`,
`<2.0.0` (includes `2.0.0-beta`), `=1.0`, `>=7.4 <7.5` (includes
`7.4.x-dev`); `null` rows excluded; inverse includes `null` rows; bound
`dev-main` rejected.

Existing tests for the five call sites keep passing. Add one per call site that
asserts the result comes from SQL: total/pagination of `find()` with more
matches than one page, and a Filament filter count.

Command test: rows with missing or stale keys get correct keys; a second run
changes nothing. Migration test: existing rows get keys after migrating.
Remember `tests/database/migrations/` mirrors the stubs.
