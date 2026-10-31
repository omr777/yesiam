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

    /*
    |--------------------------------------------------------------------------
    | FASE 20 - CONSULTAR UNA IDENTIDAD
    |--------------------------------------------------------------------------
    |
    | El detalle requiere el mismo permiso
    | que el directorio.
    |
    */

    public function view(
        User $actor,
        User $target
    ): bool {
        return $actor->hasPermission('users.manage');
    }

    /*
    |--------------------------------------------------------------------------
    | FASE 20 - ACTIVAR IDENTIDAD
    |--------------------------------------------------------------------------
    |
    | Completa el Joiner: una identidad
    | suspendida pasa a activa.
    |
    | No se permite autoactivación.
    |
    */

    public function activate(
        User $actor,
        User $target
    ): bool {
        return $actor->hasPermission('users.manage')
            && $actor->id !== $target->id;
    }

    /*
    |--------------------------------------------------------------------------
    | FASE 20 - ASIGNAR ROLES
    |--------------------------------------------------------------------------
    |
    | Un administrador no puede cambiar
    | sus propios roles: evita escalar
    | privilegios o retirarse el acceso
    | administrativo por error.
    |
    */

    public function assignRoles(
        User $actor,
        User $target
    ): bool {
        return $actor->hasPermission('users.manage')
            && $actor->id !== $target->id;
    }
}
