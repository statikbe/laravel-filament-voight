# Environment System Details

**Date:** 2026-10-06
**Status:** approved (amended, see Change Log)
**Related:** `03-dependency-sync.md` (same token + environment resolution), `14-environment-health.md` (same relation manager)

Show on the project page which server each environment runs on: PHP version and
extensions, OS, database, queue, disk, opcache, deployed commit, Laravel `about`
output. The monitored app pushes it with `voight:push-system-details`, shipped in
this package (which client apps already install for `voight:sync-lockfile`).

---

## Problem

Voight knows each environment's dependencies but nothing about the server under
it. To answer "which projects still run PHP 8.2?" or "is production on the
commit we think it is?" someone has to log in to the client app's admin panel or
SSH into the box.

That information is only visible from inside the client app, so the client has
to report it.

---

## Approach

**Push from the client server, latest snapshot only.**

- Lockfiles are pushed from CI (`voight.sh`), but CI is not the server. Server
  details must be collected by the running app, so the push is an artisan command
  (`voight:push-system-details`) in this package, which client apps already have.
- Voight never calls client apps. No inbound endpoint or extra auth on every
  client server; the existing project token is reused.
- Voight stores one JSON snapshot per environment and overwrites it on each push.
  No history table. Trade-off: no "PHP was upgraded on date X" timeline. Add a
  table when that is asked for.
- Everything lives in one package: no separate system-details package and no
  extra composer dependency.

```
client server: php artisan voight:push-system-details
      → POST {voight}/api/voight/system-details  (Bearer project token)
      → voight_environment_system_details (one row per environment, overwritten)
      → Environment view page (nested under project) + global Environments list
```

---

## Client side: `voight:push-system-details`

### Config

`push` section in `config/filament-voight.php`:

```php
'push' => [
    // Base URL of Voight (no path); posts to {base_url}/api/voight/system-details. null = push disabled.
    // Same vars as voight.sh.
    'base_url' => env('VOIGHT_API_BASE_URL'),
    'token' => env('VOIGHT_API_TOKEN'),
    // Name the receiver files the snapshot under; null = app()->environment().
    'environment' => env('VOIGHT_PUSH_ENVIRONMENT'),
    // Base URL the app uses to call itself (web-server values); null = app.url.
    'loopback_url' => env('VOIGHT_PUSH_LOOPBACK_URL'),
],
```

The environment name must match what `voight.sh` sends (`APP_ENV`), or the
snapshot lands on a second environment record.

### `PushSystemDetailsCommand`

`php artisan voight:push-system-details [--url=] [--token=] [--environment=]`

- Non-interactive; options override config.
- Exits 0 with a one-line notice when `push.base_url` is null, so it is safe in a
  deploy recipe on every project.
- Exits 1 when the base URL is not `https://`, except for `localhost` /
  `127.0.0.1` hosts (local testing). The token never travels in clear text.
- `SystemDetailsCollector` builds the snapshot with PHP built-ins and
  `php artisan about --json`; the locale is forced to English during collection.
- Payload (JSON):

  ```json
  {
    "environment": "production",
    "collected_at": "2026-10-06T09:00:00+00:00",
    "app": { "environment": "production", "debug_mode": false },
    "framework": { "name": "laravel", "version": "12.0.0", "groups": { "environment": {}, "cache": {}, "drivers": {}, "storage": {} } },
    "server": { "php": {}, "system": {}, "deployment": {}, "database": {}, "disk": {} }
  }
  ```

  The contract is framework-neutral: other clients (e.g. a Craft CMS plugin) push the same
  shape. `app` = `app()->environment()` and `config('app.debug')`. `framework.name` =
  `laravel`; `framework.version` = laravel/framework via `Composer\InstalledVersions`
  (leading `v` stripped, null when not installed); `framework.groups` = decoded
  `about --json`, null if it fails. `server.php` = version, sapi, memory_limit, max_execution_time,
  upload_max_filesize, post_max_size, max_input_vars, timezone, ini_path (loaded php.ini),
  xdebug, opcache, opcache_validate_timestamps, extensions (`name => version` map, sorted by name; version null when unknown); `server.system` = os,
  server_software (null in CLI), load_average, cpu_cores (Linux `/proc/cpuinfo`, else null),
  hostname; `server.deployment` = release_path, commit (7 chars, from `REVISION` or
  `.git/HEAD`, else null), php_user, server_time; `server.database` = driver, version
  (null on failure); `server.disk` = free/total bytes of `base_path()`. Every probe is
  guarded and yields null on failure. The UI renders any keys generically.
- **Web-server values via signed loopback.** ini values differ between CLI and FPM, so the
  command first asks the app's own web server: it mints
  `URL::temporarySignedRoute('voight.system-details.collect', now()->addMinute(), absolute: false)`
  and GETs it against `push.loopback_url` (default `app.url`, timeout 10). The route
  (`routes/api.php`, `GET /api/voight/system-details/collect`) returns `SystemDetailsCollector::collect()` as JSON, so the
  snapshot comes from the FPM process. Non-2xx, connection error or a body without `server`
  prints one warning line and falls back to CLI collection; `server.php.sapi` then reads
  `cli`, which the UI shows. In DDEV set `VOIGHT_PUSH_LOOPBACK_URL=http://localhost` (the
  container does not trust the local certificate).
- Security of the collect route: relative signature (`ValidateSignature::relative()`, covers
  path + query, so the loopback host may differ from the app URL), one-minute expiry,
  `throttle:6,1`, GET only. It sits outside the `web` group on purpose (host apps attach
  session, dev-lock and localization middleware there). It exposes nothing beyond what
  the push already sends to Voight, and only to a holder of a valid signature, which needs
  `APP_KEY`.
- `Http::withToken($token)->acceptJson()->timeout(10)->post($url, $payload)`.
  Non-2xx or connection failure: print the status/message, exit 1. Never throw a
  stack trace into a deploy log.

Composer and npm package lists are **not** sent: Voight already has them from
the lockfile sync.

### When it runs

- **After deploy:** add a Deployer task `artisan:voight:push-system-details` after
  `deploy:symlink` (documented in README; not shipped as a recipe).
- **Daily:** the service provider schedules the command daily when `push.base_url`
  is set, so disk numbers do not go stale between deploys.

---

## Voight side: `laravel-filament-voight`

### Data model: `voight_environment_system_details`

Migration stub `create_voight_environment_system_details_table.php.stub`
(replaces the earlier add-columns migration; `voight_environments` is untouched):

| Column | Type | Notes |
|---|---|---|
| `id` | ulid | |
| `environment_id` | foreign ulid, unique | cascade on delete; one row per environment |
| `php_version` | string, nullable, indexed | copied from `server.php.version`; used for the filter. Laravel/Filament/Livewire versions are not columns: they come from the environment's last lockfile scan (still present in `payload`) |
| `payload` | `json` | Full validated payload |
| `collected_at` | timestamp, nullable | Client clock |
| `received_at` | timestamp | Set by Voight on receipt |
| timestamps | | |

Model `EnvironmentSystemDetail` (configurable via `models.environment_system_detail`,
factory included); `Environment::systemDetails()` is a `hasOne`.

### Endpoint

`POST /api/voight/system-details` in `routes/api.php`, same middleware group as
`lock-file` (`AuthenticateProjectToken`).

`StoreSystemDetailsRequest`:

```php
'environment' => ['required', 'string', 'max:255'],
'collected_at' => ['required', 'date'],
'app' => ['nullable', 'array'],
'app.environment' => ['nullable', 'string', 'max:255'],
'app.debug_mode' => ['nullable', 'boolean'],
'framework' => ['nullable', 'array'],
'framework.name' => ['nullable', 'string', 'max:255'],
'framework.version' => ['nullable', 'string', 'max:255'],
'framework.groups' => ['nullable', 'array'],
'server' => ['required', 'array'],
```

`SystemDetailsController::store()`:

1. Project = `$request->attributes->get('voight_project')` (always set by the
   middleware). No `project_code` field: the token already names the project, so
   a token cannot write to another project.
2. Environment = `LockFileSyncService::resolveEnvironment()`, made public. Same
   `firstOrCreate` + `EnvironmentCreatedViaApi` event as the lockfile sync.
3. `$environment->systemDetails()->updateOrCreate([], [...])`: `php_version` from `server.php.version`, `payload` = validated data, `collected_at`, `received_at = now()`.
4. Return `204`.

Synchronous: one row update, no job.

Payload size: reject bodies over 256 KB (`$request->getContent()` length check
in the request's `prepareForValidation`, 413). Real payloads are ~5 KB.

### Filament

- **`EnvironmentResource`**: nested under `ProjectResource`, view page only, at
  `/projects/{project}/environments/{environment}`. Header subheading shows "Last reported"
  (relative plus exact time) and a "Copy as Markdown" header action (clipboard via
  `alpineClickHandler`, Markdown built server-side by `SystemDetailsPresenter::markdown()`
  and passed through `Js::from()`). Infolist: a row of compact stat cards (PHP, Database "mysql 8.0.46", Environment and Debug mode badge, both from `app.*`; monospace values
  with icons), then `Tabs` with icons: "Framework" (tab labelled by `framework.name` + `framework.version`, e.g. "Laravel 12.1.0" or "Craft 5.4.0", generic "Framework" when absent; a two-column grid of `Section`s, one per
  `framework.groups` entry, each with a heading icon), "Server" (two independent columns at `lg`, one column on mobile: PHP on the left; System, Deployment, Disk, then unknown groups stacked on the right; Database is shown only as the stat card, but stays in the Markdown export) and "Extensions" (badge = count, one badge per extension, sorted by name case-insensitively; "name version" only when the
  extension has its own version, the name alone when the version is null or equals the PHP
  version; a leading "name " in the version, as mysqlnd reports it, is stripped), preceded by a small muted explanation of why only some badges show a version. Rows are
  inline label / monospace value; booleans are Yes (success) / No (gray) badges, `*_bytes`
  values human-readable, `load_average` as "0.08 / 0.09 / 0.08". Labels come from the
  `system_details.labels` translations with `Str::headline` as fallback for plain identifier
  keys; other keys, such as storage paths, are shown verbatim, so keys from newer
  clients need no Voight change. Without a snapshot a single empty-state text replaces
  the cards and tabs.
- **`EnvironmentsRelationManager`**: rows link to the view page; columns
  "PHP" and "Server reported" come from the eager-loaded `systemDetails`. The
  slide-over action is gone.
- **Environments list** (`ListEnvironments` page, navigation group Management):
  project, environment (shown as badge), PHP version, received since; multi-select filter on
  environment name (options = distinct names) and select filter on
  PHP version (options = distinct stored values); rows link to the view page. Access
  and query scope follow `ProjectResource` (`canViewAny()` / `getEloquentQuery()`).
  Defaults: ternary filter `server_reported` on (only environments with a snapshot),
  sorted by most recent report first, name as tie-breaker.

---

## Testing

**voight** (`tests/Feature/PushSystemDetailsTest.php`):
- No `push.base_url`: command exits 0, `Http::assertNothingSent()`.
- Configured: posts to the URL with bearer token; payload has `environment`,
  `collected_at`, `app`, `framework`, `server.php`.
- `push.environment` / `--environment` override `app()->environment()`.
- Receiver returns 401: command exits 1, prints status.
- `http://` URL to a non-local host: exits 1, nothing sent.
- Schedule registered only when `push.base_url` is set.

**voight** (`tests/Feature/SystemDetailsEndpointTest.php`):
- No token / user token instead of project token: 401.
- Valid push stores the snapshot, indexed PHP version and `received_at` on the token's
  project environment.
- Second push overwrites the first.
- Unknown environment name creates the environment and dispatches
  `EnvironmentCreatedViaApi`.
- Missing `server`: 422. Oversized body: 413.

**voight** Filament (`EnvironmentSystemDetailsTest`):
- View page renders snapshot (stack summary, database/disk lines, extensions tab, unknown
  groups in Other, framework fieldsets, a non-Laravel framework tab title) and the placeholder without a snapshot.
- Relation manager shows the PHP version.
- Global list shows reported environments by default (newest first), all with the filter cleared, and filters by PHP version and environment name.

---

## Docs

- `README.md`: endpoint in API reference; `voight:push-system-details` (config,
  Deployer task, schedule), noting that the same `VOIGHT_API_BASE_URL` /
  `VOIGHT_API_TOKEN` as `voight.sh` are reused. `CHANGELOG.md` entry.

---

## Decisions

| Date | Decision | Why | Who |
|---|---|---|---|
| 2026-10-06 | Send a minimal server snapshot plus `about --json` | Useful for ops; internal app behind token auth | Kristof |
| 2026-10-06 | Daily schedule on by default when `push.base_url` is set | Load/disk/queue data stays fresh between deploys | Kristof |
| 2026-10-06 | No HMAC payload signing; command refuses a non-`https` `push.base_url` instead | TLS already encrypts and protects integrity; HMAC does not encrypt, and Sanctum stores only the token hash, so signing would need a derived key scheme. Same trust model as the lock-file endpoint. Revisit if TLS terminates on an untrusted hop | Kristof |
| 2026-10-06 | Push reuses VOIGHT_API_BASE_URL / VOIGHT_API_TOKEN from voight.sh | The push cannot work without a Voight project token anyway; one set of vars per project | Kristof |
| 2026-10-06 | Snapshot moved to `voight_environment_system_details` with indexed versions; slide-over replaced by nested environment view page; global Environments list with version filters | Filter/find projects by stack versions, cleaner UI | Kristof |
| 2026-10-06 | push command moved into voight package as voight:push-system-details | keep everything in one package | Kristof |

---

## Out of scope

- History / timeline of server changes.
- Alerts on server data (e.g. PHP EOL, disk > 90%, snapshot older than N days).
  Natural follow-up via `EnvironmentHealthService` (spec 14).
- Pulling from Voight into client apps.

---

## Change Log

| Date | Change | Why | Who |
|---|---|---|---|
| 2026-10-06 | snapshot moved to voight_environment_system_details table with indexed php/laravel/filament/livewire versions; slide-over replaced by nested environment view page; global Environments list with version filters | filter/find projects by stack versions, cleaner UI | Kristof |
| 2026-10-06 | view page redesigned (stack summary + tabs), CLI-only php ini values dropped from payload | CLI values misleading, page cluttered | Kristof |
| 2026-10-06 | signed loopback for web-server values, richer server data, view restyled like the old system-details package with Copy as Markdown | CLI values misleading; keep familiar UI | Kristof |
| 2026-10-06 | loopback route moved to /api/voight/system-details/collect in routes/api.php | keep all package routes under api/voight | Kristof |
| 2026-10-06 | Environments list defaults to reported environments, newest report first | user request | Kristof |
| 2026-10-15 | Dropped laravel/filament/livewire version columns, only php_version kept | already available from last lockfile scan | Kristof |
| 2026-10-15 | PHP extensions sent as name => version map, shown as "name version" | user request | Kristof |
| 2026-10-15 | Environments list: name shown as badge + name filter | user request | Kristof |
| 2026-10-15 | Extension badges sorted by name, version only shown when it differs from PHP; non-identifier label keys (storage paths) shown verbatim, with explanatory text on the tab | badges unreadable; paths were title-cased | Kristof |
| 2026-10-15 | "Laravel" tab renamed to "Framework" | other frameworks (e.g. Craft CMS) may push data later | Kristof |
| 2026-10-15 | Server tab: Database section dropped (stat card shows it), PHP left, other groups stacked right, active tab persisted in query string | duplicate info, cleaner layout | Kristof |
| 2026-10-15 | Payload made framework-agnostic: app {environment, debug_mode}, framework {name, version, groups}; versions block dropped, php_version from server.php.version; Framework tab titled by framework name + version | Craft CMS and other frameworks will push system details | Kristof |
