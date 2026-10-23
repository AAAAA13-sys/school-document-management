<?php

namespace App\Policies;

use App\Models\User;

class ConnectorPolicy
{
    public function viewAny(User $actor): bool
    {
        return $actor->isSuperAdmin();
    }

    public function create(User $actor): bool
    {
        return $actor->isSuperAdmin();
    }

    public function delete(User $actor): bool
    {
        return $actor->isSuperAdmin();
    }
}
