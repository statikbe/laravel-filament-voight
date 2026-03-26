# Dependency Sync

## Overview

Projects push their lockfiles to the application via an authenticated API. The system parses them and stores the full dependency tree.

## Sync Flow

1. A CI/CD script or manual trigger sends lockfiles to the API.
2. The API validates the payload, creates a `DependencySync` record with status `pending`.
3. A queued job processes the lockfiles:
   - Parses `composer.lock` and/or `package-lock.json` (or `yarn.lock`, `pnpm-lock.yaml`).
   - Extracts all packages with versions, direct/dev flags, and dependency relationships.
   - Upserts `Package` records (name + type).
   - Replaces `EnvironmentPackage` records for the environment (full snapshot, not diff).
   - Updates sync status to `completed` or `failed`.

## API Endpoints

### POST /api/v1/sync

Authenticated via API token (Laravel Sanctum or project-scoped token).

**Request:**

```json
{
  "project_code": "my-project",
  "environment": "production",
  "lockfiles": {
    "composer.lock": "<base64 encoded content>",
    "package-lock.json": "<base64 encoded content>"
  }
}
```

**Response (202 Accepted):**

```json
{
  "sync_id": "01HXY...",
  "status": "pending"
}
```

### GET /api/v1/sync/{id}

Returns sync status and summary.

## Lockfile Storage

Lockfiles are stored on a private disk for later use by osv-scanner and auditing jobs.

- Dedicated Laravel disk `voight-lockfiles` configured in the package's config file.
- Default driver: `local`, root: `storage/app/private/voight/lockfiles`.
- Directory structure: `{project_code}/{environment}/` — e.g. `my-project/production/composer.lock`.
- Files are overwritten on each sync (only latest version kept).
- Relative paths stored in `DependencySync.lockfile_paths` (JSON array) for traceability.
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
