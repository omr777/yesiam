<?php

namespace App\Policies;

use App\Models\Resource;
use App\Models\User;

class ResourcePolicy
{
    /*
    |--------------------------------------------------------------------------
    | CONSULTAR RECURSOS
    |--------------------------------------------------------------------------
    */

    public function viewAny(User $user): bool
    {
        return $user->hasPermission('resources.view');
    }

    public function view(
        User $user,
        Resource $resource
    ): bool {
        return $user->hasPermission('resources.view');
    }

    /*
    |--------------------------------------------------------------------------
    | CREAR RECURSOS
    |--------------------------------------------------------------------------
    */

    public function create(User $user): bool
    {
        return $user->hasPermission('resources.manage');
    }

    /*
    |--------------------------------------------------------------------------
    | MODIFICAR RECURSOS
    |--------------------------------------------------------------------------
    */

    public function update(
        User $user,
        Resource $resource
    ): bool {
        return $user->hasPermission('resources.manage')
            && $resource->is_active;
    }

    /*
    |--------------------------------------------------------------------------
    | DESACTIVAR RECURSOS
    |--------------------------------------------------------------------------
    */

    public function deactivate(
        User $user,
        Resource $resource
    ): bool {
        return $user->hasPermission('resources.manage')
            && $resource->is_active;
    }

    /*
    |--------------------------------------------------------------------------
    | ASIGNAR RESPONSABLES DE APROBACIÓN
    |--------------------------------------------------------------------------
    */

    public function assignApprover(
        User $actor,
        Resource $resource
    ): bool {
        return $actor->status === 'active'
            && $actor->hasPermission('resources.manage')
            && $resource->is_active;
    }
}
