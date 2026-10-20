<?php

namespace App\Policies;

use App\Models\Resource;
use App\Models\User;

class ResourcePolicy
{
    // Consultar el catálogo de recursos.
    public function viewAny(User $user): bool
    {
        return $user->hasPermission('resources.view');
    }

    // Consultar un recurso específico.
    public function view(User $user, Resource $resource): bool
    {
        return $user->hasPermission('resources.view');
    }

    // Crear nuevos recursos.
    public function create(User $user): bool
    {
        return $user->hasPermission('resources.manage');
    }

    // Modificar recursos activos.
    public function update(User $user, Resource $resource): bool
    {
        return $user->hasPermission('resources.manage')
            && $resource->is_active;
    }

    // Desactivar recursos activos.
    public function deactivate(User $user, Resource $resource): bool
    {
        return $user->hasPermission('resources.manage')
            && $resource->is_active;
    }
}
