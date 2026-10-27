<?php

namespace Tests\Feature;

use App\Models\AccessGrant;
use App\Models\AccessRequest;
use App\Models\Resource;
use App\Models\Role;
use App\Models\User;
use Database\Seeders\RbacSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Gate;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class AccessLifecycleIntegrationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RbacSeeder::class);
    }

    /*
    |--------------------------------------------------------------------------
    | MÉTODOS AUXILIARES
    |--------------------------------------------------------------------------
    */

    private function makeUser(string $roleCode): User
    {
        $user = User::factory()->create([
            'status' => 'active',
        ]);

        $role = Role::query()
            ->where('code', $roleCode)
            ->firstOrFail();

        $user->roles()->attach($role->id);

        return $user;
    }

    /*
    |--------------------------------------------------------------------------
    | AUTENTICACIÓN PARA LAS PRUEBAS DE API
    |--------------------------------------------------------------------------
    */

    private function authenticate(User $user): void
    {
        Sanctum::actingAs($user, ['*']);
    }

    /*
    |--------------------------------------------------------------------------
    | PREPARACIÓN DEL ESCENARIO
    |--------------------------------------------------------------------------
    */

    private function scenario(): array
    {
        $admin = $this->makeUser('iam_admin');

        $manager = $this->makeUser('resource_manager');

        $employee = $this->makeUser('employee');

        $resource = Resource::create([
            'name' => 'VPN corporativa',
            'code' => 'VPN',
            'description' => 'Acceso remoto empresarial',
            'is_active' => true,
        ]);

        /*
         * Asignar al responsable del recurso.
         */
        $resource->approvers()->attach(
            $manager->id,
            [
                'assigned_by_user_id' => $admin->id,
            ]
        );

        /*
         * Confirmar los permisos RBAC.
         */
        $this->assertTrue(
            $manager->hasPermission('access.approve'),
            'El Resource Manager no tiene access.approve.'
        );

        $this->assertTrue(
            $admin->hasPermission('resources.manage'),
            'El administrador IAM no tiene resources.manage.'
        );

        /*
         * Confirmar que Carlos está asignado
         * como responsable de aprobación.
         */
        $this->assertTrue(
            $resource->approvers()
                ->where('users.id', $manager->id)
                ->exists(),
            'El responsable no está asignado al recurso.'
        );

        return compact(
            'admin',
            'manager',
            'employee',
            'resource'
        );
    }

    /*
    |--------------------------------------------------------------------------
    | PRUEBA 1: CICLO COMPLETO DE ACCESO
    |--------------------------------------------------------------------------
    */

    public function test_complete_access_lifecycle(): void
    {
        $data = $this->scenario();

        $admin = $data['admin'];
        $manager = $data['manager'];
        $employee = $data['employee'];
        $resource = $data['resource'];

        /*
         * ==============================================================
         * PASO 1: EL EMPLEADO SOLICITA ACCESO
         * ==============================================================
         */

        $this->authenticate($employee);

        $this->postJson(
            '/api/access-requests',
            [
                'resource_id' => $resource->id,
                'justification' =>
                    'Necesito VPN para realizar mis actividades laborales.',
            ]
        )->assertCreated();

        $accessRequest = AccessRequest::query()
            ->where('requester_user_id', $employee->id)
            ->where('resource_id', $resource->id)
            ->firstOrFail();

        $this->assertSame(
            'pending',
            $accessRequest->status
        );

        /*
         * ==============================================================
         * PASO 2: VALIDAR RESPONSABLE Y APROBAR
         * ==============================================================
         */

        /*
         * Comprobar directamente la política
         * antes de ejecutar el endpoint.
         *
         * Si falla, sabremos que el 403
         * proviene de la autorización.
         */
        $this->assertTrue(
            Gate::forUser($manager)->allows(
                'decide',
                $accessRequest
            ),
            'AccessRequestPolicy::decide() rechazó al responsable.'
        );

        /*
         * Cambiar identidad autenticada:
         * Employee -> Resource Manager.
         */
        $this->authenticate($manager);

        $this->postJson(
            "/api/access-requests/{$accessRequest->id}/approve",
            [
                'decision_reason' =>
                    'La solicitud está justificada por sus funciones.',
            ]
        )
            ->assertOk()
            ->assertJsonPath('data.status', 'approved');

        $accessRequest->refresh();

        $this->assertDatabaseHas('access_requests', [
            'id' => $accessRequest->id,
            'requester_user_id' => $employee->id,
            'resource_id' => $resource->id,
            'status' => 'approved',
            'decided_by_user_id' => $manager->id,
        ]);

        /*
         * ==============================================================
         * PASO 3: EL ADMINISTRADOR CONCEDE ACCESO
         * ==============================================================
         */

        $this->authenticate($admin);

        $this->postJson(
            "/api/access-requests/{$accessRequest->id}/grant"
        )
            ->assertCreated()
            ->assertJsonPath('data.status', 'active');

        $grant = AccessGrant::query()
            ->where('access_request_id', $accessRequest->id)
            ->firstOrFail();

        $this->assertDatabaseHas('access_grants', [
            'id' => $grant->id,
            'access_request_id' => $accessRequest->id,
            'user_id' => $employee->id,
            'resource_id' => $resource->id,
            'status' => 'active',
            'granted_by_user_id' => $admin->id,
        ]);

        $this->assertNotNull($grant->granted_at);

        /*
         * ==============================================================
         * PASO 4: EL EMPLEADO CONSULTA SUS ACCESOS ACTIVOS
         * ==============================================================
         */

        $this->authenticate($employee);

        $this->getJson(
            "/api/users/{$employee->id}/access-grants?status=active"
        )
            ->assertOk()
            ->assertJsonPath('user.id', $employee->id)
            ->assertJsonPath('pagination.total', 1)
            ->assertJsonPath('data.0.id', $grant->id)
            ->assertJsonPath('data.0.status', 'active')
            ->assertJsonPath(
                'data.0.resource.code',
                'VPN'
            );

        /*
         * ==============================================================
         * PASO 5: EL ADMINISTRADOR REVOCA ACCESO
         * ==============================================================
         */

        $this->authenticate($admin);

        $this->postJson(
            "/api/access-grants/{$grant->id}/revoke",
            [
                'revocation_reason' =>
                    'El usuario ya no requiere acceso remoto.',
            ]
        )
            ->assertOk()
            ->assertJsonPath('data.status', 'revoked')
            ->assertJsonPath(
                'data.revoked_by_user_id',
                $admin->id
            );

        $grant->refresh();

        $this->assertDatabaseHas('access_grants', [
            'id' => $grant->id,
            'status' => 'revoked',
            'granted_by_user_id' => $admin->id,
            'revoked_by_user_id' => $admin->id,
        ]);

        $this->assertNotNull($grant->revoked_at);

        /*
         * ==============================================================
         * PASO 6: EL ACCESO YA NO DEBE FIGURAR COMO ACTIVO
         * ==============================================================
         */

        $this->authenticate($employee);

        $this->getJson(
            "/api/users/{$employee->id}/access-grants?status=active"
        )
            ->assertOk()
            ->assertJsonCount(0, 'data')
            ->assertJsonPath('pagination.total', 0);

        /*
         * ==============================================================
         * PASO 7: EL ACCESO DEBE APARECER EN REVOCADOS
         * ==============================================================
         */

        $this->getJson(
            "/api/users/{$employee->id}/access-grants?status=revoked"
        )
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('pagination.total', 1)
            ->assertJsonPath('data.0.id', $grant->id)
            ->assertJsonPath('data.0.status', 'revoked');

        /*
         * ==============================================================
         * PASO 8: VERIFICAR AUDITORÍA COMPLETA
         * ==============================================================
         */

        $expectedActions = [
            'access.requested',
            'access.approved',
            'access.granted',
            'access.revoked',
        ];

        foreach ($expectedActions as $action) {
            $this->assertDatabaseHas('audit_logs', [
                'action' => $action,
                'entity_type' => 'access_request',
                'entity_id' => $accessRequest->id,
                'outcome' => 'success',
            ]);
        }

        $this->assertDatabaseCount('audit_logs', 4);

        /*
         * Verificar que la aprobación original
         * permanezca registrada.
         */
        $this->assertDatabaseHas('access_requests', [
            'id' => $accessRequest->id,
            'status' => 'approved',
            'decided_by_user_id' => $manager->id,
        ]);
    }

    /*
    |--------------------------------------------------------------------------
    | PRUEBA 2: UNA SOLICITUD RECHAZADA NO PUEDE CONCEDERSE
    |--------------------------------------------------------------------------
    */

    public function test_rejected_request_cannot_be_provisioned(): void
    {
        $data = $this->scenario();

        $admin = $data['admin'];
        $manager = $data['manager'];
        $employee = $data['employee'];
        $resource = $data['resource'];

        /*
         * ==============================================================
         * PASO 1: EL EMPLEADO CREA SU SOLICITUD
         * ==============================================================
         */

        $this->authenticate($employee);

        $this->postJson(
            '/api/access-requests',
            [
                'resource_id' => $resource->id,
                'justification' =>
                    'Solicito acceso a la VPN para un nuevo proyecto.',
            ]
        )->assertCreated();

        $accessRequest = AccessRequest::query()
            ->where('requester_user_id', $employee->id)
            ->where('resource_id', $resource->id)
            ->firstOrFail();

        $this->assertSame(
            'pending',
            $accessRequest->status
        );

        /*
         * ==============================================================
         * PASO 2: VALIDAR RESPONSABLE Y RECHAZAR
         * ==============================================================
         */

        $this->assertTrue(
            Gate::forUser($manager)->allows(
                'decide',
                $accessRequest
            ),
            'AccessRequestPolicy::decide() rechazó al responsable.'
        );

        $this->authenticate($manager);

        $this->postJson(
            "/api/access-requests/{$accessRequest->id}/reject",
            [
                'decision_reason' =>
                    'El acceso no es necesario para este puesto.',
            ]
        )
            ->assertOk()
            ->assertJsonPath('data.status', 'rejected');

        $accessRequest->refresh();

        $this->assertDatabaseHas('access_requests', [
            'id' => $accessRequest->id,
            'status' => 'rejected',
            'decided_by_user_id' => $manager->id,
        ]);

        /*
         * ==============================================================
         * PASO 3: INTENTAR CONCEDER LA SOLICITUD RECHAZADA
         * ==============================================================
         */

        $this->authenticate($admin);

        $this->postJson(
            "/api/access-requests/{$accessRequest->id}/grant"
        )->assertForbidden();

        /*
         * ==============================================================
         * PASO 4: COMPROBAR QUE NO SE CREÓ NINGUNA CONCESIÓN
         * ==============================================================
         */

        $this->assertDatabaseCount(
            'access_grants',
            0
        );

        $this->assertDatabaseHas('access_requests', [
            'id' => $accessRequest->id,
            'status' => 'rejected',
        ]);

        /*
         * ==============================================================
         * PASO 5: VERIFICAR AUDITORÍA
         * ==============================================================
         */

        $this->assertDatabaseHas('audit_logs', [
            'action' => 'access.requested',
            'entity_id' => $accessRequest->id,
        ]);

        $this->assertDatabaseHas('audit_logs', [
            'action' => 'access.rejected',
            'entity_id' => $accessRequest->id,
        ]);

        $this->assertDatabaseMissing('audit_logs', [
            'action' => 'access.granted',
            'entity_id' => $accessRequest->id,
        ]);

        $this->assertDatabaseCount('audit_logs', 2);
    }
}
