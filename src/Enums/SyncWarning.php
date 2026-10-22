<?php

namespace Statikbe\FilamentVoight\Enums;

use Filament\Support\Contracts\HasColor;
use Filament\Support\Contracts\HasIcon;
use Filament\Support\Contracts\HasLabel;

/**
 * Something a dependency sync could not (fully) understand. Stored on the sync as
 * a code plus context, so the message is translated when it is displayed.
 */
enum SyncWarning: string implements HasColor, HasIcon, HasLabel
{
    use Concerns\HasOptions;

    case UnsupportedLockfile = 'unsupported_lockfile';
    case NpmLockfileV1Unsupported = 'npm_lockfile_v1_unsupported';
    case YarnBerryUnsupported = 'yarn_berry_unsupported';
    case YarnWorkspacesUnsupported = 'yarn_workspaces_unsupported';
    case LockfileMissingOnDisk = 'lockfile_missing_on_disk';

    public function label(): string
    {
        return voightTrans('enums.sync_warning.' . $this->value . '.label');
    }

    /**
     * @param  array<string, string>  $context
     */
    public function message(array $context): string
    {
        return voightTrans('enums.sync_warning.' . $this->value . '.message', $context);
    }

    public function color(): string
    {
        return 'warning';
    }

    public function icon(): string
    {
        return 'heroicon-o-exclamation-triangle';
    }

    public function getLabel(): string
    {
        return $this->label();
    }

    public function getColor(): string
    {
        return $this->color();
    }

    public function getIcon(): string
    {
        return $this->icon();
    }
}
