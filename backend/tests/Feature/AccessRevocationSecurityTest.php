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

class AccessRevocationSecurityTest extends TestCase
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

    private function makeUser(
        string $roleCode = 'employee',
        string $status = 'active'
    ): User {
        $user = User::factory()->create([
            'status' => $status,
        ]);

        $role = Role::query()
            ->where('code', $roleCode)
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

    private function makeGrant(
        User $beneficiary,
        Resource $resource,
        User $grantor,
        User $approver
    ): AccessGrant {
        /*
         * Preparar una solicitud aprobada
         * como dato inicial del test.
         *
         * No estamos probando /approve
         * ni /grant en este archivo.
         */
        $accessRequest = AccessRequest::create([
            'requester_user_id' => $beneficiary->id,
            'resource_id' => $resource->id,
            'justification' => 'Necesito acceso para realizar mi trabajo.',
        ]);

        $accessRequest->forceFill([
            'status' => 'approved',
            'decided_by_user_id' => $approver->id,
            'decision_reason' => 'Solicitud autorizada por el responsable.',
            'decided_at' => now(),
        ])->save();

        /*
         * Preparar una concesión interna activa.
         */
        $grant = new AccessGrant();

        $grant->forceFill([
            'access_request_id' => $accessRequest->id,
            'user_id' => $beneficiary->id,
            'resource_id' => $resource->id,
            'status' => 'active',
            'granted_by_user_id' => $grantor->id,
            'granted_at' => now(),
        ]);

        $grant->save();

        return $grant->refresh();
    }

    private function scenario(): array
    {
        $admin = $this->makeUser('iam_admin');
        $manager = $this->makeUser('resource_manager');
        $employee = $this->makeUser('employee');

        $resource = $this->makeResource();

        $grant = $this->makeGrant(
            $employee,
            $resource,
            $admin,
            $manager
        );

        return [
            'admin' => $admin,
            'manager' => $manager,
            'employee' => $employee,
            'resource' => $resource,
            'grant' => $grant,
        ];
    }

    private function endpoint(AccessGrant $grant): string
    {
        return "/api/access-grants/{$grant->id}/revoke";
    }

    private function validReason(): string
    {
        return 'El empleado cambió de departamento y ya no requiere acceso.';
    }

    /*
    |--------------------------------------------------------------------------
    | 1. VISITANTE NO PUEDE REVOCAR
    |--------------------------------------------------------------------------
    */

    public function test_guest_cannot_revoke_access(): void
    {
        $data = $this->scenario();

        $this->postJson(
            $this->endpoint($data['grant']),
            ['revocation_reason' => $this->validReason()]
        )->assertUnauthorized();

        $this->assertDatabaseHas('access_grants', [
            'id' => $data['grant']->id,
            'status' => 'active',
        ]);

        $this->assertDatabaseCount('audit_logs', 0);
    }

    /*
    |--------------------------------------------------------------------------
    | 2. EMPLEADO NO PUEDE REVOCAR
    |--------------------------------------------------------------------------
    */

    public function test_employee_cannot_revoke_access(): void
    {
        $data = $this->scenario();

        $this->actingAs($data['employee'], 'web')
            ->postJson(
                $this->endpoint($data['grant']),
                ['revocation_reason' => $this->validReason()]
            )
            ->assertForbidden();

        $this->assertDatabaseHas('access_grants', [
            'id' => $data['grant']->id,
            'status' => 'active',
        ]);
    }

    /*
    |--------------------------------------------------------------------------
    | 3. RESOURCE MANAGER NO PUEDE REVOCAR
    |--------------------------------------------------------------------------
    */

    public function test_resource_manager_cannot_revoke_access(): void
    {
        $data = $this->scenario();

        $this->actingAs($data['manager'], 'web')
            ->postJson(
                $this->endpoint($data['grant']),
                ['revocation_reason' => $this->validReason()]
            )
            ->assertForbidden();

        $this->assertDatabaseHas('access_grants', [
            'id' => $data['grant']->id,
            'status' => 'active',
        ]);
    }

    /*
    |--------------------------------------------------------------------------
    | 4. REVOCACIÓN AUTORIZADA Y AUDITADA
    |--------------------------------------------------------------------------
    */

    public function test_admin_can_revoke_active_grant(): void
    {
        $data = $this->scenario();

        $originalGrantedAt = $data['grant']->granted_at;

        $this->actingAs($data['admin'], 'web')
            ->postJson(
                $this->endpoint($data['grant']),
                ['revocation_reason' => $this->validReason()]
            )
            ->assertOk()
            ->assertJsonPath('data.status', 'revoked')
            ->assertJsonPath(
                'data.revoked_by_user_id',
                $data['admin']->id
            );

        $grant = $data['grant']->fresh();

        $this->assertNotNull($grant->revoked_at);

        $this->assertEquals(
            $originalGrantedAt->getTimestamp(),
            $grant->granted_at->getTimestamp()
        );

        $this->assertDatabaseHas('access_grants', [
            'id' => $grant->id,
            'status' => 'revoked',
            'granted_by_user_id' => $data['admin']->id,
            'revoked_by_user_id' => $data['admin']->id,
            'revocation_reason' => $this->validReason(),
        ]);

        $this->assertDatabaseHas('audit_logs', [
            'action' => 'access.revoked',
            'actor_user_id' => $data['admin']->id,
            'entity_type' => 'access_request',
            'entity_id' => $grant->access_request_id,
            'outcome' => 'success',
        ]);

        $audit = AuditLog::query()
            ->where('action', 'access.revoked')
            ->firstOrFail();

        $this->assertEquals(
            $grant->id,
            $audit->metadata['access_grant_id']
        );

        $this->assertEquals(
            $data['employee']->id,
            $audit->metadata['beneficiary_user_id']
        );

        $this->assertEquals(
            'active',
            $audit->metadata['previous_status']
        );

        $this->assertEquals(
            'revoked',
            $audit->metadata['new_status']
        );

        $this->assertArrayNotHasKey(
            'revocation_reason',
            $audit->metadata
        );
    }

    /*
    |--------------------------------------------------------------------------
    | 5. PROHIBIR REVOCACIÓN PROPIA
    |--------------------------------------------------------------------------
    */

    public function test_admin_cannot_revoke_own_grant(): void
    {
        $data = $this->scenario();

        $ownGrant = $this->makeGrant(
            $data['admin'],
            $data['resource'],
            $data['manager'],
            $data['manager']
        );

        $this->actingAs($data['admin'], 'web')
            ->postJson(
                $this->endpoint($ownGrant),
                ['revocation_reason' => $this->validReason()]
            )
            ->assertForbidden();

        $this->assertDatabaseHas('access_grants', [
            'id' => $ownGrant->id,
            'status' => 'active',
        ]);

        $this->assertDatabaseCount('audit_logs', 0);
    }

    /*
    |--------------------------------------------------------------------------
    | 6. NO PERMITIR SEGUNDA REVOCACIÓN
    |--------------------------------------------------------------------------
    */

    public function test_grant_cannot_be_revoked_twice(): void
    {
        $data = $this->scenario();

        $this->actingAs($data['admin'], 'web');

        $this->postJson(
            $this->endpoint($data['grant']),
            ['revocation_reason' => $this->validReason()]
        )->assertOk();

        $firstRevocation = $data['grant']->fresh();

        $this->postJson(
            $this->endpoint($data['grant']),
            [
                'revocation_reason' =>
                    'Intento posterior de modificar la revocación.',
            ]
        )->assertStatus(409);

        $grant = $data['grant']->fresh();

        $this->assertSame(
            $firstRevocation->revocation_reason,
            $grant->revocation_reason
        );

        $this->assertEquals(
            $firstRevocation->revoked_at,
            $grant->revoked_at
        );

        $this->assertDatabaseCount('audit_logs', 1);
    }

    /*
    |--------------------------------------------------------------------------
    | 7. MOTIVO OBLIGATORIO
    |--------------------------------------------------------------------------
    */

    public function test_revocation_reason_is_required(): void
    {
        $data = $this->scenario();

        $this->actingAs($data['admin'], 'web')
            ->postJson(
                $this->endpoint($data['grant']),
                []
            )
            ->assertUnprocessable()
            ->assertJsonValidationErrors([
                'revocation_reason',
            ]);

        $this->assertDatabaseHas('access_grants', [
            'id' => $data['grant']->id,
            'status' => 'active',
        ]);

        $this->assertDatabaseCount('audit_logs', 0);
    }

    /*
    |--------------------------------------------------------------------------
    | 8. MOTIVO DEMASIADO CORTO
    |--------------------------------------------------------------------------
    */

    public function test_short_revocation_reason_is_rejected(): void
    {
        $data = $this->scenario();

        $this->actingAs($data['admin'], 'web')
            ->postJson(
                $this->endpoint($data['grant']),
                ['revocation_reason' => 'OK']
            )
            ->assertUnprocessable()
            ->assertJsonValidationErrors([
                'revocation_reason',
            ]);

        $this->assertDatabaseHas('access_grants', [
            'id' => $data['grant']->id,
            'status' => 'active',
        ]);
    }

    /*
    |--------------------------------------------------------------------------
    | 9. PERMITIR REVOCAR ACCESOS DE USUARIOS INACTIVOS
    |--------------------------------------------------------------------------
    */

    public function test_can_revoke_grant_of_disabled_employee(): void
    {
        $data = $this->scenario();

        $data['employee']->forceFill([
            'status' => 'disabled',
        ])->save();

        $this->actingAs($data['admin'], 'web')
            ->postJson(
                $this->endpoint($data['grant']),
                ['revocation_reason' => $this->validReason()]
            )
            ->assertOk();

        $this->assertDatabaseHas('access_grants', [
            'id' => $data['grant']->id,
            'status' => 'revoked',
        ]);
    }

    /*
    |--------------------------------------------------------------------------
    | 10. PERMITIR REVOCAR RECURSOS INACTIVOS
    |--------------------------------------------------------------------------
    */

    public function test_can_revoke_grant_of_inactive_resource(): void
    {
        $data = $this->scenario();

        $data['resource']->update([
            'is_active' => false,
        ]);

        $this->actingAs($data['admin'], 'web')
            ->postJson(
                $this->endpoint($data['grant']),
                ['revocation_reason' => $this->validReason()]
            )
            ->assertOk();

        $this->assertDatabaseHas('access_grants', [
            'id' => $data['grant']->id,
            'status' => 'revoked',
        ]);
    }

    /*
    |--------------------------------------------------------------------------
    | 11. ADMINISTRADOR INACTIVO NO PUEDE REVOCAR
    |--------------------------------------------------------------------------
    */

    public function test_disabled_admin_cannot_revoke(): void
    {
        $data = $this->scenario();

        $data['admin']->forceFill([
            'status' => 'disabled',
        ])->save();

        $this->actingAs($data['admin'], 'web')
            ->postJson(
                $this->endpoint($data['grant']),
                ['revocation_reason' => $this->validReason()]
            )
            ->assertForbidden();

        $this->assertDatabaseHas('access_grants', [
            'id' => $data['grant']->id,
            'status' => 'active',
        ]);
    }

    /*
    |--------------------------------------------------------------------------
    | 12. EL CLIENTE NO PUEDE FALSIFICAR DATOS
    |--------------------------------------------------------------------------
    */

    public function test_client_cannot_override_revocation_fields(): void
    {
        $data = $this->scenario();

        $otherUser = $this->makeUser();

        $this->actingAs($data['admin'], 'web')
            ->postJson(
                $this->endpoint($data['grant']),
                [
                    'revocation_reason' => $this->validReason(),
                    'status' => 'active',
                    'user_id' => $otherUser->id,
                    'revoked_by_user_id' => $otherUser->id,
                    'granted_by_user_id' => $otherUser->id,
                ]
            )
            ->assertOk();

        $this->assertDatabaseHas('access_grants', [
            'id' => $data['grant']->id,
            'user_id' => $data['employee']->id,
            'status' => 'revoked',
            'granted_by_user_id' => $data['admin']->id,
            'revoked_by_user_id' => $data['admin']->id,
        ]);
    }

    /*
    |--------------------------------------------------------------------------
    | 13. ROLLBACK SI FALLA LA AUDITORÍA
    |--------------------------------------------------------------------------
    */

    public function test_revocation_rolls_back_when_audit_fails(): void
    {
        $data = $this->scenario();

        $this->mock(
            AuditService::class,
            function (MockInterface $mock): void {
                $mock->shouldReceive('accessRevoked')
                    ->once()
                    ->andThrow(
                        new RuntimeException(
                            'Simulated audit failure'
                        )
                    );
            }
        );

        $this->actingAs($data['admin'], 'web')
            ->postJson(
                $this->endpoint($data['grant']),
                ['revocation_reason' => $this->validReason()]
            )
            ->assertStatus(500);

        $this->assertDatabaseHas('access_grants', [
            'id' => $data['grant']->id,
            'status' => 'active',
            'revoked_by_user_id' => null,
            'revoked_at' => null,
            'revocation_reason' => null,
        ]);

        $this->assertDatabaseCount('audit_logs', 0);
    }

    /*
    |--------------------------------------------------------------------------
    | 14. REVOCAR UN ACCESO NO ALTERA OTRO
    |--------------------------------------------------------------------------
    */

    public function test_revoking_one_grant_does_not_modify_another(): void
    {
        $data = $this->scenario();

        $otherEmployee = $this->makeUser();

        $otherGrant = $this->makeGrant(
            $otherEmployee,
            $data['resource'],
            $data['admin'],
            $data['manager']
        );

        $this->actingAs($data['admin'], 'web')
            ->postJson(
                $this->endpoint($data['grant']),
                ['revocation_reason' => $this->validReason()]
            )
            ->assertOk();

        $this->assertDatabaseHas('access_grants', [
            'id' => $data['grant']->id,
            'status' => 'revoked',
        ]);

        $this->assertDatabaseHas('access_grants', [
            'id' => $otherGrant->id,
            'status' => 'active',
            'revoked_by_user_id' => null,
        ]);

        $this->assertDatabaseCount('audit_logs', 1);
    }
}
