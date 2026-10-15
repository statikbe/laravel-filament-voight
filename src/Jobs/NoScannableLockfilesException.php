<?php

namespace Statikbe\FilamentVoight\Jobs;

use RuntimeException;

/**
 * The environment has no completed sync with lockfiles to scan. RunOsvScanJob
 * fails with it without recording a failed audit run: there was nothing to scan.
 */
class NoScannableLockfilesException extends RuntimeException {}
