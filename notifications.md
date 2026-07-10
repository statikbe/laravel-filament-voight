# Alert Notifications (Slack + Email)

When a dependency scan finds vulnerabilities at or above a configured severity
threshold, Voight sends a summarized alert — with a deep link to the project —
over **Email** and/or **Slack**. Alerts are configured per project (or globally)
through the `AlertSetting` records managed on the Project's *Alert settings*
relation manager.

There are two delivery modes:

- **Immediate** — fired at the end of each scan, for settings with frequency
  `Immediate`.
- **Digest** — a scheduled `Daily`/`Weekly` summary of *currently outstanding*
  findings.

---

## Data model

| Model / column | Purpose |
| --- | --- |
| `AlertSetting` | One alert rule. `channel` (`email`/`slack`), `severity_threshold` (0–10), `frequency` (`immediate`/`daily`/`weekly`), `slack_channel` (nullable override), `is_enabled`, `last_sent_at`, `project_id` (nullable → **global**). |
| `AlertRecipient` | Polymorphic recipient row (`recipient_type`/`recipient_id`) linked to an `AlertSetting`. Points at a **User** or a **Team**. Email only. |

A setting with `project_id = null` is **global** and applies to every project.

Recipients are stored using morph **aliases** (`voight-user`, `voight-team`),
registered in `FilamentVoightServiceProvider::packageBooted()`. The host user
model is never hardcoded — it is resolved through
`FilamentVoight::config()->getUserModel()`
(`config('filament-voight.models.user')` → `auth.providers.users.model` →
framework base user).

`AlertSetting::resolveEmailRecipients()` expands the recipient rows into a
deduplicated collection of host user models that have an email address (teams
are expanded to their members, and users are re-queried through the resolved
user model so they are `Notifiable`).

---

## Components

All new sending-layer classes live in `src/Notifications/`.

### `AuditSummary` (DTO)

A `final readonly` value object holding everything a message needs: project
name/code, environment names, per-severity counts (zero buckets omitted),
total, the top 5 findings (by score), the absolute detail URL, and a timestamp.

Two named constructors:

- **`fromAuditRun(AuditRun $run, float $threshold)`** — findings from a single
  completed run whose vulnerability score `>= $threshold`. Used by immediate alerts.
- **`fromProjectOutstanding(Project $project, float $threshold)`** — the
  project's *current* outstanding findings: the latest run per environment
  (`AuditRun::latestIdsPerEnvironment()`), score-filtered. Used by digests.

Severity is always bucketed through the `Vulnerability::severity` accessor
(`Severity::fromScore()`) — the single source of truth. Scores are `decimal:1`
strings, so they are cast to `float` before comparison/sorting.

The detail link is built with
`ProjectResource::getUrl('view', ['record' => $project], isAbsolute: true, panel: getAlertsPanelId())`.
`isAbsolute` is required because the URL is generated from a queue/console
context with no incoming request. There is no AuditRun detail page, so all
links point at the Project view.

### Notifications

`AuditAlertNotification` (abstract) is extended by:

- `AuditRunSummaryNotification` — immediate (`langGroup() = 'audit_run_summary'`)
- `AuditDigestNotification` — digest (`langGroup() = 'audit_digest'`)

Both take an `AuditSummary` + an `AlertChannel`. `via()` maps the channel to
`['mail']` or `['slack']`.

- **`toMail()`** renders the shared markdown view
  `filament-voight::mail.audit-summary` (a severity table + top-findings list +
  a "View project" button). The from-address comes from
  `getAlertMailFrom()` when configured, otherwise the app default.
- **`toSlack()`** builds a Block Kit message: header, a markdown section with
  severity counts, a context line listing environments, and a primary button
  linking to the project.

> **These notifications are intentionally *not* `ShouldQueue`.** They are always
> sent from an already-off-request context (a queued job or a scheduled command)
> using `Notification::sendNow()`. Queuing them would only add a second,
> redundant queue hop per recipient.

### `AlertDispatcher`

The single place that turns an `AlertSetting` + notification into an actual send.
`send()` returns `true` only when a message was handed to a transport.

- **Email** → `resolveEmailRecipients()`; skips (logs a warning) if none resolve,
  otherwise `Notification::sendNow($recipients, $notification)`.
- **Slack** → resolves the channel as
  `slack_channel` → `getSlackDefaultChannel()` → `services.slack.notifications.channel`;
  skips (logs a warning) if none is set, otherwise sends to an **on-demand string
  route**: `Notification::sendNow(Notification::route('slack', $channel), $notification)`.
  A plain-string Slack route makes the channel resolve the **bot token
  automatically** from `services.slack.notifications.bot_user_oauth_token`, so the
  module never handles the token itself.

Slack posts to a channel and **ignores** user/team recipients.

---

## Triggers

### Immediate — `SendAuditAlertsJob`

`RunOsvScanJob::handle()` dispatches `SendAuditAlertsJob::dispatch($auditRun)`
immediately after the run is marked `Completed` (a failed scan never dispatches).

The job (mirroring the scan job's conventions — `tries=3`, `timeout=120`,
`backoff=[30,120,300]`, honoring `getAlertsQueue()`):

1. Skips entirely if the project `is_muted`.
2. Loads enabled `Immediate` settings matching this project **or** global
   (`project_id IS NULL`).
3. For each: builds an `AuditSummary` from the run, skips if no findings clear the
   threshold, sends via `AlertDispatcher`, and stamps `last_sent_at` on success.

A **global** immediate setting therefore fires once per completed scan.

### Digests — `voight:send-alert-digests`

Scheduled `->hourly()` in `packageBooted()`. Running hourly (rather than daily)
means a digest goes out within an hour of becoming due and missed ticks
self-heal.

For each enabled `Daily`/`Weekly` setting that `isDigestDue()`:

- `isDigestDue()` returns `true` when `last_sent_at` is null, or older than
  1 day (Daily) / 1 week (Weekly).
- Target projects = the setting's own project, or **all** non-muted projects for a
  global setting.
- Per project, builds an outstanding-findings summary; projects with zero
  findings above the threshold are skipped.
- `last_sent_at` advances **once per setting, only if at least one message was
  actually sent**.

### Muting

`Project.is_muted` suppresses *all* alerts for that project — enforced in both the
immediate job and the digest command (per `specs/06-alerting.md`).

---

## Configuration

Module config (`config/filament-voight.php` → `notifications`):

```php
'notifications' => [
    'slack_default_channel' => env('VOIGHT_SLACK_CHANNEL'),
    'mail_from_address'     => env('VOIGHT_ALERT_MAIL_FROM'),
    'mail_from_name'        => env('VOIGHT_ALERT_MAIL_FROM_NAME'),
    'panel_id'              => 'voight',
    'queue'                 => env('VOIGHT_ALERTS_QUEUE'),
],
```

The Slack **bot token** is *not* a module concern — it lives in the host app's
`config/services.php`:

```php
'slack' => [
    'notifications' => [
        'bot_user_oauth_token' => env('SLACK_BOT_USER_OAUTH_TOKEN'),
        'channel'              => env('SLACK_BOT_USER_DEFAULT_CHANNEL'),
    ],
],
```

**Host requirements:** a working mailer (Postmark transport is available), a bot
token + default channel for Slack, and a queue worker + scheduler running the
package commands.

---

## Admin UI

`AlertSettingsRelationManager` (on the Project view/edit page):

- `channel` select is `live()`, so channel-dependent fields update instantly.
- **Slack:** a `slack_channel` text input (placeholder = the resolved config
  default). The legacy `webhook_url` field is gone (the column is retained).
- **Email:** two searchable multi-selects — `recipient_users` and
  `recipient_teams` — backed by Filament's `loadStateFromRelationshipsUsing()` /
  `saveRelationshipsUsing()` hooks, which sync the polymorphic `AlertRecipient`
  rows (delete-missing + `firstOrCreate`, honoring the unique constraint).
- Table columns include `slack_channel`, a recipients count, and `last_sent_at`.

---

## Files

| Path | Role |
| --- | --- |
| `src/Notifications/AuditSummary.php` | Message DTO + builders |
| `src/Notifications/AuditAlertNotification.php` | Abstract notification (mail + slack) |
| `src/Notifications/AuditRunSummaryNotification.php` | Immediate notification |
| `src/Notifications/AuditDigestNotification.php` | Digest notification |
| `src/Notifications/AlertDispatcher.php` | Channel resolution + send |
| `src/Jobs/SendAuditAlertsJob.php` | Immediate trigger (from scan) |
| `src/Commands/SendAlertDigestsCommand.php` | `voight:send-alert-digests` |
| `src/Models/AlertSetting.php` | `isDigestDue()`, `resolveEmailRecipients()` |
| `src/Models/AlertRecipient.php` | Polymorphic recipient |
| `resources/views/mail/audit-summary.blade.php` | Shared markdown mail view |
| `src/Resources/ProjectResource/RelationManagers/AlertSettingsRelationManager.php` | Admin UI |

Tests live under `tests/Notifications/`, `tests/Jobs/`, `tests/Commands/`, and
`tests/Feature/Resources/ProjectResource/`.
