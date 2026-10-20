<?php

namespace Tests\Feature;

use App\Models\AccessRequest;
use App\Models\Resource;
use App\Models\Role;
use App\Models\User;
use Database\Seeders\RbacSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AccessRequestSecurityTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RbacSeeder::class);
    }

    private function makeUser(string $roleCode = 'employee'): User
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
        string $code = 'VPN',
        bool $active = true
    ): Resource {
        return Resource::create([
            'name' => "Recurso {$code}",
            'code' => $code,
            'description' => 'Recurso empresarial de prueba',
            'is_active' => $active,
        ]);
    }

    private function makeRequest(
        User $user,
        Resource $resource
    ): AccessRequest {
        return AccessRequest::create([
            'requester_user_id' => $user->id,
            'resource_id' => $resource->id,
            'justification' => 'Necesito acceso para trabajar.',
        ]);
    }

    // 1. Visitante no puede crear solicitudes.
    public function test_guest_cannot_create_request(): void
    {
        $resource = $this->makeResource();

        $this->postJson('/api/access-requests', [
            'resource_id' => $resource->id,
            'justification' => 'Necesito acceso remoto.',
        ])->assertUnauthorized();

        $this->assertDatabaseCount('access_requests', 0);
    }

    // 2. Visitante no puede consultar solicitudes.
    public function test_guest_cannot_list_requests(): void
    {
        $this->getJson('/api/access-requests')
            ->assertUnauthorized();
    }

    // 3. Empleado crea una solicitud pendiente.
    public function test_employee_can_create_pending_request(): void
    {
        $employee = $this->makeUser();
        $resource = $this->makeResource();

        $this->actingAs($employee, 'web')
            ->postJson('/api/access-requests', [
                'resource_id' => $resource->id,
                'justification' => 'Necesito acceso remoto.',
            ])
            ->assertCreated()
            ->assertJsonPath('data.status', 'pending')
            ->assertJsonPath(
                'data.requester_user_id',
                $employee->id
            );

        $this->assertDatabaseHas('access_requests', [
            'requester_user_id' => $employee->id,
            'resource_id' => $resource->id,
            'status' => 'pending',
        ]);
    }

    // 4. No puede suplantar ni autoaprobar.
    public function test_employee_cannot_forge_request_fields(): void
    {
        $employee = $this->makeUser();
        $otherUser = $this->makeUser();
        $resource = $this->makeResource();

        $this->actingAs($employee, 'web')
            ->postJson('/api/access-requests', [
                'resource_id' => $resource->id,
                'justification' => 'Necesito acceso remoto.',
                'requester_user_id' => $otherUser->id,
                'status' => 'approved',
                'decided_by_user_id' => $otherUser->id,
            ])
            ->assertCreated();

        $this->assertDatabaseHas('access_requests', [
            'requester_user_id' => $employee->id,
            'resource_id' => $resource->id,
            'status' => 'pending',
            'decided_by_user_id' => null,
        ]);

        $this->assertDatabaseMissing('access_requests', [
            'requester_user_id' => $otherUser->id,
        ]);
    }

    // 5. No puede solicitar recursos inactivos.
    public function test_cannot_request_inactive_resource(): void
    {
        $employee = $this->makeUser();
        $resource = $this->makeResource('OLD', false);

        $this->actingAs($employee, 'web')
            ->postJson('/api/access-requests', [
                'resource_id' => $resource->id,
                'justification' => 'Necesito acceso remoto.',
            ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['resource_id']);

        $this->assertDatabaseCount('access_requests', 0);
    }

    // 6. No puede repetir una solicitud pendiente.
    public function test_cannot_create_duplicate_pending_request(): void
    {
        $employee = $this->makeUser();
        $resource = $this->makeResource();

        $this->makeRequest($employee, $resource);

        $this->actingAs($employee, 'web')
            ->postJson('/api/access-requests', [
                'resource_id' => $resource->id,
                'justification' => 'Necesito nuevamente acceso.',
            ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['resource_id']);

        $this->assertDatabaseCount('access_requests', 1);
    }

    // 7. Un empleado solo consulta sus solicitudes.
    public function test_employee_only_sees_own_requests(): void
    {
        $daniela = $this->makeUser();
        $carlos = $this->makeUser();

        $vpn = $this->makeResource('VPN');
        $accounting = $this->makeResource('ACCOUNTING');

        $ownRequest = $this->makeRequest($daniela, $vpn);
        $otherRequest = $this->makeRequest($carlos, $accounting);

        $this->actingAs($daniela, 'web')
            ->getJson('/api/access-requests')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', $ownRequest->id)
            ->assertJsonPath('data.0.resource.code', 'VPN');

        $this->assertNotEquals(
            $ownRequest->id,
            $otherRequest->id
        );
    }

    // 8. Una cuenta suspendida no puede solicitar.
    public function test_suspended_user_cannot_request_access(): void
    {
        $employee = $this->makeUser();
        $resource = $this->makeResource();

        $employee->forceFill([
            'status' => 'suspended',
        ])->save();

        $this->actingAs($employee, 'web')
            ->postJson('/api/access-requests', [
                'resource_id' => $resource->id,
                'justification' => 'Necesito acceso remoto.',
            ])
            ->assertForbidden();

        $this->assertDatabaseCount('access_requests', 0);
    }

    // 9. Usuario sin permiso access.request.
    public function test_user_without_permission_cannot_request(): void
    {
        $user = User::factory()->create([
            'status' => 'active',
        ]);

        // No asignamos ningún rol.
        $resource = $this->makeResource();

        $this->actingAs($user, 'web')
            ->postJson('/api/access-requests', [
                'resource_id' => $resource->id,
                'justification' => 'Necesito acceso remoto.',
            ])
            ->assertForbidden();

        $this->assertDatabaseCount('access_requests', 0);
    }

    // 10. La justificación debe ser válida.
    public function test_short_justification_is_rejected(): void
    {
        $employee = $this->makeUser();
        $resource = $this->makeResource();

        $this->actingAs($employee, 'web')
            ->postJson('/api/access-requests', [
                'resource_id' => $resource->id,
                'justification' => 'VPN',
            ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['justification']);

        $this->assertDatabaseCount('access_requests', 0);
    }
}
