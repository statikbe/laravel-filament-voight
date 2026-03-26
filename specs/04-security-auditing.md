# Security Auditing

## Overview

Automated vulnerability scanning of dependencies using Google's OSV-scanner, with results stored and tracked per environment.

## Audit Strategy

### OSV-Scanner Integration

- Use Google's [osv-scanner](https://github.com/google/osv-scanner) CLI tool.
- For each environment, pass the stored lockfiles (from `voight-lockfiles` disk) to osv-scanner.
- osv-scanner natively understands `composer.lock` and `package-lock.json`.
- Parse osv-scanner JSON output to extract vulnerabilities.
- Store results as `Vulnerability` and `AuditFinding` records.

### Audit Flow

1. Trigger: scheduled (cron), manual (via Filament UI), or post-sync (after dependency sync completes).
2. Create `AuditRun` record with status `pending`.
3. Queued job processes each package:
   - Batches queries to OSV API (supports batch endpoint).
   - Creates/updates `Vulnerability` records.
   - Creates `VulnerablePackageRange` records for affected versions.
   - Creates `AuditFinding` for each match.
4. Mark `AuditRun` as `completed`.
5. If new findings, trigger alert evaluation.

### osv-scanner CLI Approach

- osv-scanner reads lockfiles directly — no need for PHP/Node runtime per version.
- Single Go binary, easy to install on the server or run in CI.
- Supports `--format json` for machine-readable output.
- Lockfiles are read from the configurable lockfiles disk (default `voight-lockfiles`, configured via `FilamentVoightConfig::getLockfilesDisk()`) where they were stored during sync.
- Fallback: if osv-scanner coverage is insufficient, consider supplementing with Packagist advisories or GitHub Advisory Database.

## Audit Scheduling

- Default: daily audit of all environments.
- Post-sync: automatically audit after a successful dependency sync.
- Manual: trigger via Filament action button on environment or project.

## Vulnerability Deduplication

- Vulnerabilities are unique by (source, source_id).
- Aliases (CVE IDs, GHSA IDs) stored in JSON array for cross-referencing.
- When displaying, group by vulnerability, show all affected environments.

## Future Considerations

- AWS Lambda integration for running actual `composer audit` / `npm audit` per version if API-only approach proves insufficient.
- SBOM (Software Bill of Materials) export in CycloneDX or SPDX format.
