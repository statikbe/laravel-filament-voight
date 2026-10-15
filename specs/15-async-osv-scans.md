# 15 — Asynchronous OSV scans

The scanner contract and the full design live in the lambda repo:
`voight-osv-scanner-lambda/docs/superpowers/specs/2026-10-05-async-scans-design.md`.

Voight side, in one page:

- Every scan is an `OsvScan` (`voight_osv_scans`); nightly batches also belong to a
  `NightlySweep` (`voight_nightly_sweeps`), which snapshots its environment ids.
- `OsvScanSubmitter::start()` saves the input to `voight/scans/{id}/input.json` on the
  lockfiles disk and queues `SubmitOsvScanJob` (after commit), which calls
  `POST /scans`, uploads to the presigned URL and queues `PollOsvScanJob`.
- Completion: the signed callback (`POST api/voight/scans/callback` →
  `CompleteOsvScanJob`) or `PollOsvScanJob` (every `poll_interval` s, 30-minute
  deadline, failing with "poll deadline exceeded"). Both end in `OsvScanResultHandler`,
  whose `UPDATE … WHERE status='pending' AND scanner_scan_id=?` claim picks one winner.
- Retryable failures are resubmitted up to `max_resubmits`; a scan that fails for good
  records one failed `AuditRun` (a sweep: one per environment, once).
- `RecordNightlySweepJob` merges batch results and records the sweep when its last
  batch completes.
- A callback URL that is not https is omitted from the scan request (a warning is
  logged); polling still completes the scan.

Config: `VOIGHT_SCANNER_URL` (the lambda's `ScansUrl`, …/scans), `VOIGHT_SCANNER_TOKEN`,
`VOIGHT_SCANNER_CALLBACK_SECRET` (equal to the lambda's `CALLBACK_SECRET`, min 32 chars),
`VOIGHT_SCANNER_CALLBACK_URL` (empty = polling only), `VOIGHT_SCANNER_POLL_INTERVAL` (15),
`VOIGHT_SCANNER_MAX_RESUBMITS` (2), `VOIGHT_SCANNER_BATCH_SIZE` (5000).
