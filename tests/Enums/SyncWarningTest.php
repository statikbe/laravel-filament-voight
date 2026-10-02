<?php

use Statikbe\FilamentVoight\Enums\SyncWarning;

it('exposes the sync warning cases with string values', function () {
    expect(SyncWarning::UnsupportedLockfile->value)->toBe('unsupported_lockfile')
        ->and(SyncWarning::NpmLockfileV1Unsupported->value)->toBe('npm_lockfile_v1_unsupported')
        ->and(SyncWarning::YarnBerryUnsupported->value)->toBe('yarn_berry_unsupported')
        ->and(SyncWarning::YarnWorkspacesUnsupported->value)->toBe('yarn_workspaces_unsupported')
        ->and(SyncWarning::LockfileMissingOnDisk->value)->toBe('lockfile_missing_on_disk');
});

it('fills the stored context into the translated message', function (SyncWarning $warning) {
    expect($warning->message(['file' => 'some-lockfile.lock']))->toContain('some-lockfile.lock');
})->with(SyncWarning::cases());
