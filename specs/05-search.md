# Search

## Overview

Search across all tracked dependencies with filtering by package name, version constraints, team, and project.

## Search Capabilities

### Package Search

- Search by package name (partial match, e.g. "laravel" finds all laravel/* packages).
- Filter by package type: `composer`, `npm`, or both.

### Version Filtering

- Exact version: `1.2.3`
- Semver ranges: `>=1.0`, `<2.0`, `^1.5`, `~1.5`
- Combined: `>=1.0 <2.0`
- Uses `composer/semver` library for PHP version comparison.

### Team Filtering

- Filter results by team.
- Default: current user's team(s) applied automatically.
- User can clear team filter to search across all projects.

### Additional Filters

- Customer
- Project
- Environment
- Direct dependencies only (exclude transitive)
- Dev dependencies only
- Has known vulnerabilities

## Search Results

Results show:

| Column        | Description                           |
|---------------|---------------------------------------|
| Package       | name and type icon                    |
| Version       | installed version                     |
| Latest        | latest known version (with outdated indicator) |
| Project       | project name + environment            |
| Team          | owning team                           |
| Vulnerabilities | count with severity badge           |

## Search API

### GET /api/v1/search

Query parameters: `q`, `type`, `version`, `team_id`, `customer_id`, `project_id`, `has_vulnerabilities`, `direct_only`, `dev_only`.

Returns paginated results.

## Filament UI

- Global search integration in Filament header.
- Dedicated search page with advanced filters.
- Click-through to project/environment detail.
