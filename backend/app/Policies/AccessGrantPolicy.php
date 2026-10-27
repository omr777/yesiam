<?php

namespace App\Policies;

use App\Models\AccessGrant;
use App\Models\User;

class AccessGrantPolicy
{
    /*
    |--------------------------------------------------------------------------
    | REVOCAR UNA CONCESIÓN DE ACCESO
    |--------------------------------------------------------------------------
    */

    public function revoke(
        User $actor,
        AccessGrant $accessGrant
    ): bool {
        /*
         * 1. El operador debe estar activo.
         */
        if ($actor->status !== 'active') {
            return false;
        }

        /*
         * 2. Exigir el permiso específico
         * para revocar concesiones.
         *
         * Ya no utilizamos resources.manage.
         */
        if (! $actor->hasPermission('access.revoke')) {
            return false;
        }

        /*
         * 3. Separation of Duties.
         *
         * El operador no puede revocar
         * su propia concesión.
         */
        if (
            (int) $accessGrant->user_id
            === (int) $actor->id
        ) {
            return false;
        }

        /*
         * 4. No exigimos que el beneficiario
         * ni el recurso estén activos.
         *
         * Una concesión puede necesitar
         * revocarse después de una baja.
         *
         * El controlador verifica que
         * la concesión siga activa
         * dentro de la transacción.
         */
        return true;
    }

    /*
    |--------------------------------------------------------------------------
    | CONSULTAR CONCESIONES DE UN USUARIO
    |--------------------------------------------------------------------------
    */

    public function viewForUser(
        User $actor,
        User $targetUser
    ): bool {
        /*
         * 1. Quien realiza la consulta
         * debe tener una cuenta activa.
         */
        if ($actor->status !== 'active') {
            return false;
        }

        /*
         * 2. Un usuario activo siempre
         * puede consultar su propio
         * inventario de concesiones.
         */
        if (
            (int) $actor->id
            === (int) $targetUser->id
        ) {
            return true;
        }

        /*
         * 3. Para consultar los accesos
         * de otra identidad, exigimos
         * access.view_all.
         *
         * Ya no utilizamos resources.manage.
         */
        return $actor->hasPermission('access.view_all');
    }
}
