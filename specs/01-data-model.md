# Data Model

## Entity Relationship Overview

```
Customer 1──N Project N──1 Team
                │
                N
           Environment
                │
           ┌────┼────┐
           N    N    N
    DependencySync  EnvironmentPackage
                         │
                         N──1 Package
                                │
                              N (via affected version ranges)
                          Vulnerability
                                │
                            AuditFinding N──1 AuditRun N──1 Environment
```

## Entities

### Customer

Represents a client whose projects are managed.

| Field       | Type   | Notes            |
|-------------|--------|------------------|
| id          | ulid   | PK               |
| name        | string |                  |
| slug        | string | unique, auto-generated via spatie/laravel-sluggable |
| created_at  | timestamp |               |
| updated_at  | timestamp |               |

### Team

A group of users responsible for projects.

| Field       | Type   | Notes            |
|-------------|--------|------------------|
| id          | ulid   | PK               |
| name        | string |                  |
| created_at  | timestamp |               |
| updated_at  | timestamp |               |

Pivot: `team_user` (team_id ULID, user_id int)

### Project

A codebase tracked by the system. Uses auto-increment `int` ID (not ULID) for Sanctum token compatibility. Fields `name`, `repo_url`, `customer_id`, and `team_id` are nullable because the API can auto-create projects with just a `project_code`.

| Field        | Type   | Notes                        |
|--------------|--------|------------------------------|
| id           | int    | PK, auto-increment           |
| project_code | string | unique, human-readable ID    |
| name         | string | nullable                     |
| description  | text   | nullable                     |
| repo_url     | string | nullable, git repository URL |
| customer_id  | ulid   | nullable, FK → Customer      |
| team_id      | ulid   | nullable, FK → Team          |
| is_muted     | bool   | default false, global mute   |
| created_at   | timestamp |                            |
| updated_at   | timestamp |                            |

### Environment

A deployment context for a project (e.g. production, staging, development).

| Field       | Type   | Notes            |
|-------------|--------|------------------|
| id          | ulid   | PK               |
| project_id  | int    | FK → Project     |
| name        | string | e.g. "production"|
| scanned_at  | timestamp | nullable, last successful scan |
| created_at  | timestamp |               |
| updated_at  | timestamp |               |

Unique constraint: (project_id, name)

### Package

A unique dependency (composer or npm package). Represents the package itself, not a specific installed version.

| Field                      | Type   | Notes                          |
|----------------------------|--------|--------------------------------|
| id                         | ulid   | PK                             |
| name                       | string | e.g. "laravel/framework"       |
| type                       | enum   | `composer`, `npm`              |
| latest_version             | string | nullable, last known latest    |
| latest_version_updated_at  | timestamp | nullable                   |
| created_at                 | timestamp |                             |
| updated_at                 | timestamp |                             |

Unique constraint: (name, type)

### EnvironmentPackage

Links an environment to its installed packages (the full dependency tree).

| Field             | Type   | Notes                                    |
|-------------------|--------|------------------------------------------|
| id                | ulid   | PK                                       |
| environment_id    | ulid   | FK → Environment                         |
| package_id        | ulid   | FK → Package                             |
| version           | string | installed version                        |
| is_direct         | bool   | true = declared in composer/package.json  |
| is_dev            | bool   | true = dev dependency                    |
| parent_package_id | ulid   | nullable, FK → Package (dependency tree) |
| created_at        | timestamp |                                        |
| updated_at        | timestamp |                                        |

### DependencySync

Records each sync event when a project pushes its lockfiles.

| Field          | Type   | Notes                     |
|----------------|--------|---------------------------|
| id             | ulid   | PK                        |
| environment_id | ulid   | FK → Environment          |
| lockfile_hash  | string | SHA-256 of lockfile content|
| package_count  | int    | total packages synced     |
| status         | enum   | `pending`, `processing`, `completed`, `failed` |
| lockfile_paths | json   | nullable, relative paths of stored lockfiles |
| error_message  | text   | nullable                  |
| synced_at      | timestamp |                         |
| created_at     | timestamp |                         |
| updated_at     | timestamp |                         |

### Vulnerability

A known security vulnerability from OSV or other sources.

| Field         | Type   | Notes                              |
|---------------|--------|------------------------------------|
| id            | ulid   | PK                                 |
| source        | enum   | `osv`, `github_advisory`, `manual` |
| source_id     | string | e.g. OSV ID, GHSA ID              |
| aliases       | json   | array of CVE IDs, etc.            |
| summary       | string |                                    |
| details       | text   | nullable                           |
| vulnerability_score | decimal(3,1) | CVSS score 0.0–10.0       |
| published_at  | timestamp |                                  |
| modified_at   | timestamp |                                  |
| created_at    | timestamp |                                  |
| updated_at    | timestamp |                                  |

Unique constraint: (source, source_id)

### VulnerablePackageRange

Defines which package versions are affected by a vulnerability.

| Field            | Type   | Notes                          |
|------------------|--------|--------------------------------|
| id               | ulid   | PK                             |
| vulnerability_id | ulid   | FK → Vulnerability             |
| package_id       | ulid   | FK → Package                   |
| affected_range   | string | semver range, e.g. ">=1.0 <1.5"|
| fixed_version    | string | nullable                       |
| created_at       | timestamp |                              |
| updated_at       | timestamp |                              |

### AuditRun

A security audit execution against an environment.

| Field          | Type   | Notes                     |
|----------------|--------|---------------------------|
| id             | ulid   | PK                        |
| environment_id | ulid   | FK → Environment          |
| status         | enum   | `pending`, `running`, `completed`, `failed` |
| started_at     | timestamp | nullable               |
| completed_at   | timestamp | nullable               |
| created_at     | timestamp |                         |
| updated_at     | timestamp |                         |

### AuditFinding

A specific vulnerability found during an audit for a given package.

| Field            | Type   | Notes                |
|------------------|--------|----------------------|
| id               | ulid   | PK                   |
| audit_run_id     | ulid   | FK → AuditRun        |
| package_id       | ulid   | FK → Package         |
| vulnerability_id | ulid   | FK → Vulnerability   |
| installed_version| string |                      |
| fixed_version    | string | nullable             |
| created_at       | timestamp |                   |
| updated_at       | timestamp |                   |

### AlertSetting

Configures how and when notifications are sent.

| Field              | Type   | Notes                                   |
|--------------------|--------|-----------------------------------------|
| id                 | ulid   | PK                                      |
| project_id         | int    | nullable, FK → Project (null = global)  |
| channel            | enum   | `email`, `slack`                        |
| severity_threshold | decimal(3,1) | minimum CVSS score to trigger alert |
| frequency          | enum   | `immediate`, `daily`, `weekly`          |
| webhook_url        | string | nullable, for Slack                     |
| is_enabled         | bool   | default true                            |
| created_at         | timestamp |                                       |
| updated_at         | timestamp |                                       |

## Notes

- Most package-owned primary keys use ULIDs for sortability and uniqueness. The exception is `Project`, which uses auto-increment `int` for compatibility with Laravel Sanctum's `HasApiTokens` trait.
- Laravel's default tables (users, etc.) keep their original `int` auto-increment IDs. Foreign keys referencing these tables must use `int` accordingly.
- Customer uses `spatie/laravel-sluggable` (`HasSlug` trait) for automatic slug generation from the `name` field.
- All models are overridable via the `FilamentVoightConfig` class (accessed via `FilamentVoight::config()` facade). A configurable morph map is registered for all models.
- Authorization handled via Laravel policies — no roles table needed in v1.
- Severity is derived from `vulnerability_score` using CVSS v3 ranges: critical (9.0–10.0), high (7.0–8.9), medium (4.0–6.9), low (0.1–3.9), none (0.0). Implemented as an accessor on the Vulnerability model, not stored.
- The `parent_package_id` on EnvironmentPackage enables reconstructing the dependency tree, but a package can be pulled in by multiple parents. Consider this a "primary parent" for display purposes; full graph may need a separate edge table in a future iteration.
