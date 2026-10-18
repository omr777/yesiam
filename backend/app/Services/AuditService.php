<?php

namespace App\Services;

use App\Models\AuditLog;
use App\Models\Department;
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
    | ESCRITURA CENTRALIZADA
    |--------------------------------------------------------------------------
    */

    private function write(
        User $actor,
        string $action,
        User|Department $target,
        array $metadata,
        ?string $ipAddress
    ): void {
        AuditLog::create([
            'actor_user_id' => $actor->id,
            'action' => $action,
            'entity_type' => $target instanceof Department
                ? 'department'
                : 'user',
            'entity_id' => $target->id,
            'outcome' => 'success',
            'metadata' => $metadata,
            'ip_address' => $ipAddress,
        ]);
    }
}
