# Alerting

## Overview

Notification system for security vulnerabilities and dependency issues using Laravel's notification system. Configuration inspired by Flare.

## Channels

- **Email**: via Laravel Mail.
- **Slack**: via Slack webhook URL.
- Future: MS Teams, custom webhooks.

## Alert Triggers

1. **New vulnerability found** — after an audit discovers a new finding.
2. **Vulnerability severity upgrade** — when a known vulnerability's severity increases.
3. **Dependency sync failure** — when a sync job fails.
4. **Combell detection failure** — when a Combell check returns `fail`.

## Configuration

### Global Settings

- Default severity threshold: minimum severity level to trigger alerts.
- Default frequency: `immediate`, `daily` (digest), `weekly` (digest).
- Default channels.

### Per-Project Overrides

- Override severity threshold.
- Override frequency.
- Override channels.
- Mute project entirely (`Project.is_muted`).

### Frequency Behavior

- **Immediate**: notification sent as soon as the finding is created.
- **Daily**: digest email/message sent at configured time (e.g. 09:00 UTC).
- **Weekly**: digest sent on configured day.

## Notification Content

### Immediate Alert

- Vulnerability summary and severity.
- Affected package, installed version, fixed version.
- Project and environment.
- Link to Filament detail page.

### Digest

- Summary: X new vulnerabilities (Y critical, Z high, ...).
- Grouped by project, then by severity.
- Link to dashboard filtered view.

## Muting

- Global mute on project level (`Project.is_muted`).
- When muted, no notifications are sent for that project.
- Muted projects still appear in audit results and search — muting only affects notifications.
- Mute/unmute via Filament action.

## Flare-Inspired Configuration UI

- Filament settings page for global alert configuration.
- Per-project notification settings as a relation manager on the project resource.
- Test notification button to verify channel configuration.
