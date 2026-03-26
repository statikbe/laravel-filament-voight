# Project Management

## Overview

CRUD management for Customers, Teams, Projects, and Environments via Filament admin panels.

## Customers

- Create, edit, delete customers.
- Fields: name, slug (auto-generated from name).
- List view with project count.

## Teams

- Create, edit, delete teams.
- Assign users to teams (many-to-many).
- Fields: name, members.

## Projects

- Create, edit, delete projects.
- Fields: project_code (unique), name, description, repo_url, customer, team, is_muted.
- Notification hooks configuration (inspired by Flare): define per-project webhook URLs and channels.
- List view filterable by customer, team, muted status.

## Environments

- Managed as a relation on the project detail page.
- Create, edit, delete environments per project.
- Fields: name (e.g. "production", "staging", "development").
- Unique per project.

## User Defaults

- A user belongs to one or more teams.
- Default team filter applied to project lists and search results.
- User can override the filter to see all projects.
