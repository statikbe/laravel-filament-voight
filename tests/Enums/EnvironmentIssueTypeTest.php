<?php

use Statikbe\FilamentVoight\Enums\EnvironmentIssueType;

it('exposes the issue type cases with string values', function () {
    expect(EnvironmentIssueType::SyncFailed->value)->toBe('sync_failed')
        ->and(EnvironmentIssueType::ScanFailed->value)->toBe('scan_failed')
        ->and(EnvironmentIssueType::SyncWarning->value)->toBe('sync_warning')
        ->and(EnvironmentIssueType::NeverSynced->value)->toBe('never_synced');
});

it('ranks failures before warnings before info', function () {
    $sorted = collect(EnvironmentIssueType::cases())
        ->shuffle()
        ->sortBy(fn (EnvironmentIssueType $type): int => $type->priority())
        ->values()
        ->all();

    expect($sorted)->toBe([
        EnvironmentIssueType::SyncFailed,
        EnvironmentIssueType::ScanFailed,
        EnvironmentIssueType::SyncWarning,
        EnvironmentIssueType::NeverSynced,
    ]);
});

it('uses danger for failures, warning for sync warnings and info for never synced', function () {
    expect(EnvironmentIssueType::SyncFailed->getColor())->toBe('danger')
        ->and(EnvironmentIssueType::ScanFailed->getColor())->toBe('danger')
        ->and(EnvironmentIssueType::SyncWarning->getColor())->toBe('warning')
        ->and(EnvironmentIssueType::NeverSynced->getColor())->toBe('info');
});
