# Occurrence-Based Notifications (Flare-style)

## Context

Flare notifies you at occurrence milestones (1, 10, 100, 1000) instead of every single occurrence — preventing fatigue while keeping you informed at meaningful thresholds. This app already has an `AlertSetting` model with `severity_threshold` and `frequency` (immediate/daily/weekly), but no notification sending logic exists yet. This plan adds occurrence-based notifications as a new trigger type alongside the existing time-based approach.

**"Occurrence"** in this context = number of completed audit runs for a project where a specific vulnerability+package combination appeared as an `AuditFinding`.

---

## How it works (concept)

1. Every time an audit scan completes, count how many times each detected vulnerability has appeared across all historical audit runs for that project.
2. For each occurrence-based `AlertSetting`, check if any configured threshold (e.g. 1, 10, 100, 1000) has been crossed for the first time.
3. Send a notification and log it — so the same threshold never fires twice for the same vulnerability+project combination.

---

## Implementation

### 1. New `AlertNotificationType` enum

**Create:** `src/Enums/AlertNotificationType.php`

`TimeBased` | `OccurrenceBased` — added as a new column on `AlertSetting` (not replacing `AlertFrequency`).

---

### 2. Database changes

**Create:** `database/migrations/add_notification_type_to_voight_alert_settings_table.php.stub`

Adds to `voight_alert_settings`:
- `notification_type` string, default `'time_based'`
- `occurrence_thresholds` JSON, nullable (defaults to `[1, 10, 100, 1000]` in code when null)
- `email` string, nullable — recipient address for Email channel (currently missing)
- `frequency` made nullable (only required for time-based settings)

**Create:** `database/migrations/create_voight_alert_notification_logs_table.php.stub`

New `voight_alert_notification_logs` table:
```
id ulid PK
alert_setting_id  FK → voight_alert_settings (cascade delete)
vulnerability_id  FK → voight_vulnerabilities (cascade delete)
package_id        FK → voight_packages (cascade delete)
threshold         unsignedInteger  (which milestone fired: 1/10/100/1000)
notified_at       timestamp
timestamps
UNIQUE(alert_setting_id, vulnerability_id, package_id, threshold)
```

Register both in `FilamentVoightServiceProvider::getMigrations()`.

---

### 3. Event dispatch in `RunOsvScanJob`

**Modify:** `src/Jobs/RunOsvScanJob.php` — add one line after line 93:
```php
OsvScanCompleted::dispatch($auditRun);
```

**Create:** `src/Events/OsvScanCompleted.php` — simple event with `public AuditRun $auditRun`.

---

### 4. Occurrence counting service

**Create:** `src/Services/OccurrenceCountService.php`

Single method: `getCountsForProject(Project $project, Collection $findings): array<string, int>`

Runs one `GROUP BY` query to count distinct completed audit runs per `vulnerability_id + package_id` pair scoped to the project:
```sql
SELECT af.vulnerability_id, af.package_id, COUNT(DISTINCT ar.id) as occurrence_count
FROM voight_audit_findings af
JOIN voight_audit_runs ar ON ar.id = af.audit_run_id
JOIN voight_environments e ON e.id = ar.environment_id
WHERE e.project_id = :project_id
  AND ar.status = 'completed'
  AND (af.vulnerability_id, af.package_id) IN (...)
GROUP BY af.vulnerability_id, af.package_id
```

Returns map keyed `"vuln_id:pkg_id"` for O(1) lookup.

---

### 5. Notification infrastructure

**Create:** `src/Notifications/AlertSettingNotifiable.php`

A thin value object (not a User model) that uses Laravel's `Notifiable` trait and provides routing for mail (`alertSetting->email`) and Slack (`alertSetting->webhook_url`).

**Create:** `src/Notifications/VulnerabilityThresholdNotification.php`

Constructor receives: `AlertSetting`, `Vulnerability`, `Package`, `int $threshold`, `int $occurrenceCount`. Routes via `via()` to `mail` or `slack` based on `alertSetting->channel`. Implements `toMail()` with vulnerability summary.

> **Note:** Slack requires `laravel/slack-notification-channel` — not currently in `composer.json`. Implement `toMail()` first; stub `toSlack()` and flag as needing the dependency.

---

### 6. Queued listener

**Create:** `src/Listeners/SendOccurrenceNotificationsListener.php` — implements `ShouldQueue`

Logic:
1. Load project via `$event->auditRun->environment->project`, bail if `is_muted`
2. Load enabled `OccurrenceBased` `AlertSetting` records for the project
3. Load `AuditFinding` records for this audit run (with `vulnerability` and `package` eager-loaded)
4. Call `OccurrenceCountService::getCountsForProject()` — one query
5. Load all existing `AlertNotificationLog` records for these settings × pairs — one query, build a lookup set
6. For each `AlertSetting` × each finding: check which thresholds are crossed but not yet logged; also filter by `severity_threshold`
7. Send `VulnerabilityThresholdNotification` for each crossing
8. Bulk insert new log records

Register in `FilamentVoightServiceProvider::packageBooted()`:
```php
Event::listen(OsvScanCompleted::class, SendOccurrenceNotificationsListener::class);
```

---

### 7. Supporting model

**Create:** `src/Models/AlertNotificationLog.php` — `HasUlids`, `voight_alert_notification_logs` table, `belongsTo` AlertSetting / Vulnerability / Package.

**Modify:** `src/Models/AlertSetting.php` — add `notification_type`, `occurrence_thresholds`, `email` to casts and PHPDoc; add `hasMany(AlertNotificationLog::class)`.

---

### 8. Filament UI

**Modify:** `src/Resources/ProjectResource/RelationManagers/AlertSettingsRelationManager.php`

Form additions:
- `Select` for `notification_type` using `AlertNotificationType::options()`
- `TextInput` for `email` (visible when channel = Email)
- `TagsInput` or `TextInput` for `occurrence_thresholds` (visible when `notification_type = OccurrenceBased`)
- `frequency` field made conditional: only required/visible when `notification_type = TimeBased`

Table: add `notification_type` column.

---

### 9. Translations

**Modify:** `resources/lang/en/filament-voight.php` — add keys for:
- `enums.alert_notification_type.time_based` / `.occurrence_based`
- `models.alert_setting.fields.*` for the new columns
- `notifications.vulnerability_threshold.*` for mail content

---

## Files to create
- `src/Enums/AlertNotificationType.php`
- `src/Events/OsvScanCompleted.php`
- `src/Listeners/SendOccurrenceNotificationsListener.php`
- `src/Models/AlertNotificationLog.php`
- `src/Notifications/AlertSettingNotifiable.php`
- `src/Notifications/VulnerabilityThresholdNotification.php`
- `src/Services/OccurrenceCountService.php`
- `database/migrations/add_notification_type_to_voight_alert_settings_table.php.stub`
- `database/migrations/create_voight_alert_notification_logs_table.php.stub`

## Files to modify
- `src/Jobs/RunOsvScanJob.php` (dispatch event after line 93)
- `src/Models/AlertSetting.php` (casts + relationship)
- `src/FilamentVoightServiceProvider.php` (register migrations + listener)
- `src/Resources/ProjectResource/RelationManagers/AlertSettingsRelationManager.php` (form + table)
- `resources/lang/en/filament-voight.php` (translations)

---

## Verification

1. Run `php artisan migrate` — confirm new columns on `voight_alert_settings` and new `voight_alert_notification_logs` table
2. Create an `AlertSetting` with `notification_type = occurrence_based`, thresholds `[1, 10, 100, 1000]`
3. Trigger `RunOsvScanJob` via `php artisan voight:run-osv-scan` or tinker
4. Confirm `OsvScanCompleted` event is dispatched (add logging temporarily)
5. Confirm `voight_alert_notification_logs` gets a row for `threshold = 1` after first scan
6. Run again — confirm the `threshold = 1` row is NOT duplicated
7. Confirm email is sent (use Mailpit or similar in local dev)