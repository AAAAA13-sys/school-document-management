<?php

namespace App\Policies;

use App\Enums\Role;
use App\Models\EmployeeInvitation;
use App\Models\User;

class EmployeeInvitationPolicy
{
    public function create(User $actor): bool
    {
        return $actor->active && (new UserPolicy)->createRole($actor, Role::Staff->value);
    }

    public function manage(User $actor, EmployeeInvitation $invitation): bool
    {
        return $this->create($actor) && $invitation->role === Role::Staff->value
            && $actor->school_id === $invitation->school_id && $actor->campus === $invitation->campus
            && (new UserPolicy)->createSource($actor, $invitation->source);
    }
}
