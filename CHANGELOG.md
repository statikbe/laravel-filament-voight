# Changelog

All notable changes to `laravel-filament-voight` will be documented in this file.

## Unreleased

### Changed 2026-10-15
- Environments can receive a server snapshot (PHP, OS, database, Laravel `about`) via `POST /api/voight/system-details`. The snapshot is stored in the new `voight_environment_system_details` table with indexed PHP / Laravel / Filament / Livewire versions; publish and run the `create_voight_environment_system_details_table` migration.
- `voight:push-system-details` collects PHP/server values through a signed loopback request to the app's own web server (`push.loopback_url` / `VOIGHT_PUSH_LOOPBACK_URL`), falling back to the CLI, and reports richer server data (limits, php.ini, Xdebug/OPcache, load, CPU cores, commit, PHP user).
- New environment detail page (nested under the project) and a global Environments list with version filters. The page shows stat cards, Laravel / Server / Extensions tabs and a Copy as Markdown action.
- New `voight:push-system-details` command (scheduled daily when `VOIGHT_API_BASE_URL` is set) pushes that snapshot from the monitored app, using the new `push` config section.
### Changed
- **Breaking:** OSV scans are asynchronous. Requires the matching
  `voight-osv-scanner-lambda` release; `VOIGHT_SCANNER_URL` must point to the
  lambda's `ScansUrl` output (`…/scans`) and `VOIGHT_SCANNER_PACKAGES_URL` is removed.
  A queue worker is required.
- A failed nightly batch is retried on its own instead of re-running the whole sweep.
- A failing scan records one failed audit run instead of one per attempt.

### Added
- `voight_osv_scans` and `voight_nightly_sweeps` tables (publish and run migrations).
- Signed scanner callback route `voight.scans.callback`
  (`VOIGHT_SCANNER_CALLBACK_SECRET` must equal the lambda's `CALLBACK_SECRET`, min 32
  characters; `VOIGHT_SCANNER_CALLBACK_URL` optional, non-https URLs disable callbacks).

## 1.0.0 - 202X-XX-XX

- initial release
