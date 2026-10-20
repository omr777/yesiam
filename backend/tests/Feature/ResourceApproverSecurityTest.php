<?php

namespace Tests\Feature;

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

class ResourceApproverSecurityTest extends TestCase
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

    private function endpoint(Resource $resource): string
    {
        return "/api/resources/{$resource->id}/approvers";
    }

    /*
    |--------------------------------------------------------------------------
    | 1. VISITANTE SIN AUTENTICACIÓN
    |--------------------------------------------------------------------------
    */

    public function test_guest_cannot_assign_approver(): void
    {
        $resource = $this->makeResource();
        $approver = $this->makeUser('resource_manager');

        $this->postJson($this->endpoint($resource), [
            'user_id' => $approver->id,
        ])->assertUnauthorized();

        $this->assertDatabaseCount('resource_approvers', 0);
        $this->assertDatabaseCount('audit_logs', 0);
    }

    /*
    |--------------------------------------------------------------------------
    | 2. EMPLEADO SIN AUTORIZACIÓN
    |--------------------------------------------------------------------------
    */

    public function test_employee_cannot_assign_approver(): void
    {
        $employee = $this->makeUser('employee');
        $approver = $this->makeUser('resource_manager');
        $resource = $this->makeResource();

        $this->actingAs($employee, 'web')
            ->postJson($this->endpoint($resource), [
                'user_id' => $approver->id,
            ])
            ->assertForbidden();

        $this->assertDatabaseCount('resource_approvers', 0);
    }

    /*
    |--------------------------------------------------------------------------
    | 3. RESPONSABLE NO PUEDE DESIGNAR OTROS RESPONSABLES
    |--------------------------------------------------------------------------
    */

    public function test_resource_manager_cannot_assign_approver(): void
    {
        $manager = $this->makeUser('resource_manager');
        $resource = $this->makeResource();

        $this->actingAs($manager, 'web')
            ->postJson($this->endpoint($resource), [
                'user_id' => $manager->id,
            ])
            ->assertForbidden();

        $this->assertDatabaseCount('resource_approvers', 0);
    }

    /*
    |--------------------------------------------------------------------------
    | 4. ADMINISTRADOR PUEDE ASIGNAR RESPONSABLE VÁLIDO
    |--------------------------------------------------------------------------
    */

    public function test_admin_can_assign_valid_approver(): void
    {
        $admin = $this->makeUser('iam_admin');
        $approver = $this->makeUser('resource_manager');
        $resource = $this->makeResource();

        $this->actingAs($admin, 'web')
            ->postJson($this->endpoint($resource), [
                'user_id' => $approver->id,
            ])
            ->assertCreated()
            ->assertJsonPath(
                'data.resource_id',
                $resource->id
            )
            ->assertJsonPath(
                'data.user_id',
                $approver->id
            )
            ->assertJsonPath(
                'data.assigned_by_user_id',
                $admin->id
            );

        // Verificar la asignación.
        $this->assertDatabaseHas('resource_approvers', [
            'resource_id' => $resource->id,
            'user_id' => $approver->id,
            'assigned_by_user_id' => $admin->id,
        ]);

        // Verificar la auditoría.
        $this->assertDatabaseHas('audit_logs', [
            'actor_user_id' => $admin->id,
            'action' => 'resource.approver_assigned',
            'entity_type' => 'resource',
            'entity_id' => $resource->id,
            'outcome' => 'success',
        ]);

        $log = AuditLog::firstOrFail();

        $this->assertEquals(
            $approver->id,
            $log->metadata['approver_user_id']
        );

        $this->assertEquals(
            $admin->id,
            $log->metadata['assigned_by_user_id']
        );

        $this->assertDatabaseCount('resource_approvers', 1);
        $this->assertDatabaseCount('audit_logs', 1);
    }

    /*
    |--------------------------------------------------------------------------
    | 5. NO ASIGNAR RESPONSABLES SUSPENDIDOS
    |--------------------------------------------------------------------------
    */

    public function test_cannot_assign_suspended_approver(): void
    {
        $admin = $this->makeUser('iam_admin');
        $approver = $this->makeUser('resource_manager');
        $resource = $this->makeResource();

        $approver->forceFill([
            'status' => 'suspended',
        ])->save();

        $this->actingAs($admin, 'web')
            ->postJson($this->endpoint($resource), [
                'user_id' => $approver->id,
            ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['user_id']);

        $this->assertDatabaseCount('resource_approvers', 0);
        $this->assertDatabaseCount('audit_logs', 0);
    }

    /*
    |--------------------------------------------------------------------------
    | 6. EL RESPONSABLE NECESITA ACCESS.APPROVE
    |--------------------------------------------------------------------------
    */

    public function test_approver_must_have_approval_permission(): void
    {
        $admin = $this->makeUser('iam_admin');
        $employee = $this->makeUser('employee');
        $resource = $this->makeResource();

        $this->actingAs($admin, 'web')
            ->postJson($this->endpoint($resource), [
                'user_id' => $employee->id,
            ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['user_id']);

        $this->assertDatabaseCount('resource_approvers', 0);
        $this->assertDatabaseCount('audit_logs', 0);
    }

    /*
    |--------------------------------------------------------------------------
    | 7. PREVENIR ASIGNACIONES DUPLICADAS
    |--------------------------------------------------------------------------
    */

    public function test_cannot_assign_same_approver_twice(): void
    {
        $admin = $this->makeUser('iam_admin');
        $approver = $this->makeUser('resource_manager');
        $resource = $this->makeResource();

        $this->actingAs($admin, 'web')
            ->postJson($this->endpoint($resource), [
                'user_id' => $approver->id,
            ])
            ->assertCreated();

        $this->postJson($this->endpoint($resource), [
            'user_id' => $approver->id,
        ])->assertStatus(409);

        $this->assertDatabaseCount('resource_approvers', 1);
        $this->assertDatabaseCount('audit_logs', 1);
    }

    /*
    |--------------------------------------------------------------------------
    | 8. NO ASIGNAR RESPONSABLES A RECURSOS INACTIVOS
    |--------------------------------------------------------------------------
    */

    public function test_cannot_assign_to_inactive_resource(): void
    {
        $admin = $this->makeUser('iam_admin');
        $approver = $this->makeUser('resource_manager');
        $resource = $this->makeResource(false);

        $this->actingAs($admin, 'web')
            ->postJson($this->endpoint($resource), [
                'user_id' => $approver->id,
            ])
            ->assertForbidden();

        $this->assertDatabaseCount('resource_approvers', 0);
        $this->assertDatabaseCount('audit_logs', 0);
    }

    /*
    |--------------------------------------------------------------------------
    | 9. USUARIO SELECCIONADO INEXISTENTE
    |--------------------------------------------------------------------------
    */

    public function test_cannot_assign_nonexistent_user(): void
    {
        $admin = $this->makeUser('iam_admin');
        $resource = $this->makeResource();

        $this->actingAs($admin, 'web')
            ->postJson($this->endpoint($resource), [
                'user_id' => 999999,
            ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['user_id']);

        $this->assertDatabaseCount('resource_approvers', 0);
        $this->assertDatabaseCount('audit_logs', 0);
    }

    /*
    |--------------------------------------------------------------------------
    | 10. SI FALLA AUDITORÍA, REVERTIR ASIGNACIÓN
    |--------------------------------------------------------------------------
    */

    public function test_assignment_rolls_back_if_audit_fails(): void
    {
        $admin = $this->makeUser('iam_admin');
        $approver = $this->makeUser('resource_manager');
        $resource = $this->makeResource();

        // Simulamos un fallo del servicio de auditoría.
        $this->mock(
            AuditService::class,
            function (MockInterface $mock): void {
                $mock->shouldReceive(
                    'resourceApproverAssigned'
                )
                    ->once()
                    ->andThrow(
                        new RuntimeException(
                            'Simulated audit failure'
                        )
                    );
            }
        );

        $this->actingAs($admin, 'web')
            ->postJson($this->endpoint($resource), [
                'user_id' => $approver->id,
            ])
            ->assertStatus(500);

        // La transacción debe revertir ambas escrituras.
        $this->assertDatabaseCount('resource_approvers', 0);
        $this->assertDatabaseCount('audit_logs', 0);
    }
}
