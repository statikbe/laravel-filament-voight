# Reporting

## Overview

Dashboards and reports for dependency health, vulnerability status, and audit history.

## Dashboard (Filament)

### Overview Widgets

- **Total projects** tracked, with breakdown by team.
- **Vulnerability summary**: total open findings by severity (critical/high/medium/low).
- **Recent audit runs**: last 10 with status and finding count.
- **Most vulnerable projects**: top 10 by finding count.
- **Outdated dependencies**: packages where installed version << latest version.

### Project Detail

- Environments with dependency counts.
- Vulnerability findings per environment.
- Dependency sync history.
- Combell detection status (if applicable).

### Environment Detail

- Full dependency tree (expandable).
- Direct vs transitive dependency list.
- Vulnerability findings with severity badges.
- Version comparison (installed vs latest).

## Reports

### Vulnerability Report

- Filterable by: severity, team, customer, project, date range.
- Exportable as CSV.
- Shows: vulnerability, affected packages, affected projects/environments, status.

### Dependency Inventory

- Full list of all packages across all projects.
- Group by package to see which projects use it.
- Useful for impact analysis: "if package X has a vulnerability, who is affected?"

### Audit History

- Timeline of audit runs with results.
- Trend: vulnerability count over time.

## Future

- PDF export for compliance reporting.
- Scheduled report delivery via email.
- SBOM export (CycloneDX, SPDX).
