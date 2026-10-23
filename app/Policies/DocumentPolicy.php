<?php

namespace App\Policies;

use App\Models\User;
use App\Services\Access;

class DocumentPolicy
{
    /**
     * Document lists and detail pages are always fetched through Access first so
     * IDs outside the actor's school/campus/source scope remain 404 responses.
     */
    public function view(User $actor, object $document): bool
    {
        if (! $this->sameSchoolAndCampus($actor, $document)) {
            return false;
        }

        if ($actor->isSuperAdmin() || $document->owner_id === $actor->id) {
            return true;
        }

        if ($actor->canAccessSource($document->source)) {
            return true;
        }

        // Named shares and folder grants are the documented cross-source exception.
        return Access::effectiveGrant($actor, $document) !== null;
    }

    public function create(User $actor, string $source): bool
    {
        // Super Admin has organisation-wide read-only document oversight.
        return ! $actor->isSuperAdmin() && $actor->canAccessSource($source);
    }

    /**
     * Route-level guard for write endpoints. The specific document policy below
     * still decides whether the actor may change the selected document.
     */
    public function writeAny(User $actor): bool
    {
        return ! $actor->isSuperAdmin() && ($actor->isAdmin() || $actor->isStaff());
    }

    /**
     * Folder batch intake creates folders implicitly, so it remains an Admin action.
     */
    public function batchAny(User $actor): bool
    {
        return $actor->isAdmin();
    }

    public function edit(User $actor, object $document): bool
    {
        if (! $this->view($actor, $document) || $actor->isSuperAdmin()) {
            return false;
        }

        if ($document->owner_id === $actor->id) {
            return true;
        }

        // Admins manage content in their own source only; a cross-source named
        // share must not turn into repository management authority.
        if ($actor->isAdmin()) {
            return Access::manages($actor, $document);
        }

        $grant = Access::effectiveGrant($actor, $document);

        return $grant?->permission === 'Editor' && ! $grant?->version_id;
    }

    public function manage(User $actor, object $document): bool
    {
        if (! $this->sameSchoolAndCampus($actor, $document) || $actor->isSuperAdmin()) {
            return false;
        }

        return $document->owner_id === $actor->id || Access::manages($actor, $document);
    }

    public function share(User $actor, object $document): bool
    {
        // Sharing and re-sharing are repository-management actions. Staff and
        // Super Admin accounts cannot grant access.
        return $actor->isAdmin()
            && $this->sameSchoolAndCampus($actor, $document)
            && Access::manages($actor, $document);
    }

    public function comment(User $actor, object $document): bool
    {
        if (! $this->view($actor, $document) || $actor->isSuperAdmin()) {
            return false;
        }

        if ($document->owner_id === $actor->id || Access::manages($actor, $document)) {
            return true;
        }

        $grant = Access::effectiveGrant($actor, $document);

        return in_array($grant?->permission, ['Commenter', 'Editor'], true);
    }

    public function star(User $actor, object $document): bool
    {
        return ! $actor->isSuperAdmin() && $this->view($actor, $document);
    }

    public function allVersions(User $actor, object $document): bool
    {
        if ($actor->isSuperAdmin() || $document->owner_id === $actor->id) {
            return true;
        }

        return $actor->canAccessSource($document->source) || Access::manages($actor, $document);
    }

    public function shareAny(User $actor): bool
    {
        return $actor->isAdmin();
    }

    public function commentAny(User $actor): bool
    {
        return ! $actor->isSuperAdmin() && ($actor->isAdmin() || $actor->isStaff());
    }

    private function sameSchoolAndCampus(User $actor, object $document): bool
    {
        return $actor->school_id === $document->school_id && $actor->campus === $document->campus;
    }
}
