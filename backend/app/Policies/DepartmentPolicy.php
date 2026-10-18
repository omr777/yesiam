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
    
    public function update(User $user, Department $department): bool
    {
    return $user->hasPermission('departments.update');
    }

    public function deactivate(User $user, Department $department): bool
    {
    return $user->hasPermission('departments.deactivate');
    }

}
