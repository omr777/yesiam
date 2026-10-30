<?php

namespace App\Policies;

use App\Models\User;

class UserPolicy
{
    /*
    |--------------------------------------------------------------------------
    | CONSULTAR IDENTIDADES
    |--------------------------------------------------------------------------
    |
    | Autoriza el listado de usuarios.
    |
    | Solo las identidades que posean
    | el permiso users.manage pueden
    | consultar el directorio IAM.
    |
    | La autorización se realiza en Laravel,
    | no en Angular.
    |
    */

    public function viewAny(User $user): bool
    {
        return $user->hasPermission('users.manage');
    }

    /*
    |--------------------------------------------------------------------------
    | JOINER - CREAR IDENTIDAD
    |--------------------------------------------------------------------------
    */

    public function create(User $user): bool
    {
        return $user->hasPermission('users.manage');
    }

    /*
    |--------------------------------------------------------------------------
    | MOVER - CAMBIAR DEPARTAMENTO
    |--------------------------------------------------------------------------
    |
    | Requiere users.manage.
    |
    | No permite que un administrador
    | modifique su propio departamento
    | mediante esta operación.
    |
    */

    public function changeDepartment(
        User $actor,
        User $target
    ): bool {
        return $actor->hasPermission('users.manage')
            && $actor->id !== $target->id;
    }

    /*
    |--------------------------------------------------------------------------
    | LEAVER - DESACTIVAR IDENTIDAD
    |--------------------------------------------------------------------------
    |
    | Requiere users.manage.
    |
    | No permite desactivar
    | la propia identidad.
    |
    */

    public function deactivate(
        User $actor,
        User $target
    ): bool {
        return $actor->hasPermission('users.manage')
            && $actor->id !== $target->id;
    }
}
