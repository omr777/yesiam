<?php

namespace App\Policies;

use App\Models\User;

class UserPolicy
{
    public function create(User $user): bool
    {
        return $user->hasPermission('users.manage');
    }
    
    public function changeDepartment(
    User $actor,
    User $target
    ): bool {
    return $actor->hasPermission('users.manage')
        && $actor->id !== $target->id;
    }

    public function deactivate(
    User $actor,
    User $target
    ): bool {
    return $actor->hasPermission('users.manage')
        && $actor->id !== $target->id;
    }

}
