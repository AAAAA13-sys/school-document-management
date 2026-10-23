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

        return Access::folderGrant($actor, $folder->parent_id) !== null
            || Access::folderGrant($actor, $folder->id) !== null;
    }

    public function create(User $actor, string $source): bool
    {
        return $actor->isAdmin() && $actor->canAccessSource($source);
    }

    public function manageAny(User $actor): bool
    {
        return $actor->isAdmin();
    }

    public function update(User $actor, object $folder): bool
    {
        return $actor->isAdmin() && Access::managesFolder($actor, $folder);
    }

    public function share(User $actor, object $folder): bool
    {
        return $this->update($actor, $folder);
    }
}
