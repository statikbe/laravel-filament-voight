<?php

declare(strict_types=1);

namespace Statikbe\FilamentVoight\Policies;

use Illuminate\Auth\Access\HandlesAuthorization;
use Illuminate\Foundation\Auth\User as AuthUser;
use Statikbe\FilamentVoight\Models\AuditRun;

class AuditRunPolicy
{
    use HandlesAuthorization;

    public function viewAny(AuthUser $authUser): bool
    {
        return $authUser->can('ViewAny:AuditRun');
    }

    public function view(AuthUser $authUser, AuditRun $auditRun): bool
    {
        return $authUser->can('View:AuditRun');
    }

    public function create(AuthUser $authUser): bool
    {
        return $authUser->can('Create:AuditRun');
    }

    public function update(AuthUser $authUser, AuditRun $auditRun): bool
    {
        return $authUser->can('Update:AuditRun');
    }

    public function delete(AuthUser $authUser, AuditRun $auditRun): bool
    {
        return $authUser->can('Delete:AuditRun');
    }

    public function deleteAny(AuthUser $authUser): bool
    {
        return $authUser->can('DeleteAny:AuditRun');
    }

    public function restore(AuthUser $authUser, AuditRun $auditRun): bool
    {
        return $authUser->can('Restore:AuditRun');
    }

    public function forceDelete(AuthUser $authUser, AuditRun $auditRun): bool
    {
        return $authUser->can('ForceDelete:AuditRun');
    }

    public function forceDeleteAny(AuthUser $authUser): bool
    {
        return $authUser->can('ForceDeleteAny:AuditRun');
    }

    public function restoreAny(AuthUser $authUser): bool
    {
        return $authUser->can('RestoreAny:AuditRun');
    }

    public function replicate(AuthUser $authUser, AuditRun $auditRun): bool
    {
        return $authUser->can('Replicate:AuditRun');
    }

    public function reorder(AuthUser $authUser): bool
    {
        return $authUser->can('Reorder:AuditRun');
    }
}
