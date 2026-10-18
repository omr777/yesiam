<?php

namespace Tests\Feature;

use App\Models\AuditLog;
use App\Models\Role;
use App\Models\User;
use Database\Seeders\RbacSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AuditLogAccessTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        // Crear roles y permisos para estas pruebas.
        $this->seed(RbacSeeder::class);
    }

    private function makeUser(string $roleCode): User
    {
        $user = User::factory()->create([
            'status' => 'active',
        ]);

        $role = Role::where('code', $roleCode)
            ->firstOrFail();

        $user->roles()->attach($role->id);

        return $user;
    }

    private function createLog(
        User $actor,
        string $action,
        int $entityId
    ): AuditLog {
        return AuditLog::create([
            'actor_user_id' => $actor->id,
            'action' => $action,
            'entity_type' => 'user',
            'entity_id' => $entityId,
            'outcome' => 'success',
            'metadata' => [
                'source' => 'automated_test',
            ],
            'ip_address' => '127.0.0.1',
        ]);
    }

    /*
    |--------------------------------------------------------------------------
    | PRUEBA 1 - Visitante
    |--------------------------------------------------------------------------
    */
    public function test_guest_cannot_view_audit_logs(): void
    {
        $this->getJson('/api/audit-logs')
            ->assertStatus(401);
    }

    /*
    |--------------------------------------------------------------------------
    | PRUEBA 2 - Empleado sin permiso
    |--------------------------------------------------------------------------
    */
    public function test_employee_cannot_view_audit_logs(): void
    {
        $employee = $this->makeUser('employee');

        $this->actingAs($employee, 'web')
            ->getJson('/api/audit-logs')
            ->assertStatus(403);
    }

    /*
    |--------------------------------------------------------------------------
    | PRUEBA 3 - Administrador autorizado
    |--------------------------------------------------------------------------
    */
    public function test_admin_can_view_audit_logs(): void
    {
        $admin = $this->makeUser('iam_admin');

        $log = $this->createLog(
            $admin,
            'identity.created',
            15
        );

        $this->actingAs($admin, 'web')
            ->getJson('/api/audit-logs')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', $log->id)
            ->assertJsonPath(
                'data.0.action',
                'identity.created'
            )
            ->assertJsonPath(
                'data.0.actor_user_id',
                $admin->id
            );
    }

    /*
    |--------------------------------------------------------------------------
    | PRUEBA 4 - Filtros y paginación
    |--------------------------------------------------------------------------
    */
    public function test_admin_can_filter_and_paginate_logs(): void
    {
        $admin = $this->makeUser('iam_admin');

        $this->createLog(
            $admin,
            'identity.created',
            10
        );

        $this->createLog(
            $admin,
            'identity.created',
            11
        );

        $this->createLog(
            $admin,
            'identity.deactivated',
            12
        );

        // Solo deben aparecer dos eventos identity.created.
        $this->actingAs($admin, 'web')
            ->getJson(
                '/api/audit-logs?action=identity.created'
            )
            ->assertOk()
            ->assertJsonCount(2, 'data')
            ->assertJsonPath('total', 2);

        // Solicitar una página con dos registros.
        $this->getJson('/api/audit-logs?per_page=2')
            ->assertOk()
            ->assertJsonCount(2, 'data')
            ->assertJsonPath('total', 3)
            ->assertJsonPath('per_page', 2);

        // Filtrar por identidad afectada.
        $this->getJson(
            '/api/audit-logs?entity_type=user&entity_id=12'
        )
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath(
                'data.0.action',
                'identity.deactivated'
            );
    }

    /*
    |--------------------------------------------------------------------------
    | PRUEBA 5 - Administrador suspendido
    |--------------------------------------------------------------------------
    */
    public function test_suspended_admin_cannot_view_audit_logs(): void
    {
        $admin = $this->makeUser('iam_admin');

        $admin->forceFill([
            'status' => 'suspended',
        ])->save();

        $this->actingAs($admin, 'web')
            ->getJson('/api/audit-logs')
            ->assertStatus(403);
    }

    /*
    |--------------------------------------------------------------------------
    | PRUEBA 6 - API solo de consulta
    |--------------------------------------------------------------------------
    */
    public function test_audit_api_does_not_allow_creating_logs(): void
    {
        $admin = $this->makeUser('iam_admin');

        $this->actingAs($admin, 'web')
            ->postJson('/api/audit-logs', [
                'action' => 'fake.event',
                'entity_type' => 'user',
                'entity_id' => 99,
                'outcome' => 'success',
            ])
            ->assertStatus(405);

        $this->assertDatabaseCount('audit_logs', 0);
    }
}
