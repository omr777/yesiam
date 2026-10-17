<?php

namespace App\Policies;

use App\Models\Department;
use App\Models\User;

class DepartmentPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->hasPermission('departments.view');
    }

    public function create(User $user): bool
    {
        return $user->hasPermission('departments.create');
    }
}
