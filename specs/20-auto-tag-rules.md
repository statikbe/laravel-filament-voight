# Auto-tag Rules

**Date:** 2026-11-27
**Status:** implemented
**Builds on:** `19-tags.md` (tag types, tags, `TagAssigner`, `voight_taggables`)

Save a filtered table as a rule on a tag. Every record the saved query
matches carries the tag, and the tag follows the query: it is added when a
record starts to match and removed when it stops (only where a rule added
it). The tag's page shows its rules in plain words, with a link that opens
the table with the same filter.

---

## Why

Classifications often follow from data Voight already has, for example
"critical vulnerabilities in High-security projects" → *Needs triage*.
Keeping those tags right by hand doesn't scale and goes stale. A rule saved
from the table where you built the filter keeps the tag current as syncs,
scans and edits change the data.

## Goals

- **Save:** "Save as auto-tag rule" on a filtered resource list table stores
  the query on a tag.
- **Live:** matching records get the tag, and records that stop matching
  lose it, unless a person set it or another rule still matches.
- **Triggers:** rules re-run after anything that can change their results:
  - create, update or delete of a project, environment, customer, package
    or vulnerability;
  - tag assignment changes;
  - a finished lockfile sync;
  - a recorded scan;
  - a nightly pass;
  - right after a rule is saved.
- **Statuses:** rules are Active, Paused or Failed. A failed query flags the
  rule and stops re-running it until it is retried or saved again.
- **Removing:** a rule can be deleted, optionally removing the tags only it
  provided.
- **Visibility:** the tag's page lists its rules: summary, tab, match count,
  last run, status, "Open in table". The tag type's tags table shows each
  tag's rule count, failed rules, and how many uses came from rules.

## Non-goals

- Rules saved from relation-manager tables (e.g. a project's findings tab).
  Only resource list pages.
- Editing a rule's conditions in place. Change it in the table, then save
  again.
- Email or Slack notifications for failed rules.
- Working out which rules a change affects. Every change re-runs all active
  rules.

## Anti-goals

- A rule overwriting or removing a tag a person set.
- A failing query removing a rule's tags. Failure is not "no matches".
- Rules re-triggering each other: the runner's own tag writes must not
  queue another run.
- Rule SQL being writable or editable from the panel or over MCP.

---

## Approaches considered

- **A. Store Filament's table state and replay it** by building the list
  page in a job and calling `getFilteredTableQuery()`.
  - Pros: exact and future-proof.
  - Cons: relies on building a Filament page outside a request, and needs a
    user to run as. It would have needed a spike first.
- **B. Translate the filter into Voight's own rule format.**
  - Every filter type implemented twice, and the copies drift.
  - Tabs need their own translation.
- **C. Store the generated SQL** (with bindings) at save time, plus a
  description, the filter state and the tab: *chosen.*
  - Running a rule is a plain query, with no Filament involved and no user
    to run as.
  - It matches exactly what the table showed when the rule was saved.
  - Relative date filters ("past week") are refused when saving, since the
    stored SQL would freeze them at the save date.
  - Accepted costs:
    - A release that changes what a filter means leaves old rules on the
      old meaning until re-saved.
    - A schema change can break stored SQL. This is why the Failed status
      exists, and re-saving fixes it.
    - The saver's visibility scope is baked into the SQL. Rules are
      admin-made.

---

## Data model

### `voight_tag_rules`

| column | type | notes |
|---|---|---|
| id | ulid | |
| tag_id | ulid FK → `voight_tags`, cascade on delete | |
| taggable_type | string | morph alias of the table's model; the tag's type must apply to it |
| source_page | string | class of the list page it was saved from, e.g. `ListVulnerabilities` |
| tab | string, nullable | active tab key |
| table_state | json | `{filters, search}` as the list page's URL-bound properties (`filters`, `search`) |
| description | json | list of indicator labels shown above the table when saved, e.g. `["Severity: Critical", "Project › Security level: High"]` |
| query_sql | text | SQL returning the matching record keys: `select <qualified key> from (...)`, see below |
| query_bindings | json | |
| status | string | `active` / `paused` / `failed` (enum `TagRuleStatus`) |
| last_error | text, nullable | set when failed |
| failed_at | timestamp, nullable | |
| last_run_at | timestamp, nullable | |
| last_match_count | integer, nullable | |
| created_by | nullable morph or user key | the admin who saved it; shown on the card. Builder: follow how the package stores user references elsewhere. |
| timestamps | | |

### `voight_tag_rule_matches`

| column | notes |
|---|---|
| tag_rule_id | FK → `voight_tag_rules`, cascade |
| taggable_type, taggable_id | string(36) like `voight_taggables` |
| unique | (`tag_rule_id`, `taggable_type`, `taggable_id`) |

### `voight_taggables`: new column

- `is_manual` boolean, default **true**. Existing rows are therefore
  manual.
- The rule runner inserts `false` when it adds a tag the record didn't
  have.
- `TagAssigner` (panel, bulk actions, MCP) writes `true` for tags it
  **adds**.

What counts as a person assigning a tag:

- **Explicit "add this tag":** bulk "Apply tags", or `assign_tags` with
  `add`. This marks the row manual (`true`), also when a rule had added it
  before. The tag then stays even when no rule matches.
- **Re-saving a form or the "Edit tags" modal with the field unchanged:**
  this does **not** flip existing automatic rows. `sync()` compares old and
  new and only writes `true` for tags that weren't there before.
  Otherwise, renaming a project would quietly freeze its rule tags.
  *(builder's call)*
- If a person removes a tag that a rule still matches, the next run adds
  it back with `is_manual = false`. That's the live behaviour that was
  agreed.

The migration adds the column. Test migrations are mirrored as usual.

---

## Saving a rule

- **The action:** a header action **"Save as auto-tag rule"** on the five
  taggable resource list pages: projects, environments, customers,
  packages, vulnerabilities. Open findings (`AuditFinding`) is not taggable,
  so it has no action.
  - Visible only when the table has at least one active filter or a
    non-default tab.
- **Form:** one field, a tag select. Options are grouped by type and
  limited to tags whose type applies to the page's model.
- **On save:**
  1. `query = $livewire->getFilteredTableQuery()`, with search and tab
     applied the way the page applies them.
  2. Store `query_sql` / `query_bindings` from
     `query->reorder()->select(<qualified key>)`. Ordering doesn't matter,
     and keys are enough.
  3. Store `table_state`, `tab`, `source_page`, and `description` from the
     table's current filter indicator labels.
  4. Create the rule as *active* and run it immediately (synchronously, so
     the notification can say "Rule saved: 12 records tagged").
- **Re-saving:** opening a rule in the table and saving again offers to
  **replace** the existing rule (same tag, same page) rather than create a
  duplicate. The builder decides the exact UX, e.g. an `editingRule` query
  parameter carried by "Open in table".
- **Authorization:** only users who may update tag types (the host policy
  for `TagType`; no policy means allowed, as elsewhere).

## Running rules

`Services\TagRuleRunner`:

- `run(TagRule $rule): void` for one rule.
- `runAll(): void` for all active rules.

**Per rule:**

1. Execute `query_sql` with its bindings to get the matching keys. On any
   `QueryException`:
   - set `failed`, `last_error`, `failed_at`;
   - log a warning with the rule id and tag;
   - stop. Tags and matches are untouched.
2. In one transaction:
   - Replace the rule's rows in `voight_tag_rule_matches` with the current
     keys.
   - **Add** the tag to matched records that lack it, with
     `is_manual = false`.
   - **Single-value types:** a record that already holds a **manual** tag
     of that type is skipped.
   - **Rules for different tags of the same single-value type matching the
     same record:** the **most recently saved** rule (`updated_at`) wins.
     The others leave that record alone, so the tags don't flip back and
     forth between runs. The runner therefore computes all matches first
     and resolves conflicts per tag type before writing. *(builder's
     call)*
   - **Remove** the tag from records where the row is `is_manual = false`
     and no rule for this tag has a match for the record anymore.
3. Set `last_run_at` and `last_match_count`.

The runner writes the pivot directly, not through `TagAssigner`, so its
writes don't trigger a new run.

**Rules that filter on tags other rules add:** a rule can use a tag
constraint, for example "Needs triage" where Security level is "High",
while another rule adds "High".

- `runAll()` repeats its passes until no rule changes anything, at most 5
  passes, so the second rule sees the first rule's changes in the same run.
- Hitting the limit (a cycle such as A depends on B and B on A) logs a
  warning naming the rules that still changed. *(builder's call)*

**Triggers:** `Jobs\RunTagRulesJob` runs `runAll()`.

- It is `ShouldBeUnique`, so at most one waits in the queue. A burst of
  changes gives one run.
- It's dispatched after commit by:
  - `saved` and `deleted` events of the five taggable models;
  - `TagAssigner` after any write;
  - the end of lockfile processing (`ProcessLockFilesJob`);
  - recording audit runs (`RecordEnvironmentAuditRunsService` or
    wherever a scan's findings are stored).

  The builder finds the exact points.
- Scheduled nightly next to the existing scheduled commands, as a new
  `voight:run-tag-rules` command. The command can also be run by hand.
- Queue: the package's existing queue configuration conventions.
- If no active rules exist, the job returns immediately.

## Pausing, failing, retrying, removing

- **Pause / Resume:** paused rules are skipped. Their tags stay.
- **Failed:**
  - skipped by `runAll()`;
  - the card shows the error and when it failed;
  - **Retry** runs the rule once: success → active, failure → stays failed
    with the new error;
  - saving again from the table (replace) resets it to active.
- **Delete:**
  - Confirmation with a checkbox "Also remove the tags this rule added
    (N records)", ticked by default. N = the rule's matches that are not
    manual.
  - **Ticked:** delete the rule (cascading its matches), then remove
    `is_manual = false` rows of the tag that no remaining rule matches.
  - **Unticked:** set `is_manual = true` on the rule's automatic rows
    first, then delete it.

## Panel

**Tag page:** a tag needs a page to show its rules. Today tags are edited
in a modal in `TagsRelationManager`. Add a **view tag** page or a slide-over
on the tags relation manager. Builder's call. Either way it shows:

```
Needs triage                                   [ 12 records ]
  Rules
  ┌───────────────────────────────────────────────────────────┐
  │ Vulnerabilities · tab: All                         Active │
  │ Severity: Critical · Project › Security level: High       │
  │ 9 matches · last run 10 min ago · by Sten                 │
  │                  [Open in table] [Pause] [Retry] [Delete] │
  └───────────────────────────────────────────────────────────┘
```

**"Open in table":** the list page URL with `filters`, `search` and `tab`
from the rule, plus the rule id so a save there replaces it.

**Tags relation manager (on the tag type page):**
- "Used on" shows `12 (9 by rule)` when some uses come from rules.
- A new **Rules** column with the rule count.
- A danger badge with the number of failed rules, when there are any.

## MCP

`list_tags` gains per tag:
- `rule_count`
- `auto_usage_count` (visible records tagged only by rules)

Rules are not created or edited over MCP.

## Icons and translations

- New `VoightIcon` cases: save rule action, rule status badges, open in
  table, pause, retry.
- All UI strings through `voightTrans`.

## Testing

- **Saving:** from `ListVulnerabilities` with a query-builder rule (including
  a tag constraint) and a tab, the stored SQL returns exactly the IDs the
  table shows.
- **Runner:**
  - a dependent rule settles within one `runAll()`;
  - a cycle stops at the pass limit;
  - a form saved with its tags unchanged keeps rule tags automatic;
  - adds tags;
  - removes them when records stop matching;
  - keeps manual tags;
  - two rules for the same tag: removing one keeps tags the other still
    matches;
  - single-value skip (manual) and replace (automatic);
  - a person assigning an auto tag flips `is_manual`;
  - a SQL error marks the rule failed and leaves tags alone.
- **Triggers:**
  - saving a project or customer, a tag assignment, a sync and a scan each
    dispatch `RunTagRulesJob`;
  - the runner's own writes do not;
  - the job is unique.
- **Statuses:** paused and failed rules are skipped; retry recovers; replace
  on re-save resets to active.
- **Delete:** with and without removing tags.
- **Counts:** the relation manager "Used on (by rule)", rule count and
  failed badge; `list_tags` new fields.
- **Command:** `voight:run-tag-rules` runs all active rules.

## Left to the builder

- Tag page form: a view page or a slide-over.
- The exact dispatch points for syncs and scans.
- The replace-on-re-save UX.
- How the indicator labels are read from the table.
- How `created_by` is stored.
