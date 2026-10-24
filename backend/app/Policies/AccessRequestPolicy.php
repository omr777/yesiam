<?php

namespace App\Policies;

use App\Models\AccessRequest;
use App\Models\User;

class AccessRequestPolicy
{
    /*
    |--------------------------------------------------------------------------
    | CONSULTAR SOLICITUDES PROPIAS
    |--------------------------------------------------------------------------
    */

    public function viewAny(User $user): bool
    {
        return $user->hasPermission('access.request');
    }

    /*
    |--------------------------------------------------------------------------
    | CREAR SOLICITUDES
    |--------------------------------------------------------------------------
    */

    public function create(User $user): bool
    {
        return $user->hasPermission('access.request');
    }

    /*
    |--------------------------------------------------------------------------
    | CONSULTAR BANDEJA DE APROBACIONES
    |--------------------------------------------------------------------------
    */

    public function viewAssigned(User $user): bool
    {
        return $user->status === 'active'
            && $user->hasPermission('access.approve');
    }

    /*
    |--------------------------------------------------------------------------
    | APROBAR O RECHAZAR UNA SOLICITUD
    |--------------------------------------------------------------------------
    */

    public function decide(
        User $user,
        AccessRequest $accessRequest
    ): bool {
        // 1. El responsable debe estar activo.
        if ($user->status !== 'active') {
            return false;
        }

        // 2. Necesita permiso de aprobación.
        if (! $user->hasPermission('access.approve')) {
            return false;
        }

        // 3. Solamente solicitudes pendientes.
        if ($accessRequest->status !== 'pending') {
            return false;
        }

        // 4. No puede decidir su propia solicitud.
        if ($accessRequest->requester_user_id === $user->id) {
            return false;
        }

        // 5. Consultar el recurso y al solicitante.
        $accessRequest->loadMissing([
            'resource',
            'requester',
        ]);

        // 6. El recurso debe existir y estar activo.
        if (
            ! $accessRequest->resource
            || ! $accessRequest->resource->is_active
        ) {
            return false;
        }

        // 7. El solicitante debe seguir activo.
        if (
            ! $accessRequest->requester
            || $accessRequest->requester->status !== 'active'
        ) {
            return false;
        }

        // 8. Verificar asignación al recurso.
        return $accessRequest->resource
            ->approvers()
            ->where('users.id', $user->id)
            ->exists();
    }
}
