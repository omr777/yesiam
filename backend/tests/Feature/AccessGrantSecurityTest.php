<?php

namespace Tests\Feature;

use App\Models\AccessGrant;
use App\Models\AccessRequest;
use App\Models\AuditLog;
use App\Models\Resource;
use App\Models\Role;
use App\Models\User;
use App\Services\AuditService;
use Database\Seeders\RbacSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Mockery\MockInterface;
use RuntimeException;
use Tests\TestCase;

class AccessGrantSecurityTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RbacSeeder::class);
    }

    /*
    |--------------------------------------------------------------------------
    | DATOS AUXILIARES PARA LAS PRUEBAS
    |--------------------------------------------------------------------------
    */

    private function makeUser(
        string $roleCode = 'employee',
        string $status = 'active'
    ): User {
        $user = User::factory()->create([
            'status' => $status,
        ]);

        $role = Role::where('code', $roleCode)
            ->firstOrFail();

        $user->roles()->attach($role->id);

        return $user;
    }

    private function makeResource(): Resource
    {
        return Resource::create([
            'name' => 'VPN corporativa',
            'code' => 'VPN',
            'description' => 'Acceso remoto empresarial',
            'is_active' => true,
        ]);
    }

    private function makeApprovedRequest(
        User $employee,
        Resource $resource,
        User $approver
    ): AccessRequest {
        $accessRequest = AccessRequest::create([
            'requester_user_id' => $employee->id,
            'resource_id' => $resource->id,
            'justification' => 'Necesito VPN para trabajar.',
        ]);

        // Preparar una solicitud previamente aprobada.
        // No se está probando aquí el endpoint /approve.
        $accessRequest->forceFill([
            'status' => 'approved',
            'decided_by_user_id' => $approver->id,
            'decision_reason' => 'Solicitud justificada y autorizada.',
            'decided_at' => now(),
        ])->save();

        return $accessRequest->refresh();
    }

    private function scenario(): array
    {
        $admin = $this->makeUser('iam_admin');
        $manager = $this->makeUser('resource_manager');
        $employee = $this->makeUser('employee');
        $resource = $this->makeResource();

        $resource->approvers()->attach(
            $manager->id,
            ['assigned_by_user_id' => $admin->id]
        );

        $accessRequest = $this->makeApprovedRequest(
            $employee,
            $resource,
            $manager
        );

        return [
            'admin' => $admin,
            'manager' => $manager,
            'employee' => $employee,
            'resource' => $resource,
            'request' => $accessRequest,
        ];
    }

    private function endpoint(AccessRequest $request): string
    {
        return "/api/access-requests/{$request->id}/grant";
    }

    /*
    |--------------------------------------------------------------------------
    | 1. VISITANTE NO PUEDE CONCEDER ACCESO
    |--------------------------------------------------------------------------
    */

    public function test_guest_cannot_grant_access(): void
    {
        $data = $this->scenario();

        $this->postJson(
            $this->endpoint($data['request'])
        )->assertUnauthorized();

        $this->assertDatabaseCount('access_grants', 0);
    }

    /*
    |--------------------------------------------------------------------------
    | 2. EMPLEADO NO PUEDE CONCEDER ACCESO
    |--------------------------------------------------------------------------
    */

    public function test_employee_cannot_grant_access(): void
    {
        $data = $this->scenario();

        $this->actingAs($data['employee'], 'web')
            ->postJson($this->endpoint($data['request']))
            ->assertForbidden();

        $this->assertDatabaseCount('access_grants', 0);
    }

    /*
    |--------------------------------------------------------------------------
    | 3. RESOURCE MANAGER NO TIENE PERMISO DE CONCESIÓN
    |--------------------------------------------------------------------------
    */

    public function test_resource_manager_cannot_grant_access(): void
    {
        $data = $this->scenario();

        $this->actingAs($data['manager'], 'web')
            ->postJson($this->endpoint($data['request']))
            ->assertForbidden();

        $this->assertDatabaseCount('access_grants', 0);
    }

    /*
    |--------------------------------------------------------------------------
    | 4. ADMINISTRADOR AUTORIZADO PUEDE CONCEDER
    |--------------------------------------------------------------------------
    */

    public function test_admin_can_grant_approved_access(): void
    {
        $data = $this->scenario();

        $this->actingAs($data['admin'], 'web')
            ->postJson($this->endpoint($data['request']))
            ->assertCreated()
            ->assertJsonPath('data.status', 'active')
            ->assertJsonPath(
                'data.user_id',
                $data['employee']->id
            )
            ->assertJsonPath(
                'data.resource_id',
                $data['resource']->id
            );

        $this->assertDatabaseHas('access_grants', [
            'access_request_id' => $data['request']->id,
            'user_id' => $data['employee']->id,
            'resource_id' => $data['resource']->id,
            'status' => 'active',
            'granted_by_user_id' => $data['admin']->id,
        ]);

        $grant = AccessGrant::firstOrFail();

        $this->assertNotNull($grant->granted_at);

        $this->assertDatabaseHas('audit_logs', [
            'actor_user_id' => $data['admin']->id,
            'action' => 'access.granted',
            'entity_type' => 'access_request',
            'entity_id' => $data['request']->id,
            'outcome' => 'success',
        ]);

        $audit = AuditLog::firstOrFail();

        $this->assertEquals(
            $grant->id,
            $audit->metadata['access_grant_id']
        );

        $this->assertEquals(
            $data['employee']->id,
            $audit->metadata['beneficiary_user_id']
        );
    }

    /*
    |--------------------------------------------------------------------------
    | 5. NO CONCEDER SOLICITUDES PENDIENTES
    |--------------------------------------------------------------------------
    */

    public function test_pending_request_cannot_be_granted(): void
    {
        $data = $this->scenario();

        $data['request']->forceFill([
            'status' => 'pending',
            'decided_by_user_id' => null,
            'decision_reason' => null,
            'decided_at' => null,
        ])->save();

        $this->actingAs($data['admin'], 'web')
            ->postJson($this->endpoint($data['request']))
            ->assertForbidden();

        $this->assertDatabaseCount('access_grants', 0);
    }

    /*
    |--------------------------------------------------------------------------
    | 6. NO CONCEDER SOLICITUDES RECHAZADAS
    |--------------------------------------------------------------------------
    */

    public function test_rejected_request_cannot_be_granted(): void
    {
        $data = $this->scenario();

        $data['request']->forceFill([
            'status' => 'rejected',
        ])->save();

        $this->actingAs($data['admin'], 'web')
            ->postJson($this->endpoint($data['request']))
            ->assertForbidden();

        $this->assertDatabaseCount('access_grants', 0);
    }

    /*
    |--------------------------------------------------------------------------
    | 7. PROHIBIR AUTOCONCESIONES
    |--------------------------------------------------------------------------
    */

    public function test_admin_cannot_grant_access_to_self(): void
    {
        $data = $this->scenario();

        $ownRequest = $this->makeApprovedRequest(
            $data['admin'],
            $data['resource'],
            $data['manager']
        );

        $this->actingAs($data['admin'], 'web')
            ->postJson($this->endpoint($ownRequest))
            ->assertForbidden();

        $this->assertDatabaseCount('access_grants', 0);
    }

    /*
    |--------------------------------------------------------------------------
    | 8. RECURSO INACTIVO
    |--------------------------------------------------------------------------
    */

    public function test_inactive_resource_cannot_be_granted(): void
    {
        $data = $this->scenario();

        $data['resource']->update([
            'is_active' => false,
        ]);

        $this->actingAs($data['admin'], 'web')
            ->postJson($this->endpoint($data['request']))
            ->assertForbidden();

        $this->assertDatabaseCount('access_grants', 0);
    }

    /*
    |--------------------------------------------------------------------------
    | 9. SOLICITANTE INACTIVO
    |--------------------------------------------------------------------------
    */

    public function test_inactive_employee_cannot_receive_grant(): void
    {
        $data = $this->scenario();

        $data['employee']->forceFill([
            'status' => 'disabled',
        ])->save();

        $this->actingAs($data['admin'], 'web')
            ->postJson($this->endpoint($data['request']))
            ->assertForbidden();

        $this->assertDatabaseCount('access_grants', 0);
    }

    /*
    |--------------------------------------------------------------------------
    | 10. NO UTILIZAR DOS VECES LA MISMA SOLICITUD
    |--------------------------------------------------------------------------
    */

    public function test_same_request_cannot_be_granted_twice(): void
    {
        $data = $this->scenario();

        $this->actingAs($data['admin'], 'web');

        $this->postJson(
            $this->endpoint($data['request'])
        )->assertCreated();

        $this->postJson(
            $this->endpoint($data['request'])
        )->assertStatus(409);

        $this->assertDatabaseCount('access_grants', 1);
        $this->assertDatabaseCount('audit_logs', 1);
    }

    /*
    |--------------------------------------------------------------------------
    | 11. NO DUPLICAR ACCESO ACTIVO CON OTRA SOLICITUD
    |--------------------------------------------------------------------------
    */

    public function test_second_approved_request_cannot_duplicate_active_access(): void
    {
        $data = $this->scenario();

        $secondRequest = $this->makeApprovedRequest(
            $data['employee'],
            $data['resource'],
            $data['manager']
        );

        $this->actingAs($data['admin'], 'web');

        $this->postJson(
            $this->endpoint($data['request'])
        )->assertCreated();

        $this->postJson(
            $this->endpoint($secondRequest)
        )->assertStatus(409);

        $this->assertDatabaseCount('access_grants', 1);
    }

    /*
    |--------------------------------------------------------------------------
    | 12. EL CLIENTE NO CONTROLA EL BENEFICIARIO
    |--------------------------------------------------------------------------
    */

    public function test_client_cannot_override_grant_fields(): void
    {
        $data = $this->scenario();

        $otherEmployee = $this->makeUser();
        $otherResource = Resource::create([
            'name' => 'Sistema financiero',
            'code' => 'FIN',
            'is_active' => true,
        ]);

        $this->actingAs($data['admin'], 'web')
            ->postJson(
                $this->endpoint($data['request']),
                [
                    'user_id' => $otherEmployee->id,
                    'resource_id' => $otherResource->id,
                    'status' => 'revoked',
                    'granted_by_user_id' => $otherEmployee->id,
                ]
            )
            ->assertCreated();

        // Deben prevalecer los valores de la
        // solicitud y del usuario autenticado.
        $this->assertDatabaseHas('access_grants', [
            'user_id' => $data['employee']->id,
            'resource_id' => $data['resource']->id,
            'status' => 'active',
            'granted_by_user_id' => $data['admin']->id,
        ]);

        $this->assertDatabaseMissing('access_grants', [
            'user_id' => $otherEmployee->id,
            'resource_id' => $otherResource->id,
        ]);
    }

    /*
    |--------------------------------------------------------------------------
    | 13. ADMINISTRADOR INACTIVO
    |--------------------------------------------------------------------------
    */

    public function test_inactive_admin_cannot_grant_access(): void
    {
        $data = $this->scenario();

        $data['admin']->forceFill([
            'status' => 'disabled',
        ])->save();

        $this->actingAs($data['admin'], 'web')
            ->postJson($this->endpoint($data['request']))
            ->assertForbidden();

        $this->assertDatabaseCount('access_grants', 0);
    }

    /*
    |--------------------------------------------------------------------------
    | 14. REVERTIR CONCESIÓN SI FALLA LA AUDITORÍA
    |--------------------------------------------------------------------------
    */

    public function test_grant_rolls_back_when_audit_fails(): void
    {
        $data = $this->scenario();

        /*
         * Simular un fallo al registrar
         * el evento access.granted.
         */
        $this->mock(
            AuditService::class,
            function (MockInterface $mock): void {
                $mock->shouldReceive('accessGranted')
                    ->once()
                    ->andThrow(
                        new RuntimeException(
                            'Simulated audit failure'
                        )
                    );
            }
        );

        $this->actingAs($data['admin'], 'web')
            ->postJson($this->endpoint($data['request']))
            ->assertStatus(500);

        /*
         * La transacción debió revertirse:
         * no debe persistir la concesión.
         */
        $this->assertDatabaseCount('access_grants', 0);

        $this->assertDatabaseCount('audit_logs', 0);

        $this->assertDatabaseHas('access_requests', [
            'id' => $data['request']->id,
            'status' => 'approved',
        ]);
    }
}
