# MCP Server

**Date:** 2026-09-30
**Status:** approved design, not yet implemented
**Depends on:** `12-dependency-graph.md` (dependency paths for `get_upgrade_plan`)

An MCP server so AI clients (Claude Code, Cursor, claude.ai, …) can query Voight:
vulnerabilities per project, which direct dependency to upgrade, where a package
is used, and statistics such as the most vulnerable projects. It can also onboard
a project: create it and issue its sync token.

Built on `laravel/mcp` (approved dependency).

---

## MCP primitives

| Primitive | Used | Why |
|---|---|---|
| Tools | Yes | Every use case is a parameterized query or action; the only primitive all clients support. |
| Prompts | Yes, 3 | Cheap user-triggered workflows that chain tools. |
| Resources | No | Clients support them unevenly and agents cannot filter them. Tools expose the same data. |
| Sampling, elicitation, roots, subscriptions | No | Not relevant to a query server. |
| MCP Apps (`AppResource`) | No, future | See Future work. |

---

## Servers and transport

Two server classes, so access rules are tied to the transport and never inferred
from a missing user:

- `Statikbe\FilamentVoight\Mcp\VoightServer` — **remote**, over HTTP. Every tool
  requires an authenticated user; no user means an error, never full access.
- `Statikbe\FilamentVoight\Mcp\VoightLocalServer extends VoightServer` —
  **local**, over stdio (`php artisan mcp:start voight`). Runs as the machine's
  operator and sees everything.

Both declare the same tools and prompts and set server `instructions` describing
the domain (projects → environments → installed packages → findings, what
"current findings" means, severity thresholds).

Registered in `FilamentVoightServiceProvider::packageBooted()` when enabled:

```php
Mcp::local('voight', VoightLocalServer::class);
Mcp::web($config->getMcpPath(), VoightServer::class)->middleware($config->getMcpMiddleware());
```

### Config (`filament-voight.mcp`)

| Key | Default | Notes |
|---|---|---|
| `enabled` | `false` | Registers both servers. |
| `path` | `mcp/voight` | Web route. |
| `middleware` | `['auth:sanctum', EnsureMcpUserToken::class]` | Hosts can swap in OAuth (`Mcp::oauthRoutes()` + Passport) without package changes. |
| `write_tools` | `true` | Hides the write tools on both servers when `false`. |

Exposed through `FilamentVoightConfig` getters (`getMcpPath()`,
`getMcpMiddleware()`, `mcpWriteToolsEnabled()`), like the existing settings.

---

## Authentication (remote)

Sanctum personal access tokens owned by **users**, with ability `voight:mcp`.

- The host's user model (`FilamentVoightConfig::getUserModel()`) must use
  `Laravel\Sanctum\HasApiTokens`. This is a documented installation step.
- `EnsureMcpUserToken` middleware runs after `auth:sanctum` and rejects the
  request unless `$request->user()` is an instance of the configured user model
  **and** `tokenCan('voight:mcp')`. Sanctum resolves a project token's
  tokenable (a `Project`) as the "user", so without this check a CI sync token
  would open the MCP server. The reverse is already safe:
  `AuthenticateProjectToken` only accepts `Project` tokenables.
- A session-authenticated Filament user (Sanctum's stateful guard) passes,
  because `TransientToken::can()` returns true. That is acceptable.

### Token page

A Filament page **MCP access** in the Voight panel (navigation group: settings):

- Lists the current user's tokens with ability `voight:mcp` (name, last used,
  created).
- **Create token** action: asks for a name, creates the token, shows the plain
  text once in a persistent notification with a copy action (same pattern as
  `ProjectInfoListSchema`'s generate token action), plus a ready-to-paste
  `claude mcp add --transport http voight <url> --header "Authorization: Bearer <token>"`
  line. The snippet is rendered by a Blade view.
- **Revoke** action per token.
- If the user model lacks `HasApiTokens`, the page shows an explanation instead
  of the actions.
- All strings via `voightTrans()`.

---

## Visibility

`Project::scopeVisibleTo(Builder $query, ?Authenticatable $user)`:

- **Local server** or a user for whom the admin callback returns true: no
  constraint.
- Otherwise: projects whose `team` has the user as a member
  (`whereHas('team.users', …)`). Projects without a team are not visible.

Both hooks are registered in code because closures cannot live in cached config:

```php
// Simple case: some users see everything. Default: nobody.
FilamentVoight::resolveMcpAdminUsing(fn (Authenticatable $user): bool => $user->is_admin);

// Hosts whose rules are not team-based (per customer, per role, …).
// When registered, replaces the team rule entirely; the admin callback still applies first.
FilamentVoight::resolveMcpVisibleProjectsUsing(
    fn (Builder $query, Authenticatable $user): Builder => $query->where(/* host rules */),
);
```

Visibility is applied **inside the services**, which receive an `McpAccess`
value object (`user`, `seesEverything`) built once per request by the server.
Tools never build project queries themselves.

**Not found and not visible return the same error**, so project codes do not
leak. The only exception is `create_project`, which must report that a code is
taken.

Applying this scope to Filament is out of scope; the scope is written so it can
be adopted there later.

---

## Authorization (host policies)

Policies live in the host app, not in this package. Visibility decides **which
projects exist** for a user (it has to be a query scope, because
`list_projects` and `get_statistics` aggregate, sort and limit in SQL, which a
per-record policy cannot do). Policies decide **what the user may do**, keeping
MCP consistent with the host's Filament permissions (e.g. Filament Shield).

Every tool on the remote server checks the host's policy for the configured
project model (`FilamentVoightConfig::getProjectModel()`):

| Tool | Ability |
|---|---|
| `list_projects`, `get_statistics`, `find_package_usage`, `get_vulnerability` | `viewAny` on the project model |
| `get_project_vulnerabilities`, `get_upgrade_plan` | `view` on the project |
| `create_project` | `create` on the project model |
| `create_project_token` | `update` on the project (matches the Filament token actions on the project page) |

Rules:

- **Both must pass:** the visibility scope and the policy. A policy can only
  restrict further; it never makes a project visible.
- **No policy registered → the check is skipped**, as Filament does. Laravel's
  `Gate` denies when no policy exists, so the check first calls
  `Gate::getPolicyFor($projectModel)`. Without this, installing the package in an
  app without policies would lock everyone out.
- A denied `view` / `update` on a single project returns the same "not found"
  error as an invisible project. A denied `viewAny` / `create` returns "not
  allowed".
- `VoightLocalServer` skips policy checks: there is no user.
- The check lives in one place (`McpAccess::authorize(string $ability, Project|string $target)`),
  called by the tools before touching a service.

---

## Architecture

```
src/Mcp/
  VoightServer.php, VoightLocalServer.php
  McpAccess.php
  Tools/        one class per tool, thin: validate → service → Response
  Prompts/      TriageProjectPrompt, SecurityDigestPrompt, OnboardProjectPrompt
src/Http/Middleware/EnsureMcpUserToken.php
src/Services/Mcp/
  ProjectQueryService.php        list_projects, get_project_vulnerabilities
  UpgradePlanService.php         get_upgrade_plan (uses DependencyGraphService, spec 12)
  PackageUsageService.php        find_package_usage
  VulnerabilityLookupService.php get_vulnerability
  StatisticsService.php          get_statistics
src/Services/ProjectOnboardingService.php  create project, issue token
src/Pages/McpAccess.php          first standalone panel page; registered in FilamentVoightPlugin
```

- **"Current findings"** = findings of `AuditRun::latestIdsPerEnvironment()`,
  exactly what the widgets use.
- **Severity** = `Severity::fromScore($vulnerability->vulnerability_score)`;
  `min_severity` filters on the score thresholds of that method.
- `ProjectOnboardingService` becomes the single place that issues project
  tokens. The Filament generate-token actions (`ProjectFormSchema`,
  `ProjectInfoListSchema`) and `CreateProjectTokenCommand` are switched to it.

---

## Tools

Shared conventions:

- Inputs declared with `schema()`; results returned as `Response::structured()`
  with an `outputSchema()`, so clients get both JSON text and structured content.
- List tools take `limit` (default 25, max 100) and return `truncated: true` when
  more rows exist.
- Project arguments are `project_code`; environment arguments are the
  environment `name`.
- Errors use `Response::error()` with a message the model can act on (e.g.
  "Project has environments production, staging; pass `environment`.").

### Read tools — `#[IsReadOnly]`, `#[IsIdempotent]`

**`list_projects`**
- In: `search?` (code or name), `team?`, `customer?`, `min_severity?`,
  `has_findings?`, `scanned_before?` (ISO date, finds stale scans),
  `sort` = `findings` (default) | `max_score` | `name` | `last_scanned`, `limit`.
- Out: per project: `code`, `name`, `team`, `customer`, `environments[]`
  (`name`, `scanned_at`), current finding counts per severity, `max_score`.

**`get_project_vulnerabilities`**
- In: `project_code`, `environment?`, `min_severity?`, `include_dev` (default
  `true`), `limit`.
- Out: current findings grouped by vulnerability, worst first: `id`
  (source id), `aliases`, `summary`, `score`, `severity`, and per affected
  package: `name`, `type`, `installed_version`, `fixed_version`, `is_direct`,
  `is_dev`, `environments[]`.

**`get_upgrade_plan`**
- In: `project_code`, `environment` (required when the project has more than
  one), `min_severity?`.
- Out: `actions[]`, one per **direct dependency to act on**
  (`DependencyGraphService::introducingDirectDependencies()`), ordered by the
  worst severity they resolve:
  - `package`, `type`, `current_version`, `is_dev`
  - `resolves[]`: vulnerability `id`, `severity`, the vulnerable `package`,
    its `installed_version` and `fixed_version`
  - `paths[]`: each path from `pathsToRoots()`, as `[{name, version, constraint}]`
    root → vulnerable node, where `constraint` is the edge's declared range
  - A vulnerable package that is itself direct is its own action with a single
    one-node path.
- No registry lookups: the tool does not determine which version of the direct
  dependency pulls in a fixed transitive version. The constraints and fixed
  versions let the model reason about it (e.g. "`^0.0.8` excludes `1.2.6`, so a
  major bump of the parent is needed"). The tool description says so.

**`find_package_usage`**
- In: `package`, `type?` (`composer` | `npm`), `version?` — a single comparison
  `<`, `<=`, `>`, `>=`, `=` followed by a version (e.g. `<4.17.21`), compared
  with `version_compare()` after stripping a leading `v`; `include_dev`
  (default `true`); `limit`.
- Out: per match: `project_code`, `environment`, `version`, `is_direct`,
  `is_dev`.

**`get_vulnerability`**
- In: `id` — matched on `source_id` or any entry in `aliases` (CVE, GHSA).
- Out: `id`, `aliases`, `summary`, `details`, `score`, `severity`,
  `published_at`, `affected[]` (`package`, `affected_range`, `fixed_version`),
  and the **visible** projects/environments currently affected.

**`get_statistics`**
- In: `group_by` = `project` | `team` | `customer` | `package` |
  `vulnerability`, `min_severity?`, `team?`, `customer?`, `limit`.
- Out: per group, worst first: `key`, `label`, current finding counts per
  severity, `max_score`, `affected_projects` (count). Covers "most vulnerable
  projects", "most widespread vulnerable packages" and "vulnerabilities reaching
  the most projects".
- Only visible projects count towards any group.

### Write tools

Not read-only, not destructive, not idempotent. Hidden via `shouldRegister()`
when `write_tools` is off.

**`create_project`**
- In: `project_code`, `name`, `repo_url?`, `team` (name; required unless the
  caller sees everything), `customer?` (name).
- Remote users may only pick a team they belong to, so they can see what they
  create. Unknown team or customer → error listing the valid options the caller
  may use.
- Fails when `project_code` exists; never returns or modifies the existing
  project.
- Out: the project (`code`, `name`, `team`, `customer`) and a hint to call
  `create_project_token`.

**`create_project_token`**
- In: `project_code` (visible project), `name` (default
  `Project::DEFAULT_API_TOKEN_NAME`).
- Never revokes existing tokens; revoking stays in Filament.
- Out: the **plain-text token** (shown once), the sync endpoint URL
  (`url('api/voight/lock-file')`), the uploader variables (`VOIGHT_API_URL`,
  `VOIGHT_API_TOKEN`, `PROJECT_CODE`) and an explicit instruction: store the
  token as a CI secret or in an untracked `.env`, never commit it.
- Rationale for returning the secret: the token only allows pushing lockfiles
  for that project, and returning it is what makes agent-driven onboarding work.
  The tool description warns that the value will appear in the conversation.

---

## Prompts

| Prompt | Arguments | Does |
|---|---|---|
| `triage_project` | `project_code` | `get_project_vulnerabilities` → `get_upgrade_plan`; ask for a prioritized fix list (severity, reach, effort of the bump). |
| `security_digest` | `team?` | `get_statistics` (by project and by vulnerability) + `list_projects(scanned_before: 7 days ago)`; a weekly summary including stale scans. |
| `onboard_project` | — | Inspect the current repository (lockfiles, CI config), `create_project`, `create_project_token`, then add the uploader and its variables to CI, storing the token as a secret. |

Prompt texts are written in English only (they are instructions to the model,
not UI).

---

## Testing

Pest, using `laravel/mcp`'s server test helpers
(`VoightServer::actingAs($user)->tool(Tool::class, [...])`).

- **Visibility:** for every read tool, a team member sees only their teams'
  projects; a non-member gets the same "not found" as for an unknown code;
  teamless projects are hidden from non-admins; the admin callback sees all;
  `VoightLocalServer` sees all; `VoightServer` without a user errors.
- **Visibility override:** `resolveMcpVisibleProjectsUsing` replaces the team
  rule; the admin callback still grants everything.
- **Policies:** with no policy registered, tools work; a policy denying
  `viewAny` blocks list tools with "not allowed"; denying `view` on one project
  returns "not found"; denying `create` / `update` blocks the write tools; a
  policy allowing everything does not reveal projects outside the visibility
  scope; the local server ignores policies.
- **Middleware:** a project token is rejected; a user token without
  `voight:mcp` is rejected; a user token with it passes.
- **Per tool:** filters, sorting, `limit` / `truncated`, severity thresholds,
  "current findings" ignore older audit runs, `include_dev`.
- **`get_upgrade_plan`:** transitive vulnerability grouped under its direct
  dependency with path and constraints; a direct vulnerable package; two direct
  dependencies introducing the same vulnerable node.
- **`find_package_usage`:** each comparison operator; `v` prefix.
- **`get_vulnerability`:** lookup by source id, CVE alias, GHSA alias.
- **Write tools:** team restriction for remote users; duplicate code fails
  without touching the existing project; token is a working
  `AuthenticateProjectToken` token for that project; existing tokens survive;
  tools are absent when `write_tools` is off.
- **`ProjectOnboardingService`:** the Filament actions and
  `CreateProjectTokenCommand` still work after switching to it.
- **Token page:** create shows the token once; revoke removes it; only the
  current user's `voight:mcp` tokens are listed.
- **Registration:** nothing is registered when `enabled` is `false`.

---

## Out of scope

- OAuth / claude.ai web connectors. The configurable middleware leaves room for
  a host to use `Mcp::oauthRoutes()` with Passport.
- Team scoping in Filament.
- Mutating tools beyond onboarding (muting, triggering audits, revoking tokens).
- MCP resources.

## Future work

- **MCP Apps:** `laravel/mcp` supports `AppResource` and `#[RendersApp]`. An
  interactive upgrade-plan tree or a project × severity matrix rendered inline
  in claude.ai or VS Code would suit this data. Not for authentication: an app
  requires an existing connection and would put credentials in the transcript.
- **Registry lookups** in `get_upgrade_plan` (Packagist / npm) to find the
  lowest parent version that pulls in the fixed version.
- **Outdated packages** tool once `Package::latest_version` is reliably
  populated.
