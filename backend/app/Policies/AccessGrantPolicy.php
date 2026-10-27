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
         * 1. El operador debe tener
         * una cuenta activa.
         */
        if ($actor->status !== 'active') {
            return false;
        }

        /*
         * 2. Debe disponer de privilegios
         * administrativos.
         *
         * Temporalmente reutilizamos
         * resources.manage.
         */
        if (! $actor->hasPermission('resources.manage')) {
            return false;
        }

        /*
         * 3. Separation of Duties.
         *
         * El operador no puede ejecutar
         * la revocación de su propia
         * concesión de acceso.
         */
        if ((int) $accessGrant->user_id === (int) $actor->id) {
            return false;
        }

        /*
         * No exigimos que el usuario beneficiario
         * ni el recurso estén activos.
         *
         * Precisamente puede ser necesario
         * revocar accesos después de desactivar
         * una identidad o un recurso.
         *
         * El estado active/revoked será comprobado
         * dentro de la transacción del controlador.
         */

        return true;
    }
}
