# Dependency Sync

## Overview

Projects push their lockfiles to the application via an authenticated API. The system parses them and stores the full dependency tree.

## Sync Flow

1. A CI/CD script or manual trigger sends lockfiles as file uploads to the API.
2. The `LockFileController` validates the request via `SyncLockFileRequest` and delegates to `LockFileSyncService`.
3. `LockFileSyncService` resolves (or auto-creates) the Project and Environment:
   - If the project doesn't exist, it is created with just the `project_code` and a `ProjectCreatedViaApi` event is dispatched.
   - If the environment doesn't exist, it is created and an `EnvironmentCreatedViaApi` event is dispatched.
4. Lockfiles are stored on the configured disk, a `DependencySync` record is created with status `pending`, and `ProcessLockFilesJob` is dispatched.
5. The queued job processes the lockfiles:
   - Parses `composer.lock` and/or `package-lock.json` (or `yarn.lock`, `pnpm-lock.yaml`).
   - Extracts all packages with versions, direct/dev flags, and dependency relationships.
   - Upserts `Package` records (name + type).
   - Replaces `EnvironmentPackage` records for the environment (full snapshot, not diff).
   - Updates sync status to `completed` or `failed`.

## API Endpoints

### POST /api/voight/lock-file

Authenticated via project-scoped Sanctum tokens (M2M). The middleware is configurable via `FilamentVoightConfig::getApiMiddleware()` and defaults to `AuthenticateProjectToken`, which validates the bearer token against Sanctum's `personal_access_tokens` table and ensures it belongs to a `Project` model.

**Request:** `multipart/form-data`

| Field          | Type   | Notes                              |
|----------------|--------|------------------------------------|
| project_code   | string | required                           |
| environment    | string | required                           |
| lockfiles[]    | file   | required, one or more file uploads |

**Response (202 Accepted):**

```json
{
  "sync_id": "01HXY...",
  "status": "pending"
}
```

There is no sync status endpoint — processing is fire-and-forget from the client's perspective.

## Lockfile Storage

Lockfiles are stored on a configurable disk for later use by osv-scanner and auditing jobs.

- Disk name is configurable via `FilamentVoightConfig::getLockfilesDisk()` (default: `voight-lockfiles`).
- Default driver: `local`, root: `storage/app/private/voight/lockfiles`.
- Directory structure: `{project_code}/{environment}/` — e.g. `my-project/production/composer.lock`.
- Files are overwritten on each sync (only latest version kept).
- Relative paths stored in `DependencySync.lockfile_paths` (JSON array) for traceability.
- Allowed lockfile names are configurable via `FilamentVoightConfig::getAllowedLockfileNames()` (defaults: `composer.lock`, `package-lock.json`, `yarn.lock`, `pnpm-lock.yaml`).
- The osv-scanner audit job reads lockfiles from this disk to perform scanning.

## Lockfile Parsing

- **composer.lock**: Extract `packages` and `packages-dev` arrays. Each entry has `name`, `version`, and `require` (for tree relationships).
- **package-lock.json**: Extract from `packages` object. Use `dev` flag and `dependencies` for tree.
- Deduplication: same package at same version across trees is one `Package` record.
- Lockfile hash stored to skip processing if nothing changed.

## Versioning History

- Each sync replaces the full set of `EnvironmentPackage` records for that environment.
- Previous state is captured by the `DependencySync` record timestamp.
- Future: consider a `package_version_history` table for change tracking over time.

## Latest Version Tracking

- Background job periodically fetches latest versions from Packagist API and npm registry.
- Updates `Package.latest_version` and `latest_version_updated_at`.
- Used to flag outdated dependencies in the UI.
