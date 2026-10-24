<?php

namespace Tests\Feature;

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

class AccessDecisionSecurityTest extends TestCase
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

        $role = Role::where('code', $roleCode)
            ->firstOrFail();

        $user->roles()->attach($role->id);

        return $user;
    }

    private function makeResource(
        bool $active = true
    ): Resource {
        return Resource::create([
            'name' => 'VPN corporativa',
            'code' => 'VPN',
            'description' => 'Acceso remoto empresarial',
            'is_active' => $active,
        ]);
    }

    private function makeRequest(
        User $requester,
        Resource $resource
    ): AccessRequest {
        return AccessRequest::create([
            'requester_user_id' => $requester->id,
            'resource_id' => $resource->id,
            'justification' => 'Necesito acceder para realizar mi trabajo.',
        ])->refresh();
    }

    private function assignApprover(
        Resource $resource,
        User $approver,
        User $admin
    ): void {
        $resource->approvers()->attach(
            $approver->id,
            [
                'assigned_by_user_id' => $admin->id,
            ]
        );
    }

    private function endpoint(
        AccessRequest $accessRequest,
        string $action = 'approve'
    ): string {
        return "/api/access-requests/{$accessRequest->id}/{$action}";
    }

    private function validReason(): string
    {
        return 'La solicitud cumple con las necesidades del puesto.';
    }

    /*
    |--------------------------------------------------------------------------
    | 1. VISITANTE NO PUEDE APROBAR
    |--------------------------------------------------------------------------
    */

    public function test_guest_cannot_approve(): void
    {
        $employee = $this->makeUser();
        $resource = $this->makeResource();
        $accessRequest = $this->makeRequest(
            $employee,
            $resource
        );

        $this->postJson(
            $this->endpoint($accessRequest),
            ['decision_reason' => $this->validReason()]
        )->assertUnauthorized();

        $this->assertDatabaseHas('access_requests', [
            'id' => $accessRequest->id,
            'status' => 'pending',
        ]);
    }

    /*
    |--------------------------------------------------------------------------
    | 2. EMPLEADO NO PUEDE APROBAR
    |--------------------------------------------------------------------------
    */

    public function test_employee_cannot_approve(): void
    {
        $employee = $this->makeUser();
        $otherEmployee = $this->makeUser();
        $resource = $this->makeResource();

        $accessRequest = $this->makeRequest(
            $otherEmployee,
            $resource
        );

        $this->actingAs($employee, 'web')
            ->postJson(
                $this->endpoint($accessRequest),
                ['decision_reason' => $this->validReason()]
            )
            ->assertForbidden();

        $this->assertDatabaseCount('audit_logs', 0);
    }

    /*
    |--------------------------------------------------------------------------
    | 3. RESPONSABLE SIN ASIGNACIÓN NO PUEDE APROBAR
    |--------------------------------------------------------------------------
    */

    public function test_manager_cannot_approve_unassigned_resource(): void
    {
        $manager = $this->makeUser('resource_manager');
        $employee = $this->makeUser();

        $resource = $this->makeResource();
        $accessRequest = $this->makeRequest(
            $employee,
            $resource
        );

        // Tiene access.approve, pero no administra VPN.
        $this->actingAs($manager, 'web')
            ->postJson(
                $this->endpoint($accessRequest),
                ['decision_reason' => $this->validReason()]
            )
            ->assertForbidden();

        $this->assertDatabaseHas('access_requests', [
            'id' => $accessRequest->id,
            'status' => 'pending',
        ]);
    }

    /*
    |--------------------------------------------------------------------------
    | 4. RESPONSABLE AUTORIZADO PUEDE APROBAR
    |--------------------------------------------------------------------------
    */

    public function test_authorized_manager_can_approve(): void
    {
        $admin = $this->makeUser('iam_admin');
        $manager = $this->makeUser('resource_manager');
        $employee = $this->makeUser();

        $resource = $this->makeResource();

        $this->assignApprover(
            $resource,
            $manager,
            $admin
        );

        $accessRequest = $this->makeRequest(
            $employee,
            $resource
        );

        $this->actingAs($manager, 'web')
            ->postJson(
                $this->endpoint($accessRequest),
                ['decision_reason' => $this->validReason()]
            )
            ->assertOk()
            ->assertJsonPath('data.status', 'approved')
            ->assertJsonPath(
                'data.decided_by_user_id',
                $manager->id
            );

        $accessRequest->refresh();

        $this->assertNotNull(
            $accessRequest->decided_at
        );

        $this->assertDatabaseHas('access_requests', [
            'id' => $accessRequest->id,
            'status' => 'approved',
            'decided_by_user_id' => $manager->id,
            'decision_reason' => $this->validReason(),
        ]);

        $this->assertDatabaseHas('audit_logs', [
            'actor_user_id' => $manager->id,
            'action' => 'access.approved',
            'entity_type' => 'access_request',
            'entity_id' => $accessRequest->id,
            'outcome' => 'success',
        ]);

        $log = AuditLog::firstOrFail();

        $this->assertEquals(
            $resource->id,
            $log->metadata['resource_id']
        );

        $this->assertEquals(
            'pending',
            $log->metadata['previous_status']
        );

        $this->assertEquals(
            'approved',
            $log->metadata['new_status']
        );

        $this->assertArrayNotHasKey(
            'decision_reason',
            $log->metadata
        );
    }

    /*
    |--------------------------------------------------------------------------
    | 5. RESPONSABLE AUTORIZADO PUEDE RECHAZAR
    |--------------------------------------------------------------------------
    */

    public function test_authorized_manager_can_reject(): void
    {
        $admin = $this->makeUser('iam_admin');
        $manager = $this->makeUser('resource_manager');
        $employee = $this->makeUser();

        $resource = $this->makeResource();

        $this->assignApprover(
            $resource,
            $manager,
            $admin
        );

        $accessRequest = $this->makeRequest(
            $employee,
            $resource
        );

        $this->actingAs($manager, 'web')
            ->postJson(
                $this->endpoint($accessRequest, 'reject'),
                [
                    'decision_reason' =>
                        'El acceso solicitado no corresponde al puesto.',
                ]
            )
            ->assertOk()
            ->assertJsonPath('data.status', 'rejected')
            ->assertJsonPath(
                'data.decided_by_user_id',
                $manager->id
            );

        $this->assertDatabaseHas('access_requests', [
            'id' => $accessRequest->id,
            'status' => 'rejected',
            'decided_by_user_id' => $manager->id,
        ]);

        $this->assertDatabaseHas('audit_logs', [
            'action' => 'access.rejected',
            'actor_user_id' => $manager->id,
            'entity_id' => $accessRequest->id,
        ]);
    }

    /*
    |--------------------------------------------------------------------------
    | 6. PROHIBIR AUTOAPROBACIÓN
    |--------------------------------------------------------------------------
    */

    public function test_manager_cannot_approve_own_request(): void
    {
        $admin = $this->makeUser('iam_admin');
        $manager = $this->makeUser('resource_manager');

        $resource = $this->makeResource();

        $this->assignApprover(
            $resource,
            $manager,
            $admin
        );

        $accessRequest = $this->makeRequest(
            $manager,
            $resource
        );

        $this->actingAs($manager, 'web')
            ->postJson(
                $this->endpoint($accessRequest),
                ['decision_reason' => $this->validReason()]
            )
            ->assertForbidden();

        $this->assertDatabaseHas('access_requests', [
            'id' => $accessRequest->id,
            'status' => 'pending',
        ]);

        $this->assertDatabaseCount('audit_logs', 0);
    }

    /*
    |--------------------------------------------------------------------------
    | 7. IMPEDIR SEGUNDAS DECISIONES
    |--------------------------------------------------------------------------
    */

    public function test_decided_request_cannot_be_decided_again(): void
    {
        $admin = $this->makeUser('iam_admin');
        $manager = $this->makeUser('resource_manager');
        $employee = $this->makeUser();

        $resource = $this->makeResource();

        $this->assignApprover(
            $resource,
            $manager,
            $admin
        );

        $accessRequest = $this->makeRequest(
            $employee,
            $resource
        );

        // Primera decisión: aprobación válida.
        $this->actingAs($manager, 'web')
            ->postJson(
                $this->endpoint($accessRequest),
                ['decision_reason' => $this->validReason()]
            )
            ->assertOk();

        // Intento posterior de rechazarla.
        $this->postJson(
            $this->endpoint($accessRequest, 'reject'),
            ['decision_reason' => $this->validReason()]
        )->assertForbidden();

        $this->assertDatabaseHas('access_requests', [
            'id' => $accessRequest->id,
            'status' => 'approved',
        ]);

        $this->assertDatabaseCount('audit_logs', 1);
    }

    /*
    |--------------------------------------------------------------------------
    | 8. NO DECIDIR SOBRE RECURSOS INACTIVOS
    |--------------------------------------------------------------------------
    */

    public function test_cannot_decide_inactive_resource(): void
    {
        $admin = $this->makeUser('iam_admin');
        $manager = $this->makeUser('resource_manager');
        $employee = $this->makeUser();

        $resource = $this->makeResource();

        $this->assignApprover(
            $resource,
            $manager,
            $admin
        );

        $accessRequest = $this->makeRequest(
            $employee,
            $resource
        );

        // El recurso se desactiva posteriormente.
        $resource->update(['is_active' => false]);

        $this->actingAs($manager, 'web')
            ->postJson(
                $this->endpoint($accessRequest),
                ['decision_reason' => $this->validReason()]
            )
            ->assertForbidden();

        $this->assertDatabaseHas('access_requests', [
            'id' => $accessRequest->id,
            'status' => 'pending',
        ]);
    }

    /*
    |--------------------------------------------------------------------------
    | 9. NO DECIDIR SOBRE SOLICITANTES SUSPENDIDOS
    |--------------------------------------------------------------------------
    */

    public function test_cannot_decide_suspended_requester(): void
    {
        $admin = $this->makeUser('iam_admin');
        $manager = $this->makeUser('resource_manager');
        $employee = $this->makeUser();

        $resource = $this->makeResource();

        $this->assignApprover(
            $resource,
            $manager,
            $admin
        );

        $accessRequest = $this->makeRequest(
            $employee,
            $resource
        );

        $employee->forceFill([
            'status' => 'suspended',
        ])->save();

        $this->actingAs($manager, 'web')
            ->postJson(
                $this->endpoint($accessRequest),
                ['decision_reason' => $this->validReason()]
            )
            ->assertForbidden();

        $this->assertDatabaseHas('access_requests', [
            'id' => $accessRequest->id,
            'status' => 'pending',
        ]);
    }

    /*
    |--------------------------------------------------------------------------
    | 10. MOTIVO DE DECISIÓN OBLIGATORIO
    |--------------------------------------------------------------------------
    */

    public function test_short_decision_reason_is_rejected(): void
    {
        $admin = $this->makeUser('iam_admin');
        $manager = $this->makeUser('resource_manager');
        $employee = $this->makeUser();

        $resource = $this->makeResource();

        $this->assignApprover(
            $resource,
            $manager,
            $admin
        );

        $accessRequest = $this->makeRequest(
            $employee,
            $resource
        );

        $this->actingAs($manager, 'web')
            ->postJson(
                $this->endpoint($accessRequest),
                ['decision_reason' => 'OK']
            )
            ->assertUnprocessable()
            ->assertJsonValidationErrors([
                'decision_reason',
            ]);

        $this->assertDatabaseHas('access_requests', [
            'id' => $accessRequest->id,
            'status' => 'pending',
        ]);

        $this->assertDatabaseCount('audit_logs', 0);
    }

    /*
    |--------------------------------------------------------------------------
    | 11. RESPONSABLE SUSPENDIDO
    |--------------------------------------------------------------------------
    */

    public function test_suspended_manager_cannot_decide(): void
    {
        $admin = $this->makeUser('iam_admin');
        $manager = $this->makeUser(
            'resource_manager',
            'suspended'
        );
        $employee = $this->makeUser();

        $resource = $this->makeResource();

        $this->assignApprover(
            $resource,
            $manager,
            $admin
        );

        $accessRequest = $this->makeRequest(
            $employee,
            $resource
        );

        $this->actingAs($manager, 'web')
            ->postJson(
                $this->endpoint($accessRequest),
                ['decision_reason' => $this->validReason()]
            )
            ->assertForbidden();

        $this->assertDatabaseCount('audit_logs', 0);
    }

    /*
    |--------------------------------------------------------------------------
    | 12. ROLLBACK SI FALLA LA AUDITORÍA
    |--------------------------------------------------------------------------
    */

    public function test_decision_rolls_back_if_audit_fails(): void
    {
        $admin = $this->makeUser('iam_admin');
        $manager = $this->makeUser('resource_manager');
        $employee = $this->makeUser();

        $resource = $this->makeResource();

        $this->assignApprover(
            $resource,
            $manager,
            $admin
        );

        $accessRequest = $this->makeRequest(
            $employee,
            $resource
        );

        // Simular fallo al escribir auditoría.
        $this->mock(
            AuditService::class,
            function (MockInterface $mock): void {
                $mock->shouldReceive(
                    'accessDecisionRecorded'
                )
                    ->once()
                    ->andThrow(
                        new RuntimeException(
                            'Simulated audit failure'
                        )
                    );
            }
        );

        $this->actingAs($manager, 'web')
            ->postJson(
                $this->endpoint($accessRequest),
                ['decision_reason' => $this->validReason()]
            )
            ->assertStatus(500);

        // Ninguna decisión debe haber persistido.
        $this->assertDatabaseHas('access_requests', [
            'id' => $accessRequest->id,
            'status' => 'pending',
            'decided_by_user_id' => null,
            'decided_at' => null,
        ]);

        $this->assertDatabaseCount('audit_logs', 0);
    }
}
