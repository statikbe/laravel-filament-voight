# Changelog

All notable changes to `laravel-filament-voight` will be documented in this file.

## Unreleased

- Environments can receive a server snapshot (PHP, OS, database, Laravel `about`) via `POST /api/voight/system-details`. The snapshot is stored in the new `voight_environment_system_details` table with indexed PHP / Laravel / Filament / Livewire versions; publish and run the `create_voight_environment_system_details_table` migration.
- `voight:push-system-details` collects PHP/server values through a signed loopback request to the app's own web server (`push.loopback_url` / `VOIGHT_PUSH_LOOPBACK_URL`), falling back to the CLI, and reports richer server data (limits, php.ini, Xdebug/OPcache, load, CPU cores, commit, PHP user).
- New environment detail page (nested under the project) and a global Environments list with version filters. The page shows stat cards, Laravel / Server / Extensions tabs and a Copy as Markdown action.
- New `voight:push-system-details` command (scheduled daily when `VOIGHT_API_BASE_URL` is set) pushes that snapshot from the monitored app, using the new `push` config section.

## 1.0.0 - 202X-XX-XX

- initial release
