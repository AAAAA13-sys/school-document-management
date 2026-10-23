<?php

namespace App\Policies;

use App\Models\User;
use App\Services\Access;

class FolderPolicy
{
    /**
     * Folder navigation is still governed by Access::folders(); this policy is
     * the write-side authority for folder management.
     */
    public function view(User $actor, object $folder): bool
    {
        if ($actor->school_id !== $folder->school_id || $actor->campus !== $folder->campus) {
            return false;
        }

        if ($actor->isSuperAdmin() || $actor->canAccessSource($folder->source)) {
            return true;
        }

        return false;
    }

    public function create(User $actor, string $source): bool
    {
        return ($actor->isSuperAdmin() || $actor->isAdmin() || $actor->isStaff()) && $actor->canAccessSource($source);
    }

    public function manageAny(User $actor): bool
    {
        return $actor->isSuperAdmin() || $actor->isAdmin() || $actor->isStaff();
    }

    public function update(User $actor, object $folder): bool
    {
        return $actor->school_id === $folder->school_id && $actor->campus === $folder->campus
            && $actor->canAccessSource($folder->source)
            && (Access::managesFolder($actor, $folder) || ($actor->isStaff() && $folder->owner_id === $actor->id));
    }

    public function share(User $actor, object $folder): bool
    {
        return false;
    }
}
