<?php

namespace App\Services;

use App\Models\Department;
use App\Models\Resource;
use App\Models\User;

class AccessEligibilityService
{
    /*
    |--------------------------------------------------------------------------
    | EVALUAR ELEGIBILIDAD
    |--------------------------------------------------------------------------
    |
    | Determina si una identidad cumple
    | las condiciones para acceder
    | a un recurso.
    |
    | Este método debe ejecutarse dentro
    | de una transacción cuando forme parte
    | de una operación sensible, como:
    |
    | - Solicitar acceso.
    | - Conceder acceso.
    | - Cambiar de departamento.
    |
    | Los modelos User y Resource deben
    | haber sido bloqueados previamente
    | por el controlador correspondiente.
    |
    */

    public function evaluate(
        User $user,
        Resource $resource
    ): array {

        /*
         * 1. ESTADO DE LA IDENTIDAD
         *
         * Una identidad que no está activa
         * no debe recibir nuevos accesos.
         */
        if ($user->status !== 'active') {
            return $this->deny(
                'user_inactive'
            );
        }

        /*
         * 2. ESTADO DEL RECURSO
         */
        if (! $resource->is_active) {
            return $this->deny(
                'resource_inactive'
            );
        }

        /*
         * 3. RECURSO SIN RESTRICCIONES
         *
         * No exige pertenecer a un
         * departamento específico.
         */
        if (! $resource->department_restricted) {
            return $this->allow(
                'unrestricted_resource'
            );
        }

        /*
         * 4. DEPARTAMENTO OBLIGATORIO
         *
         * Para recursos restringidos,
         * el usuario necesita departamento.
         */
        $departmentId = $user->department_id;

        if ($departmentId === null) {
            return $this->deny(
                'department_missing'
            );
        }

        /*
         * 5. LECTURA BLOQUEANTE
         * DEL DEPARTAMENTO
         *
         * FASE 17.7.5
         *
         * Utilizamos lockForUpdate() para
         * consultar el estado vigente.
         *
         * Dentro de una transacción,
         * el bloqueo se conserva hasta
         * que termina la operación.
         */
        $department = Department::query()
            ->whereKey((int) $departmentId)
            ->lockForUpdate()
            ->first();

        /*
         * El departamento podría haber
         * sido eliminado o desactivado.
         */
        if (
            $department === null
            || ! $department->is_active
        ) {
            return $this->deny(
                'department_inactive'
            );
        }

        /*
         * 6. COMPROBAR ASIGNACIÓN
         * DEPARTAMENTAL VIGENTE
         *
         * Consultamos la relación entre
         * recurso y departamento usando
         * también una lectura bloqueante.
         *
         * Así evitamos depender únicamente
         * de una instantánea anterior
         * de la transacción.
         *
         * El controlador debe mantener
         * previamente bloqueado el recurso
         * para coordinar los cambios
         * de su lista de elegibilidad.
         */
        $eligibleDepartment = $resource
            ->eligibleDepartments()
            ->where(
                'departments.id',
                (int) $departmentId
            )
            ->lockForUpdate()
            ->first();

        if ($eligibleDepartment === null) {
            return $this->deny(
                'department_not_allowed'
            );
        }

        /*
         * 7. ELEGIBILIDAD CONFIRMADA
         */
        return $this->allow(
            'eligible'
        );
    }

    /*
    |--------------------------------------------------------------------------
    | CONSULTA BOOLEANA
    |--------------------------------------------------------------------------
    */

    public function isEligible(
        User $user,
        Resource $resource
    ): bool {
        $decision = $this->evaluate(
            $user,
            $resource
        );

        return $decision['eligible'];
    }

    /*
    |--------------------------------------------------------------------------
    | RESPUESTA POSITIVA
    |--------------------------------------------------------------------------
    */

    private function allow(string $reason): array
    {
        return [
            'eligible' => true,
            'reason' => $reason,
        ];
    }

    /*
    |--------------------------------------------------------------------------
    | RESPUESTA NEGATIVA
    |--------------------------------------------------------------------------
    */

    private function deny(string $reason): array
    {
        return [
            'eligible' => false,
            'reason' => $reason,
        ];
    }
}
