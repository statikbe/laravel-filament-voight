# Changelog

All notable changes to `laravel-filament-voight` will be documented in this file.

## Unreleased

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
