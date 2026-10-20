<?php

namespace App\Services;

use App\Models\AuditLog;
use App\Models\Department;
use App\Models\Resource;
use App\Models\User;

class AuditService
{
    /*
    |--------------------------------------------------------------------------
    | AUDITORÍA DE IDENTIDADES
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
    | AUDITORÍA DE DEPARTAMENTOS
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
    | AUDITORÍA DE RECURSOS
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
    | ESCRITURA CENTRALIZADA
    |--------------------------------------------------------------------------
    */

    private function write(
        User $actor,
        string $action,
        User|Department|Resource $target,
        array $metadata,
        ?string $ipAddress
    ): void {
        $entityType = match (true) {
            $target instanceof Department => 'department',
            $target instanceof Resource => 'resource',
            default => 'user',
        };

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
