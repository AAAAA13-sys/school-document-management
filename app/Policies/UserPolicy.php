<?php

namespace App\Policies;

use App\Enums\Role;
use App\Enums\Source;
use App\Models\User;

class UserPolicy
{
    public function viewAdmin(User $actor): bool
    {
        return $actor->isSuperAdmin() || $actor->isAdmin();
    }

    public function viewAdminSection(User $actor, string $section): bool
    {
        if (! $this->viewAdmin($actor)) {
            return false;
        }

        return $section !== 'connectors' || $actor->isSuperAdmin();
    }

    public function manageAccounts(User $actor): bool
    {
        return $this->viewAdmin($actor);
    }

    public function viewAudit(User $actor): bool
    {
        return $this->viewAdmin($actor);
    }

    public function sourceHistory(User $actor): bool
    {
        return $this->viewAdmin($actor);
    }

    public function manageConnectors(User $actor): bool
    {
        return $actor->isSuperAdmin();
    }

    public function accessWorkspace(User $actor, string $source): bool
    {
        return $actor->canAccessSource($source);
    }

    public function viewAny(User $actor): bool
    {
        return $this->manageAccounts($actor);
    }

    public function view(User $actor, User $target): bool
    {
        if (! $this->sameSchoolAndCampus($actor, $target)) {
            return false;
        }

        return $actor->isSuperAdmin() || ($actor->isAdmin() && $this->isStaffInAdminScope($actor, $target));
    }

    public function create(User $actor): bool
    {
        return $this->manageAccounts($actor);
    }

    public function createRole(User $actor, string $role): bool
    {
        if ($actor->isSuperAdmin()) {
            return in_array($role, Role::values(), true);
        }

        return $actor->isAdmin() && $role === Role::Staff->value;
    }

    public function createSource(User $actor, ?string $source = null): bool
    {
        if ($actor->isSuperAdmin()) {
            return $source === null || in_array($source, Source::values(), true);
        }

        if (! $actor->isAdmin() || $source === null || ! in_array($source, Source::values(), true)) {
            return false;
        }

        // Legacy admins without a primary source retain school-wide management.
        return $actor->source === null || $actor->source === $source;
    }

    public function update(User $actor, User $target): bool
    {
        if (! $this->sameSchoolAndCampus($actor, $target)) {
            return false;
        }

        if ($actor->isSuperAdmin() || $actor->id === $target->id) {
            return true;
        }

        return $actor->isAdmin() && $this->isStaffInAdminScope($actor, $target);
    }

    public function changeRole(User $actor, User $target, string $role): bool
    {
        if (! $this->update($actor, $target) || ! in_array($role, Role::values(), true)) {
            return false;
        }

        if ($actor->isSuperAdmin() || $actor->id === $target->id) {
            // The account controller rejects self-demotion/deactivation with a
            // validation response; policy access itself must not mask that guard.
            return true;
        }

        // An Admin may keep a Staff account as Staff, but never promote it.
        return $role === Role::Staff->value && $target->role === Role::Staff->value;
    }

    public function changeSource(User $actor, User $target, ?string $source): bool
    {
        if (! $this->update($actor, $target) || ($source !== null && ! in_array($source, Source::values(), true))) {
            return false;
        }

        if ($actor->isSuperAdmin() || $actor->id === $target->id) {
            return true;
        }

        // An Admin cannot move a Staff account out of their assigned source.
        return $source !== null && ($actor->source === null || $source === $actor->source);
    }

    private function sameSchoolAndCampus(User $actor, User $target): bool
    {
        return $actor->school_id === $target->school_id && $actor->campus === $target->campus;
    }

    private function isStaffInAdminScope(User $actor, User $target): bool
    {
        return $target->isStaff() && ($actor->source === null || $target->source === $actor->source);
    }
}
