<?php

namespace App\Services;

use App\Models\AuditLog;
use App\Models\User;

class AuditService
{
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

    private function write(
        User $actor,
        string $action,
        User $target,
        array $metadata,
        ?string $ipAddress
    ): void {
        AuditLog::create([
            'actor_user_id' => $actor->id,
            'action' => $action,
            'entity_type' => 'user',
            'entity_id' => $target->id,
            'outcome' => 'success',
            'metadata' => $metadata,
            'ip_address' => $ipAddress,
        ]);
    }
}
