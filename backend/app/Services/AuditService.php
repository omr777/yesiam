<?php

namespace App\Services;

use App\Models\AccessGrant;
use App\Models\AccessRequest;
use App\Models\AuditLog;
use App\Models\Department;
use App\Models\Resource;
use App\Models\User;

class AuditService
{
    /*
    |--------------------------------------------------------------------------
    | IDENTIDADES
    |--------------------------------------------------------------------------
    */

    public function identityCreated(
        User $actor,
        User $target,
        ?string $ipAddress
    ): void {
        $this->write(
            $actor,
            'identity.created',
            $target,
            [
                'department_id' => $target->department_id,
                'initial_status' => $target->status,
            ],
            $ipAddress
        );
    }

    public function identityMoved(
        User $actor,
        User $target,
        ?int $previousDepartmentId,
        int $newDepartmentId,
        ?string $ipAddress
    ): void {
        $this->write(
            $actor,
            'identity.department_changed',
            $target,
            [
                'previous_department_id' => $previousDepartmentId,
                'new_department_id' => $newDepartmentId,
            ],
            $ipAddress
        );
    }

    public function identityDeactivated(
        User $actor,
        User $target,
        string $previousStatus,
        ?string $ipAddress
    ): void {
        $this->write(
            $actor,
            'identity.deactivated',
            $target,
            [
                'previous_status' => $previousStatus,
                'new_status' => 'disabled',
            ],
            $ipAddress
        );
    }

    /*
    |--------------------------------------------------------------------------
    | DEPARTAMENTOS
    |--------------------------------------------------------------------------
    */

    public function departmentCreated(
        User $actor,
        Department $target,
        ?string $ipAddress
    ): void {
        $this->write(
            $actor,
            'department.created',
            $target,
            [
                'name' => $target->name,
                'code' => $target->code,
                'is_active' => (bool) $target->is_active,
            ],
            $ipAddress
        );
    }

    public function departmentUpdated(
        User $actor,
        Department $target,
        array $changes,
        ?string $ipAddress
    ): void {
        $this->write(
            $actor,
            'department.updated',
            $target,
            [
                'changes' => $changes,
            ],
            $ipAddress
        );
    }

    public function departmentDeactivated(
        User $actor,
        Department $target,
        ?string $ipAddress
    ): void {
        $this->write(
            $actor,
            'department.deactivated',
            $target,
            [
                'previous_is_active' => true,
                'new_is_active' => false,
            ],
            $ipAddress
        );
    }

    /*
    |--------------------------------------------------------------------------
    | RECURSOS
    |--------------------------------------------------------------------------
    */

    public function resourceCreated(
        User $actor,
        Resource $target,
        ?string $ipAddress
    ): void {
        $this->write(
            $actor,
            'resource.created',
            $target,
            [
                'name' => $target->name,
                'code' => $target->code,
                'is_active' => (bool) $target->is_active,
            ],
            $ipAddress
        );
    }

    public function resourceUpdated(
        User $actor,
        Resource $target,
        array $changes,
        ?string $ipAddress
    ): void {
        $this->write(
            $actor,
            'resource.updated',
            $target,
            [
                'changes' => $changes,
            ],
            $ipAddress
        );
    }

    public function resourceDeactivated(
        User $actor,
        Resource $target,
        ?string $ipAddress
    ): void {
        $this->write(
            $actor,
            'resource.deactivated',
            $target,
            [
                'previous_is_active' => true,
                'new_is_active' => false,
            ],
            $ipAddress
        );
    }

    /*
    |--------------------------------------------------------------------------
    | SOLICITUDES DE ACCESO
    |--------------------------------------------------------------------------
    */

    public function accessRequested(
        User $actor,
        AccessRequest $target,
        ?string $ipAddress
    ): void {
        $this->write(
            $actor,
            'access.requested',
            $target,
            [
                'resource_id' => $target->resource_id,
                'status' => $target->status,
            ],
            $ipAddress
        );
    }

    /*
    |--------------------------------------------------------------------------
    | ASIGNACIÓN DE RESPONSABLES
    |--------------------------------------------------------------------------
    */

    public function resourceApproverAssigned(
        User $actor,
        Resource $resource,
        User $approver,
        ?string $ipAddress
    ): void {
        $this->write(
            $actor,
            'resource.approver_assigned',
            $resource,
            [
                'approver_user_id' => $approver->id,
                'assigned_by_user_id' => $actor->id,
            ],
            $ipAddress
        );
    }

    /*
    |--------------------------------------------------------------------------
    | AUDITORÍA DE APROBACIONES Y RECHAZOS
    |--------------------------------------------------------------------------
    */

    public function accessDecisionRecorded(
        User $actor,
        AccessRequest $target,
        ?string $ipAddress
    ): void {
        /*
         * Determinar el evento según el estado final.
         * Solo se permiten approved y rejected.
         */
        $action = match ($target->status) {
            'approved' => 'access.approved',
            'rejected' => 'access.rejected',

            default => throw new \LogicException(
                'Estado inválido para auditar una decisión de acceso.'
            ),
        };

        /*
         * Registrar la decisión.
         */
        $this->write(
            $actor,
            $action,
            $target,
            [
                'resource_id' => $target->resource_id,
                'previous_status' => 'pending',
                'new_status' => $target->status,
                'decided_by_user_id' => $actor->id,
            ],
            $ipAddress
        );
    }

    /*
    |--------------------------------------------------------------------------
    | AUDITORÍA DE CONCESIONES DE ACCESO
    |--------------------------------------------------------------------------
    */

    public function accessGranted(
        User $actor,
        AccessRequest $accessRequest,
        AccessGrant $grant,
        ?string $ipAddress
    ): void {
        /*
         * Registrar la concesión de acceso.
         *
         * La solicitud es la entidad auditada.
         * El ID de la concesión se incluye
         * en los metadatos.
         */
        $this->write(
            $actor,
            'access.granted',
            $accessRequest,
            [
                'access_grant_id' => $grant->id,
                'resource_id' => $grant->resource_id,
                'beneficiary_user_id' => $grant->user_id,
                'status' => $grant->status,
                'granted_by_user_id' => $actor->id,
            ],
            $ipAddress
        );
    }

    /*
    |--------------------------------------------------------------------------
    | ESCRITURA CENTRALIZADA
    |--------------------------------------------------------------------------
    */

    private function write(
        User $actor,
        string $action,
        User|Department|Resource|AccessRequest $target,
        array $metadata,
        ?string $ipAddress
    ): void {
        /*
         * Identificar el tipo de entidad auditada.
         */
        $entityType = match (true) {
            $target instanceof Department => 'department',
            $target instanceof Resource => 'resource',
            $target instanceof AccessRequest => 'access_request',
            default => 'user',
        };

        /*
         * Crear el registro de auditoría.
         */
        AuditLog::create([
            'actor_user_id' => $actor->id,
            'action' => $action,
            'entity_type' => $entityType,
            'entity_id' => $target->id,
            'outcome' => 'success',
            'metadata' => $metadata,
            'ip_address' => $ipAddress,
        ]);
    }
}
