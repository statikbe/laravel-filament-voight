# Project Management

## Overview

CRUD management for Customers, Teams, Projects, and Environments via Filament admin panels.

## Customers

- Create, edit, delete customers.
- Fields: name, slug (auto-generated from name via `spatie/laravel-sluggable`).
- Slug is not editable in the form — only `name` is exposed.
- List view with project count.
- Form wrapped in a Section component.

## Teams

- Create, edit, delete teams.
- Fields: name (form wrapped in a Section component).
- Users relation manager (attach/detach users, no inverse relationship needed — `$hasInverseRelationship = false`).
- User search by name or email in the attach dialog.

## Projects

- Create, edit, delete projects.
- Form organized into Sections: General, Assignment, Settings, API Token.
- General section fields: project_code (unique, required), name (required), description, repo_url (required, URL validated).
- Assignment section: customer select (searchable, preloaded, required, with inline creation via `createOptionForm`), team select (searchable, preloaded, required).
- Settings section: is_muted toggle.
- API Token section (visible only on edit, not create):
  - Shows active token count.
  - Generate Token action (with confirmation) — creates a Sanctum token and displays the plain-text token in a persistent notification.
  - Revoke All Tokens action (danger, with confirmation) — deletes all tokens for the project.
- List view filterable by customer, team, muted status.

## Environments

- Managed as a relation on the project detail page.
- Create, edit, delete environments per project.
- Fields: name (e.g. "production", "staging", "development").
- Unique per project.

## Packages

- Read-only resource (no create/edit pages, only list).
- Shows all tracked packages.
- Navigation group: Dependencies.

## User Defaults

- A user belongs to one or more teams.
- Default team filter applied to project lists and search results.
- User can override the filter to see all projects.

## Architecture Notes

- The package supports two usage modes: standalone panel (`FilamentVoightPanelProvider`) or plugin in an existing panel (`FilamentVoightPlugin`).
- All configuration is centralized in `FilamentVoightConfig`, accessed via the `FilamentVoight::config()` facade.
- Translation helper `voightTrans()` is used throughout, backed by a single translation file.
- All forms are wrapped in `Section` components for consistent UI structure.
