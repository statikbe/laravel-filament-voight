# Configurable Icons

**Date:** 2026-11-26
**Status:** implemented
**Builds on:** —

Every icon the package renders comes from one semantic `VoightIcon` enum. Each
case carries a default icon and a description. Host apps override icons in
config or through Filament's own icon alias system, and an LLM can read the
descriptions to pick fitting replacements (e.g. when swapping to another icon
set).

---

## Problem

Icons are hardcoded in ~90 places across ~40 files:

- resources, relation managers, actions, infolist/table schemas, widgets
  (`Heroicon::Outlined…` enum cases);
- 11 package enums implementing `HasIcon` (`Severity`, `AuditRunStatus`,
  `DependencySyncStatus`, `AlertChannel`, `AlertFrequency`, `AuditRunTrigger`,
  `DependencyKind`, `VulnerabilitySource`, `EnvironmentIssueType`,
  `SyncWarning`, `PackageType`) as `'heroicon-o-…'` strings;
- `Support/McpClientSetup.php` and `resources/views/mcp/clients.blade.php`.

A host app cannot change any of them, and the mix of enum cases and strings
makes the set hard to audit. `FilamentVoightServiceProvider::getIcons()`
already feeds `FilamentIcon::register()` but returns `[]`.

## Goals

- Every icon the package names (in its own code and views) is resolved
  through a `VoightIcon` case. Icons Filament picks itself (boolean
  `IconColumn` true/false, built-in action icons) stay on Filament's own
  aliases, so global host overrides of those keep working.
- Overridable per case via `config('filament-voight.icons')` **and** via
  Filament's standard `FilamentIcon::register(['voight::…' => …])`.
- Each case has a human/LLM-readable description of what it represents and
  where it shows.
- Descriptions are discoverable from the enum source and from
  `php artisan voight:icons [--json]`.

## Non-goals

- Shipping alternative icon sets or a dependency on any icon package.
- Changing any icon's default appearance. After this change the UI looks
  identical.
- Theming colours or sizes.

## Anti-goals

- Config lookups scattered across call sites. Call sites only ever call
  `VoightIcon::X->resolve()` (or `getIcon()` on a package enum).
- Defaults living in config. Published configs are snapshots; new cases added
  in later releases must still render without the user re-publishing.
- Merging distinct usages into one case just because they share an icon today.

---

## Approaches considered

**Where defaults and descriptions live**

1. **A PHP attribute on each case** — *chosen.*
   `#[IconDefinition(Heroicon::OutlinedFire, '…')]` keeps alias, default and
   description on adjacent lines, so a case cannot be added without both, and
   an LLM reading the file sees each slot as a unit. Cost: reflection, cached
   per case in a static array.
2. Two `match` methods (`default()`, `description()`), matching the style of
   the other package enums. Rejected: with ~70 cases each case lives in three
   places hundreds of lines apart, easy to drift and hard to read as a set.

**How descriptions are exposed**

- A. `description()` on the enum — *chosen.*
- B. `voight:icons` artisan command with `--json` — *chosen.*
- C. Generated comments in the published config — rejected, goes stale the
  moment a case is added.

---

## Design

### `IconDefinition` attribute

`src/Support/IconDefinition.php` (location is a builder-level call; no new
base folder):

```php
#[Attribute(Attribute::TARGET_CLASS_CONSTANT)]
final readonly class IconDefinition
{
    public function __construct(
        public string | BackedEnum $default,
        public string $description,
    ) {}
}
```

### `VoightIcon` enum

`src/Enums/VoightIcon.php`, string-backed. The value is the alias suffix in
dot notation, grouped by area: `navigation.projects`, `severity.critical`,
`audit-run-status.completed`, `action.open-external`, `environment.stat.php`,
`mcp-client.claude-code`, …

```php
enum VoightIcon: string
{
    public const ALIAS_PREFIX = 'voight::';

    #[IconDefinition(Heroicon::OutlinedFire, 'Critical severity: badges and filters for the most urgent vulnerability level, needing immediate action.')]
    case SeverityCritical = 'severity.critical';
    // …

    /** The Filament alias, e.g. "voight::severity.critical". */
    public function alias(): string;

    /** The icon to render: registered Filament alias, else the attribute default. */
    public function resolve(): string | BackedEnum | Htmlable;

    public function default(): string | BackedEnum;

    public function description(): string;

    /** @return array<string, string|BackedEnum> alias => icon, from config overrides only */
    public static function configOverrides(): array;
}
```

- `resolve()` = `FilamentIcon::resolve($this->alias()) ?? $this->default()`.
- `default()` / `description()` read the attribute via
  `ReflectionEnumBackedCase`, memoised in a `static` array.
- The enum is **not** passed straight to `->icon()`: Filament would render its
  backing value as an icon name. Call sites always use `->resolve()`.

**Case granularity.** One case per distinct *usage*, not per distinct icon.
Where two places currently disagree, both get their own case and keep their
current icon. Known instances:

- `AuditRunStatusWidget` maps statuses to its own icons → separate
  `widget.audit-run-status.*` cases, not reused `audit-run-status.*` cases.
- `EnvironmentInfolistSchema`: the stats row and the `GROUP_ICONS` map use
  different icons for "environment" → separate `environment.stat.*` and
  `environment.group.*` cases, plus `environment.group.fallback`
  (`Squares2x2`) for unknown groups.
- Dynamic icons (check vs. warning row, file vs. folder path) get one case
  per branch.

Same-concept reuse is fine where the meaning really is identical (e.g.
`ProjectResource` navigation and the team's projects relation tab are *not*
identical; the four "no vulnerabilities" empty states *are* →
`empty-state.no-vulnerabilities`). The builder decides per call site and
writes the description accordingly.

**Descriptions are not translated.** They are developer/LLM documentation,
never shown in the panel, so they stay plain English in the attribute (PHP
attributes cannot call `__()` anyway). The "always translate" rule applies to
panel UI only.

**Description style.** One sentence: what it represents + where it shows.
Written for someone choosing a replacement from a different icon set, so name
the concept, not the glyph ("Opens the package's external website in a new
tab", not "arrow out of a box").

### Config

New top-level key in `config/filament-voight.php`, empty by default, with a
header comment explaining both override routes and pointing at
`php artisan voight:icons`:

```php
'icons' => [
    // 'severity.critical' => 'phosphor-fire',
    // 'navigation.projects' => \Filament\Support\Icons\Heroicon::OutlinedFolder,
],
```

Keys are enum values (no `voight::` prefix). Values: icon name string or
`BackedEnum` (both survive `config:cache`). Read via a new
`FilamentVoightConfig::getIconOverrides(): array`. Unknown keys are ignored.

### Registration and precedence

`FilamentVoightServiceProvider::getIcons()` returns
`VoightIcon::configOverrides()` (alias-prefixed). Move the
`FilamentIcon::register($this->getIcons())` call from `packageBooted()` to
`packageRegistered()`: package providers register before app providers, so
any app-level `FilamentIcon::register()` (in `register()` or `boot()`) is
queued later and wins. Config is already loaded at that point.

Precedence, highest first:

1. App's own `FilamentIcon::register(['voight::…' => …])`.
2. `filament-voight.icons` config.
3. `#[IconDefinition]` default.

Only overrides are registered, never defaults, so Filament's alias table
stays a pure "user changed this" list.

### Call-site migration

- **Navigation icons** (`protected static … $navigationIcon`, 7 resources +
  `ListEnvironments`): drop the property, override
  `getNavigationIcon(): string | BackedEnum | Htmlable | null`.
- **Relation manager tab icons** (`protected static … $icon`): drop the
  property, override `getIcon(Model $ownerRecord, string $pageClass)`.
- **Fluent calls** (`->icon()`, `->emptyStateIcon()`, `$this->icon()` in
  actions, `icon:` named args in `McpClientSetup`): pass `VoightIcon::X->resolve()`.
  `McpClient::$icon` widens from `Heroicon` to `string | BackedEnum | Htmlable`.
- **Package enums**: `getIcon()` / `icon()` return
  `VoightIcon::X->resolve()`; widen return types to
  `string | BackedEnum | Htmlable` where they are `string`. Remove fallback
  literals like `?? 'heroicon-o-check-circle'` in
  `EnvironmentsRelationManager` in favour of a case.
- **Blade** (`mcp/clients.blade.php`): `:icon="VoightIcon::X->resolve()"`.
- `EnvironmentInfolistSchema::GROUP_ICONS` becomes a map of group key →
  `VoightIcon` case.

### `voight:icons` command

`src/Commands/ListIconsCommand.php`, signature
`voight:icons {--json}`.

- Table: Alias, Default, Current, Overridden (yes/no), Description.
- `--json`: array of `{case, alias, default, current, overridden, description}`
  objects. `default`/`current` rendered as strings (`BackedEnum` → `ShortClass::Case`, e.g. `Heroicon::OutlinedCube`,
  `Htmlable` → `toHtml()`).
- Output is plain English like the other `voight:*` commands (not translated).
- Registered in the service provider's command list; one row added to the
  README's Artisan commands table.

### Breaking changes

The package is unreleased, so this is a clean break, no compatibility shims:

- the 11 package enums' `icon()` / `getIcon()` widen from `string` to
  `string | BackedEnum | Htmlable`;
- `$navigationIcon` / `$icon` static properties are removed from package
  resources and relation managers (host subclasses override the getters);
- `McpClient::$icon` widens.

List them under **Breaking** in `CHANGELOG.md` → Unreleased.

### README

New "Icons" section: the two override routes with examples, precedence,
and a note that `php artisan voight:icons --json` gives an LLM everything
it needs to remap the set.

---

## Testing

- **Enum integrity**: every case has exactly one `IconDefinition` with a
  non-empty description; values are unique and match
  `^[a-z0-9-]+(\.[a-z0-9-]+)*$`.
- **Resolution**: default when nothing registered; config override wins over
  default; `FilamentIcon::register` wins over config.
- **Config accessor**: `getIconOverrides()` returns `[]` when unset.
- **Command**: table output contains a known case; `--json` is valid JSON with
  all cases and marks an overridden one.
- **Guard**: a test that scans `src/` and `resources/views/` and fails on any
  `Heroicon::` or `heroicon-` literal outside `src/Enums/VoightIcon.php`, so
  new hardcoded icons can't creep back in.
- Existing enum tests (`AuditRunTriggerTest`, `DependencyKindTest`, …) that
  assert icon strings are updated to assert the resolved default.

## Left to the builder

- Exact case names and the final case list (expect ~60–70).
- Where same-meaning usages share a case vs. get their own.
- Description wording, following the style rule above.
