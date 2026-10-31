<?php

namespace App\Policies;

use App\Models\User;
use App\Services\Access;
use Illuminate\Support\Facades\DB;

class DocumentPolicy
{
    /**
     * Document lists and detail pages are always fetched through Access first so
     * IDs outside the actor's school/campus/source scope remain 404 responses.
     * The policy delegates non-owner decisions to Access::visibleTo so listings,
     * search, counts, downloads and the API cannot drift from this check.
     */
    public function view(User $actor, object $document): bool
    {
        if (! $this->sameSchoolAndCampus($actor, $document) || ! ($actor->active ?? true)) {
            return false;
        }

        if ($actor->isSuperAdmin() || $document->owner_id === $actor->id) {
            return true;
        }

        return Access::visibleTo(DB::table('documents')->where('id', $document->id), $actor)->exists();
    }

    public function create(User $actor, string $source): bool
    {
        return $actor->active && $actor->canAccessSource($source);
    }

    /**
     * Route-level guard for write endpoints. The specific document policy below
     * still decides whether the actor may change the selected document.
     */
    public function writeAny(User $actor): bool
    {
        return $actor->active && ($actor->isSuperAdmin() || $actor->isAdmin() || $actor->isStaff());
    }

    /**
     * Folder batch intake creates folders owned by the uploading account.
     */
    public function batchAny(User $actor): bool
    {
        return $this->writeAny($actor);
    }

    public function edit(User $actor, object $document): bool
    {
        if (! $this->view($actor, $document)) {
            return false;
        }

        if ($actor->isSuperAdmin() || $document->owner_id === $actor->id) {
            return true;
        }

        // Restricted documents do not inherit Admin source-management authority.
        // A same-source Admin must rely on an explicit Editor grant, just like
        // Staff; a cross-source Admin share remains read-only under Phase 3 rules.
        if ($document->classification === 'Restricted') {
            if ($actor->isAdmin() && ! Access::manages($actor, $document)) {
                return false;
            }

            $grant = Access::effectiveGrant($actor, $document);

            return $grant?->permission === 'Editor' && ! $grant?->version_id;
        }

        // Admins manage Internal/Confidential content in their own source only;
        // a cross-source named share must not become repository management.
        if ($actor->isAdmin()) {
            return Access::manages($actor, $document);
        }

        $grant = Access::effectiveGrant($actor, $document);

        return $grant?->permission === 'Editor' && ! $grant?->version_id;
    }

    public function manage(User $actor, object $document): bool
    {
        if (! $this->sameSchoolAndCampus($actor, $document) || ! ($actor->active ?? true)) {
            return false;
        }

        if (! $this->view($actor, $document)) {
            return false;
        }

        if ($actor->isSuperAdmin() || $document->owner_id === $actor->id) {
            return true;
        }

        // Restricted documents are owner-only for management actions; explicit
        // shares can grant editing but never delete/move/reclassify authority.
        return $document->classification !== 'Restricted' && Access::manages($actor, $document);
    }

    public function share(User $actor, object $document): bool
    {
        if (! $this->sameSchoolAndCampus($actor, $document) || ! ($actor->active ?? true) || ! $this->view($actor, $document)) {
            return false;
        }

        // Restricted documents are the one sharing action reserved to Super Admin.
        if ($document->classification === 'Restricted') {
            return $actor->isSuperAdmin();
        }

        return $actor->isSuperAdmin() || $document->owner_id === $actor->id
            || ($actor->isAdmin() && Access::manages($actor, $document));
    }

    /**
     * A classification change is a management action, and the actor must also be
     * able to see the target level. Owners see every level; an Admin can move
     * Internal/Confidential documents inside its source but cannot promote them
     * to Restricted unless it owns the document.
     */
    public function classify(User $actor, object $document, string $classification): bool
    {
        if (! in_array($classification, ['Internal', 'Confidential', 'Restricted'], true)
            || ! $this->manage($actor, $document)) {
            return false;
        }

        if ($actor->isSuperAdmin() || $document->owner_id === $actor->id) {
            return true;
        }

        return $classification !== 'Restricted' && $actor->isAdmin() && Access::manages($actor, $document);
    }

    public function comment(User $actor, object $document): bool
    {
        if (! $this->view($actor, $document)) {
            return false;
        }

        if ($actor->isSuperAdmin() || $document->owner_id === $actor->id) {
            return true;
        }

        // Restricted documents require the explicit share permission.
        if ($document->classification !== 'Restricted' && Access::manages($actor, $document)) {
            return true;
        }

        $grant = Access::effectiveGrant($actor, $document);

        return in_array($grant?->permission, ['Commenter', 'Editor'], true);
    }

    public function star(User $actor, object $document): bool
    {
        return $this->view($actor, $document);
    }

    public function allVersions(User $actor, object $document): bool
    {
        if (! $this->sameSchoolAndCampus($actor, $document) || ! ($actor->active ?? true)) {
            return false;
        }

        if ($actor->isSuperAdmin() || $document->owner_id === $actor->id) {
            return true;
        }

        // Restricted version history follows the explicit grant, including any
        // pinned version, rather than source-wide visibility.
        if ($document->classification === 'Restricted') {
            return false;
        }

        if ($actor->isAdmin() && Access::manages($actor, $document)) {
            return true;
        }

        return $document->classification === 'Internal' && $actor->canAccessSource($document->source);
    }

    public function shareAny(User $actor): bool
    {
        // Resource policies and approval routing decide the specific action.
        return $this->writeAny($actor);
    }

    public function commentAny(User $actor): bool
    {
        return $this->writeAny($actor);
    }

    private function sameSchoolAndCampus(User $actor, object $document): bool
    {
        return $actor->school_id === $document->school_id && $actor->campus === $document->campus;
    }
}
