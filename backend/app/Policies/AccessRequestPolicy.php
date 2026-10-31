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
    | FASE 20 - COLA DE CONCESIÓN
    |--------------------------------------------------------------------------
    |
    | Solicitudes aprobadas pendientes de
    | provisionamiento. Requiere access.grant.
    |
    */

    public function viewGrantQueue(User $user): bool
    {
        return $user->status === 'active'
            && $user->hasPermission('access.grant');
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
        if ($user->status !== 'active') {
            return false;
        }

        if (! $user->hasPermission('access.approve')) {
            return false;
        }

        if ($accessRequest->status !== 'pending') {
            return false;
        }

        if ($accessRequest->requester_user_id === $user->id) {
            return false;
        }

        $accessRequest->loadMissing([
            'resource',
            'requester',
        ]);

        if (
            ! $accessRequest->resource
            || ! $accessRequest->resource->is_active
        ) {
            return false;
        }

        if (
            ! $accessRequest->requester
            || $accessRequest->requester->status !== 'active'
        ) {
            return false;
        }

        return $accessRequest->resource
            ->approvers()
            ->where('users.id', $user->id)
            ->exists();
    }

    /*
    |--------------------------------------------------------------------------
    | EJECUTAR UNA CONCESIÓN INTERNA DE ACCESO
    |--------------------------------------------------------------------------
    */

    public function grant(
        User $actor,
        AccessRequest $accessRequest
    ): bool {
        // 1. El administrador debe estar activo.
        if ($actor->status !== 'active') {
            return false;
        }

        // 2. Requiere privilegios de administración.
        if (! $actor->hasPermission('access.grant')) {
            return false;
        }

        // 3. La solicitud debe estar aprobada.
        if ($accessRequest->status !== 'approved') {
            return false;
        }

        // 4. Evitar que un administrador se conceda
        // acceso a sí mismo.
        if ($accessRequest->requester_user_id === $actor->id) {
            return false;
        }

        // 5. Recuperar usuario y recurso.
        $accessRequest->loadMissing([
            'resource',
            'requester',
        ]);

        // 6. El recurso debe estar activo.
        if (
            ! $accessRequest->resource
            || ! $accessRequest->resource->is_active
        ) {
            return false;
        }

        // 7. El beneficiario debe estar activo.
        if (
            ! $accessRequest->requester
            || $accessRequest->requester->status !== 'active'
        ) {
            return false;
        }

        return true;
    }
}
