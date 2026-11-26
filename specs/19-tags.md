# Tags

**Date:** 2026-11-26
**Status:** draft
**Builds on:** `13-mcp-server.md` (MCP tools, write-tool switch),
`17-configurable-icons.md` (every new icon is a `VoightIcon` case)

Curated, typed tags on projects, environments, customers, packages and
vulnerabilities. Admins define tag types (e.g. "Security level") with a fixed
set of tags (Low / Medium / High). Users assign them in the panel, one by one
or in bulk on a filtered table, and filter on them, also through relations.
Claude assigns them over MCP to sync classifications kept elsewhere (e.g. a
spreadsheet).

---

## Why

Classifications such as a project's security level or a customer's tier live
outside Voight today. Getting them in makes them usable in **filters**: "show
critical vulnerabilities in projects with security level High". Claude can
keep them in sync from their source.

## Goals

- Tag types and their tags are managed on their own admin screen. Assigning
  never creates a tag.
- A tag type applies to one or more of the five models, or to all of them.
- A tag type is either single-value (a record holds at most one of its tags)
  or multi-value.
- In the panel, tags can be assigned per record and in bulk: filter → select
  all → apply.
- Tags are filterable on each model's own table **and** through relations on
  related tables.
- MCP can read tag types, tags and assignments, filter on tags, and assign or
  remove tags. Unknown tags or types fail with an error naming what is
  missing, so an admin knows what to create.

## Non-goals (this phase)

- **Auto-tagging rules** (phase 2): saving a table's filter state on a tag and
  re-applying it when new results arrive. That needs a pivot `source` column
  (manual vs rule), added by a phase-2 migration. Nothing here may block it.
- **An MCP tool that creates tags or tag types.** It may be added later.
- Per-locale editing UI for tag names. Names are stored translatable (spatie)
  but entered in the app locale.
- Tag colours or icons per tag.

## Anti-goals

- Free-form tagging: no input anywhere that creates a tag as a side effect.
  This rules out Filament's `SpatieTagsInput`.
- Touching a host app's own spatie-tags setup: no use of the global `tags` /
  `taggables` tables or `config/tags.php`.
- Partial syncs over MCP: a batch with one invalid row applies nothing.

---

## Approaches considered

1. **spatie/laravel-tags, extended, on Voight-owned tables**: *chosen.*
   - A `Tag` model extends Spatie's `Tag` and uses table `voight_tags`.
   - Tagged models override `getTagClassName()` and `getTaggableTableName()`
     so assignments go to `voight_taggables`.
   - A new `TagType` model holds the type metadata.
   - We get Spatie's attach/detach/sync methods, the
     `withAnyTags`/`withAllTags`/`withoutTags` scopes, ordering
     (`eloquent-sortable`) and translatable names.
   - Cost: two new dependencies, `spatie/laravel-tags` ^4.12 and its required
     `spatie/laravel-translatable` ^6.
2. Our own three tables and a morph-to-many trait. Rejected: it re-implements
   what Spatie ships, and the user asked for Spatie.
3. Spatie-tags with its default global tables and config. Rejected: it
   collides with a host app that uses spatie-tags itself.

Filtering through relations (option "B") was chosen over own-model-only
filters, because the classifications pay off where you triage (findings,
vulnerabilities).

---

## Data model

### `voight_tag_types`

| column | type | notes |
|---|---|---|
| id | id | |
| key | string, unique | slug of the name at creation, **immutable**. Spatie's `type` column on tags stores this key. Also the identifier MCP uses. |
| name | json | translatable (`HasTranslations`) |
| description | json, nullable | translatable. What the classification means; shown in the panel and returned over MCP so an LLM can map source values. |
| is_single | boolean, default false | single-value type |
| taggable_types | json, nullable | list of morph aliases (`voight-project`, …). `null` = applies to all five models. |
| order_column | integer, nullable | sortable |
| timestamps | | |

### `voight_tags`

This is Spatie's schema (`name` json, `slug` json, `type` string,
`order_column`, timestamps) with:
- `type` **not null**;
- a foreign key `type` → `voight_tag_types.key`, cascade on delete;
- unique (`type`, slug in the default locale), so a type cannot hold the same
  tag twice. The builder picks how to enforce this across DB drivers: a
  validation rule plus a unique index if the JSON path allows one.

### `voight_taggables`

Spatie's pivot: `tag_id` (FK → `voight_tags`, cascade), `taggable_type` (morph
alias), `taggable_id`, with unique (`tag_id`, `taggable_id`, `taggable_type`).

### Models

- `Models\TagType`:
  - `tags(): HasMany` via `type` → `key`;
  - `appliesTo(Model|string $model): bool`;
  - scope `forModel(string $class)`.
- `Models\Tag extends Spatie\Tags\Tag`:
  - `$table = 'voight_tags'`;
  - `tagType(): BelongsTo` via `type` → `key`.
- `Models\Concerns\HasVoightTags`:
  - uses Spatie's `HasTags`;
  - overrides `getTagClassName()` → the configured tag model, and
    `getTaggableTableName()` → `voight_taggables`;
  - used by `Project`, `Environment`, `Customer`, `Package` and
    `Vulnerability`.
- Morph map gets `voight-tag-type` and `voight-tag`. The models are
  overridable through `filament-voight.models.*` like the existing ones, with
  accessors on `FilamentVoightConfig`.
- Migrations are package stubs following the existing naming
  (`create_voight_tag_types_table`, …), mirrored in
  `tests/database/migrations/`.

### Assignment service (single entry point)

`Services\TagAssigner` is used by every write path: record form, view-page
action, bulk actions and MCP.

- `assign(iterable<Model> $records, Collection<Tag> $tags): void`
- `remove(iterable<Model> $records, Collection<Tag> $tags): void`
- `sync(Model $record, TagType $type, Collection<Tag> $tags): void` (used by
  forms)

Rules, all checked **before** anything is written; violations throw a
`TagAssignmentException` that carries a list of problems:

- the tag's type must apply to the record's model;
- a single-value type accepts at most one tag per call;
- on a single-value type, assigning replaces the record's existing tag of that
  type.

Writes for a batch happen in one DB transaction.

---

## Panel

### Tag types screen

- `TagTypeResource` in the **settings** navigation group, next to customers
  and teams. Schemas live in `TagTypeResource/Schemas/`.
- Form fields: name, description, single value, applies to (checkbox list of
  the five models; none checked = all).
- The type's tags are managed inline as a relation manager (create, rename,
  reorder, delete). Each tag row shows how many records carry it.
- **Narrowing "applies to"** while records of a removed model carry tags of
  this type is refused with a validation message giving the count per model.
  *(builder's call; detaching silently would lose data)*
- Deleting a type or a tag asks for confirmation and states how many
  assignments go with it. The cascade removes them.
- Turning on **single value** while some record holds two or more tags of the
  type is refused the same way.
- Authorization: the host's policies, like the other resources.

### Assigning

- **Project and customer forms:** one select per applicable tag type, labelled
  with the type name and showing its description as helper text. A
  single-value type gets a single select, a multi-value type a multiple
  select. Options are existing tags only. Saving goes through
  `TagAssigner::sync`.
- **Environment, package and vulnerability** have no edit form, so their view
  pages get an **"Edit tags"** header action with the same fields in a modal.
- **Bulk actions** on all five tables:
  - "Apply tags": pick a type, then its tags; single-value types replace.
  - "Remove tags".
  - Both work on Filament's "select all" across pages, chunked, through
    `TagAssigner`.
- **Authorization** of every assign path: the record's `update` policy
  ability. With no policy defined, that is allowed, the same as the rest of
  the panel.
- **Display:**
  - a "Tags" column (badges `Type: Tag`, hidden by default) on the five
    tables;
  - a tags entry on the five view pages.

### Filters

- `Resources\Components\TagConstraint::make(TagType $type, ?string $relation = null)`
  is a query-builder `SelectConstraint`:
  - label `<Model> › <Type>` when a relation is given, `<Type>` otherwise;
  - options are the type's tags; multiple allowed;
  - "is" means *has any of*, "is not" means *has none of*;
  - queries go through Spatie's `withAnyTags` inside `whereHas($relation)`.
- Each table gets one constraint per applicable tag type, per model it can
  reach.
- **Rule for which relations a table offers:** wherever a table already
  filters on a related project, customer, environment or package, it also
  offers that model's tag types through the same relation path. This is the
  pattern of `EnvironmentStageConstraint` / `ProjectConstraint`. Expected
  result:

| Table | Own tags | Through relations |
|---|---|---|
| Projects | project | customer |
| Environments | environment | project, customer |
| Customers | customer | — |
| Packages | package | environment (`environmentPackages.environment`), project, customer |
| Vulnerabilities | vulnerability | package, and project/customer if the table reaches them |
| Findings (open findings page + finding relation managers, `AuditFindingTableSchema`) | — | vulnerability, package, environment, project, customer |

  The builder confirms each path against the table's existing constraints.
  Where a cell needs a relation that does not exist yet, the builder adds it
  only if it is a plain Eloquent/`HasManyDeep` path; otherwise drops the cell
  and notes it.

---

## MCP

### `list_tags` (read)

Returns every tag type:
- `key`, `name`, `description`, `single`;
- `applies_to`: model names, or `"all"`;
- its tags: `name`, `slug`, `usage_count`.

### Tags in existing read tools

- Records returned by `list_projects`, `get_project_vulnerabilities`,
  `get_vulnerability` and `find_package_usage` gain a `tags` field:
  `[{type, tag}]` with the type key and the tag name.
- Those tools gain an optional `tags` filter, using the same reference format
  as `assign_tags`.
- Semantics: a record matches when, for every type listed, it has any of the
  given tags.
- Which relations each tool filters through follows the panel's filter table.
  The builder picks the minimal sensible set per tool, e.g. project tags on
  `get_project_vulnerabilities`.

### `assign_tags` (write; behind `mcp.write_tools`)

- Input:
  - `model`: project | environment | customer | package | vulnerability;
  - `action`: add | remove;
  - `rows`: up to 500, each `{record, tags: [{type, tag}]}`.
- `record` identifies the record by its natural key:
  - project: `project_code`;
  - environment: `project_code` + `environment` name;
  - customer: `slug`, or `name` matched exactly;
  - package: `type` + `name`;
  - vulnerability: `source` + `source_id`, or `source_id` alone when it is
    unambiguous.
- Tags are referenced as `{type: <type key>, tag: <tag name or slug>}`.
  Matching is case-insensitive on the default-locale name or the slug.
- **Validation is all-or-nothing.** Every row is checked first. If any row has:
  - an unknown type,
  - an unknown tag,
  - a type that doesn't apply to `model`,
  - more than one tag of a single-value type,
  - a record that isn't found,
  - or a record the user may not `update` (see below),

  the tool applies **nothing** and returns an error listing each problem with
  its row index. Missing types and tags are named, so an admin can create
  them.
- On success it returns a count per row (added, replaced, removed, unchanged).
- Its description explains the error-then-ask-admin flow, and that
  single-value types replace.

### Visibility and authorization over MCP

*(decided by the user)*

Tags and tag types are **global**: one classification per record, the same
for every team.

**Visibility** extends `McpAccess`, which today scopes only projects:

- **projects and environments:** as today, through `visibleProjects()`;
- **customers:** those of a visible project;
- **packages:** those installed in an environment of a visible project;
- **vulnerabilities:** those with a finding, or a vulnerable range on an
  installed package, in a visible project. The builder reuses whatever path
  the existing tools use;
- users who see everything, and the local server, see all records.

A record outside this scope is reported as **"not found"**.

**Write permission:** a visible record may be tagged when the host's `update`
policy ability for **that record's model** allows it. Generalise
`McpAccess::authorize()` beyond `Project`. With no policy registered for the
model, allow it, like `authorize()` does for projects today (and like
Filament does in the panel). The same
permission check is used in the panel. A visible record the user may not
update is reported as **"not allowed"**, which is a separate problem from
"not found" in the error list.

**Counts:** `list_tags`'s `usage_count` counts only records visible to the
caller. The admin tag-type screen shows global counts.

---

## Icons and translations

- Every new icon is a `VoightIcon` case with a description, for example:
  - navigation: tag types;
  - actions: apply tags, remove tags, edit tags;
  - the tags column and entry.

  The guard test enforces this.
- All UI strings go through `voightTrans`. Tag and type names are data, not
  translations.

## Testing

- **Models:**
  - `HasVoightTags` uses `voight_tags`/`voight_taggables`. A host-style
    default `tags` table is never touched (assert it doesn't exist and that
    queries don't use it).
  - Morph aliases are stored.
- **`TagAssigner`:**
  - refuses a type that doesn't apply, more than one tag on a single-value
    type, and a mixed batch with one bad row (nothing written);
  - replaces the existing tag on single-value types;
  - has an all-models type.
- **Tag type screen:** narrowing applies-to or turning on single value with
  conflicting assignments is refused; delete cascades.
- **Panel:**
  - the bulk apply over a filtered selection tags exactly the filtered
    records;
  - forms show only applicable types;
  - "Edit tags" on a view page works.
- **Filters:** `TagConstraint` on own tags and through one relation per
  table. Is / is-not semantics.
- **MCP:**
  - `list_tags` shape;
  - the `tags` output and filter on each extended tool;
  - `assign_tags`:
    - add, remove, single-value replace;
    - the all-or-nothing error listing unknown type, unknown tag, wrong model
      and missing record together;
    - disabled when write tools are off;
    - scoping.

## Left to the builder

- Exact relation paths per table and per MCP tool, following the rule above.
- How tag-name uniqueness per type is enforced per DB driver.
- Chunk size for bulk actions.
- Exact error message wording, translated in the panel and English in MCP,
  like the existing tools.
