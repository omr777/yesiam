<?php

namespace App\Policies;

use App\Models\User;

class AccessRequestPolicy
{
    /*
    |--------------------------------------------------------------------------
    | CONSULTAR MIS PROPIAS SOLICITUDES
    |--------------------------------------------------------------------------
    */

    public function viewAny(User $user): bool
    {
        return $user->hasPermission('access.request');
    }

    /*
    |--------------------------------------------------------------------------
    | CREAR SOLICITUDES DE ACCESO
    |--------------------------------------------------------------------------
    */

    public function create(User $user): bool
    {
        return $user->hasPermission('access.request');
    }

    /*
    |--------------------------------------------------------------------------
    | CONSULTAR SOLICITUDES ASIGNADAS
    |--------------------------------------------------------------------------
    */

    public function viewAssigned(User $user): bool
    {
        return $user->status === 'active'
            && $user->hasPermission('access.approve');
    }
}
