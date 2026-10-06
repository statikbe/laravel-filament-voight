# Environment System Details

**Date:** 2026-10-06
**Status:** approved
**Related:** `03-dependency-sync.md` (same token + environment resolution), `14-environment-health.md` (same relation manager)

Show on the project page which server each environment runs on: PHP version and
extensions, OS, database, queue, disk, opcache, deployed commit, Laravel `about`
output. Data comes from `statikbe/laravel-filament-system-details`, which the
client app already has installed.

---

## Problem

Voight knows each environment's dependencies but nothing about the server under
it. To answer "which projects still run PHP 8.2?" or "is production on the
commit we think it is?" someone has to log in to the client app's admin panel or
SSH into the box.

The system-details package already collects all of this (`ServerInfo::all()`,
`AboutReader::sections()`), but only shows it inside the client app.

---

## Approach

**Push from the client server, latest snapshot only.**

- Lockfiles are pushed from CI (`voight.sh`), but CI is not the server. Server
  details must be collected by the running app, so the push lives in the
  system-details package as an artisan command.
- Voight never calls client apps. No inbound endpoint or extra auth on every
  client server; the existing project token is reused.
- Voight stores one JSON snapshot per environment and overwrites it on each push.
  No history table. Trade-off: no "PHP was upgraded on date X" timeline. Add a
  table when that is asked for.
- The system-details package stays generic: it posts to a configured URL with a
  bearer token and knows nothing about Voight models. No composer dependency in
  either direction.

```
client server: php artisan system-details:push
      → POST {voight}/api/voight/system-details  (Bearer project token)
      → voight_environments.system_details (JSON, overwritten)
      → Project view → Environments relation manager → "System details" modal
```

---

## Client side: `laravel-filament-system-details`

### Config

Add to `config/filament-system-details.php`:

```php
'push' => [
    // Base URL of Voight (no path); posts to {base_url}/api/voight/system-details. null = push disabled.
    // Same vars as voight.sh.
    'base_url' => env('VOIGHT_API_BASE_URL'),
    'token' => env('VOIGHT_API_TOKEN'),
    // Name the receiver files the snapshot under; null = app()->environment().
    'environment' => env('SYSTEM_DETAILS_PUSH_ENVIRONMENT'),
],
```

The environment name must match what `voight.sh` sends (`APP_ENV`), or the
snapshot lands on a second environment record.

### `PushSystemDetails` command (new)

`php artisan system-details:push`

- Exits 0 with a one-line notice when `push.base_url` is null, so it is safe in a
  deploy recipe on every project.
- Exits 1 when `push.base_url` is not `https://`, except for `localhost` /
  `127.0.0.1` hosts (local testing). The token never travels in clear text.
- Forces English for the snapshot (`app()->setLocale('en')` around collection):
  `ServerInfo` values are translated and cached per locale.
- Bypasses the cache for `server` so the snapshot reflects the server now, not up
  to an hour ago.
- Payload (JSON):

  ```json
  {
    "environment": "production",
    "collected_at": "2026-10-06T09:00:00+00:00",
    "server": { "php": {}, "system": {}, "deployment": {}, "writable": {}, "database": {}, "queue": {}, "disk": {} },
    "laravel": { "environment": {}, "cache": {}, "drivers": {} }
  }
  ```

  `server` = `ServerInfo::all()`, `laravel` = `AboutReader::sections()` (with
  `hidden_sections` applied, same as the Laravel tab).
- `Http::withToken($token)->acceptJson()->timeout(10)->post($url, $payload)`.
  Non-2xx: print the status, exit 1. Never throw a stack trace into a deploy log.

Composer and npm package lists are **not** sent: Voight already has them from
the lockfile sync.

### When it runs

- **After deploy:** add a Deployer task `artisan:system-details:push` after
  `deploy:symlink` (documented in README; not shipped as a recipe).
- **Daily:** the service provider schedules the command daily when `push.base_url`
  is set, so load, disk and queue numbers do not go stale between deploys.

---

## Voight side: `laravel-filament-voight`

### Data model: `voight_environments`

Migration stub `add_system_details_to_voight_environments_table.php.stub`:

| Column | Type | Notes |
|---|---|---|
| `system_details` | `json`, nullable | Last received payload: `{server, laravel, collected_at}` |
| `system_details_received_at` | `timestamp`, nullable | Set by Voight on receipt |

`Environment` casts: `system_details` → `array`, `system_details_received_at` →
`datetime`. Add both to the `@property` block.

### Endpoint

`POST /api/voight/system-details` in `routes/api.php`, same middleware group as
`lock-file` (`AuthenticateProjectToken`).

`StoreSystemDetailsRequest`:

```php
'environment' => ['required', 'string', 'max:255'],
'collected_at' => ['required', 'date'],
'server' => ['required', 'array'],
'laravel' => ['nullable', 'array'],
```

`SystemDetailsController::store()`:

1. Project = `$request->attributes->get('voight_project')` (always set by the
   middleware). No `project_code` field: the token already names the project, so
   a token cannot write to another project.
2. Environment = `LockFileSyncService::resolveEnvironment()`, made public. Same
   `firstOrCreate` + `EnvironmentCreatedViaApi` event as the lockfile sync.
3. `$environment->update(['system_details' => [...validated], 'system_details_received_at' => now()])`.
4. Return `204`.

Synchronous: one row update, no job.

Payload size: reject bodies over 256 KB (`$request->getContent()` length check
in the request's `prepareForValidation`, 413). Real payloads are ~5 KB.

### Filament: `EnvironmentsRelationManager`

- **Column** `system_details_received_at`: label "Server reported", `since()`,
  placeholder "Never". Tooltip: PHP version + OS from the snapshot.
- **Record action** `systemDetails` (icon `Heroicon::ServerStack`), hidden when
  `system_details` is null. Opens a slide-over with a read-only infolist:
  - Header line: collected at, received at.
  - One `Section` per `server` key (php, system, deployment, writable, database,
    queue, disk) and per `laravel` key, collapsible, `laravel` sections collapsed.
  - Each section is a `KeyValueEntry`; keys shown with `Str::headline()`,
    booleans shown as Yes/No.
  - Unknown keys render as-is, so new fields from a newer system-details version
    need no Voight change.

Translations in `lang/en` and `lang/nl` for the column, action and modal
headings only; snapshot keys are not translated.

---

## Testing

**system-details** (`tests/Feature/PushSystemDetailsTest.php`):
- No `push.base_url`: command exits 0, `Http::assertNothingSent()`.
- Configured: posts to the URL with bearer token; payload has `environment`,
  `collected_at`, `server.php`, `laravel`.
- `push.environment` overrides `app()->environment()`.
- Receiver returns 401: command exits 1, prints status.
- Payload values are English with the app locale set to `nl`.
- `http://` URL to a non-local host: exits 1, nothing sent.
- Schedule registered only when `push.base_url` is set.

**voight** (`tests/Feature/SystemDetailsEndpointTest.php`):
- No token / user token instead of project token: 401.
- Valid push stores snapshot and `system_details_received_at` on the token's
  project environment.
- Second push overwrites the first.
- Unknown environment name creates the environment and dispatches
  `EnvironmentCreatedViaApi`.
- Missing `server`: 422. Oversized body: 413.

**voight** Filament (`EnvironmentsRelationManager` test):
- Action hidden for an environment without snapshot.
- Action shows PHP version and a `laravel` section for one with a snapshot.

---

## Docs

- system-details `README.md`: "Push to a central dashboard" section (config,
  Deployer task, schedule). `CHANGELOG.md` entry.
- voight `README.md`: endpoint in API reference; how to set
  note that the same `VOIGHT_API_BASE_URL` / `VOIGHT_API_TOKEN` as `voight.sh`
  are reused, nothing extra to set.

---

## Decisions

| Date | Decision | Why | Who |
|---|---|---|---|
| 2026-10-06 | Send everything `ServerInfo` and `about` show (minus `hidden_sections`) | Useful for ops; internal app behind token auth | Kristof |
| 2026-10-06 | Daily schedule on by default when `push.base_url` is set | Load/disk/queue data stays fresh between deploys | Kristof |
| 2026-10-06 | No HMAC payload signing; command refuses a non-`https` `push.base_url` instead | TLS already encrypts and protects integrity; HMAC does not encrypt, and Sanctum stores only the token hash, so signing would need a derived key scheme. Same trust model as the lock-file endpoint. Revisit if TLS terminates on an untrusted hop | Kristof |
| 2026-10-06 | Push reuses VOIGHT_API_BASE_URL / VOIGHT_API_TOKEN from voight.sh | The push cannot work without a Voight project token anyway; one set of vars per project | Kristof |

---

## Out of scope

- History / timeline of server changes.
- Alerts on server data (e.g. PHP EOL, disk > 90%, snapshot older than N days).
  Natural follow-up via `EnvironmentHealthService` (spec 14).
- Cross-project search ("all environments on PHP 8.2"). JSON column allows it
  later with `whereJsonContains` / a generated column.
- Pulling from Voight into client apps.
