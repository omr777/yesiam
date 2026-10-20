<?php

namespace Tests\Feature;

use App\Models\AccessRequest;
use App\Models\Resource;
use App\Models\Role;
use App\Models\User;
use Database\Seeders\RbacSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AccessRequestAssignedTest extends TestCase
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

    private function makeRequest(
        User $requester,
        Resource $resource,
        string $status = 'pending'
    ): AccessRequest {
        $accessRequest = AccessRequest::create([
            'requester_user_id' => $requester->id,
            'resource_id' => $resource->id,
            'justification' => 'Necesito acceso para realizar mi trabajo.',
        ]);

        if ($status !== 'pending') {
            $accessRequest->forceFill([
                'status' => $status,
            ])->save();
        }

        return $accessRequest->refresh();
    }

    /*
    |--------------------------------------------------------------------------
    | 1. UN VISITANTE NO PUEDE CONSULTAR LA BANDEJA
    |--------------------------------------------------------------------------
    */

    public function test_guest_cannot_view_assigned_requests(): void
    {
        $this->getJson('/api/access-requests/assigned')
            ->assertUnauthorized();
    }

    /*
    |--------------------------------------------------------------------------
    | 2. UN EMPLEADO SIN ACCESS.APPROVE NO PUEDE ENTRAR
    |--------------------------------------------------------------------------
    */

    public function test_employee_cannot_view_assigned_requests(): void
    {
        $employee = $this->makeUser('employee');

        $this->actingAs($employee, 'web')
            ->getJson('/api/access-requests/assigned')
            ->assertForbidden();
    }

    /*
    |--------------------------------------------------------------------------
    | 3. UN RESPONSABLE SIN RECURSOS NO VE SOLICITUDES
    |--------------------------------------------------------------------------
    */

    public function test_manager_without_assignment_sees_empty_inbox(): void
    {
        $manager = $this->makeUser('resource_manager');
        $employee = $this->makeUser();

        $vpn = $this->makeResource();

        $this->makeRequest($employee, $vpn);

        $this->actingAs($manager, 'web')
            ->getJson('/api/access-requests/assigned')
            ->assertOk()
            ->assertJsonCount(0, 'data')
            ->assertJsonPath('total', 0);
    }

    /*
    |--------------------------------------------------------------------------
    | 4. SOLO VE SOLICITUDES DE SUS RECURSOS
    |--------------------------------------------------------------------------
    */

    public function test_manager_only_sees_requests_from_assigned_resources(): void
    {
        $admin = $this->makeUser('iam_admin');

        $carlos = $this->makeUser('resource_manager');
        $mariana = $this->makeUser('resource_manager');

        $daniela = $this->makeUser();
        $lucia = $this->makeUser();

        $vpn = $this->makeResource('VPN');
        $accounting = $this->makeResource('ACCOUNTING');

        $this->assignApprover($vpn, $carlos, $admin);
        $this->assignApprover($accounting, $mariana, $admin);

        $vpnRequest = $this->makeRequest($daniela, $vpn);
        $accountingRequest = $this->makeRequest(
            $lucia,
            $accounting
        );

        $response = $this->actingAs($carlos, 'web')
            ->getJson('/api/access-requests/assigned')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('total', 1)
            ->assertJsonPath(
                'data.0.resource.code',
                'VPN'
            );

        $returnedIds = array_column(
            $response->json('data'),
            'id'
        );

        $this->assertContains(
            $vpnRequest->id,
            $returnedIds
        );

        $this->assertNotContains(
            $accountingRequest->id,
            $returnedIds
        );
    }

    /*
    |--------------------------------------------------------------------------
    | 5. NO MOSTRAR SOLICITUDES PROPIAS
    |--------------------------------------------------------------------------
    */

    public function test_manager_cannot_see_own_requests_in_inbox(): void
    {
        $admin = $this->makeUser('iam_admin');
        $manager = $this->makeUser('resource_manager');
        $employee = $this->makeUser();

        $vpn = $this->makeResource();

        $this->assignApprover($vpn, $manager, $admin);

        $this->makeRequest($manager, $vpn);

        $otherRequest = $this->makeRequest(
            $employee,
            $vpn
        );

        $this->actingAs($manager, 'web')
            ->getJson('/api/access-requests/assigned')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath(
                'data.0.id',
                $otherRequest->id
            );
    }

    /*
    |--------------------------------------------------------------------------
    | 6. SOLO SOLICITUDES PENDIENTES
    |--------------------------------------------------------------------------
    */

    public function test_inbox_only_contains_pending_requests(): void
    {
        $admin = $this->makeUser('iam_admin');
        $manager = $this->makeUser('resource_manager');

        $requesterOne = $this->makeUser();
        $requesterTwo = $this->makeUser();
        $requesterThree = $this->makeUser();

        $vpn = $this->makeResource();

        $this->assignApprover($vpn, $manager, $admin);

        $pending = $this->makeRequest(
            $requesterOne,
            $vpn,
            'pending'
        );

        $this->makeRequest(
            $requesterTwo,
            $vpn,
            'approved'
        );

        $this->makeRequest(
            $requesterThree,
            $vpn,
            'rejected'
        );

        $this->actingAs($manager, 'web')
            ->getJson('/api/access-requests/assigned')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', $pending->id)
            ->assertJsonPath(
                'data.0.status',
                'pending'
            );
    }

    /*
    |--------------------------------------------------------------------------
    | 7. NO MOSTRAR SOLICITUDES DE USUARIOS INACTIVOS
    |--------------------------------------------------------------------------
    */

    public function test_inbox_excludes_inactive_requesters(): void
    {
        $admin = $this->makeUser('iam_admin');
        $manager = $this->makeUser('resource_manager');

        $activeEmployee = $this->makeUser();
        $suspendedEmployee = $this->makeUser(
            'employee',
            'suspended'
        );

        $vpn = $this->makeResource();

        $this->assignApprover($vpn, $manager, $admin);

        $validRequest = $this->makeRequest(
            $activeEmployee,
            $vpn
        );

        $this->makeRequest(
            $suspendedEmployee,
            $vpn
        );

        $this->actingAs($manager, 'web')
            ->getJson('/api/access-requests/assigned')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath(
                'data.0.id',
                $validRequest->id
            );
    }

    /*
    |--------------------------------------------------------------------------
    | 8. NO MOSTRAR SOLICITUDES DE RECURSOS INACTIVOS
    |--------------------------------------------------------------------------
    */

    public function test_inbox_excludes_inactive_resources(): void
    {
        $admin = $this->makeUser('iam_admin');
        $manager = $this->makeUser('resource_manager');

        $employee = $this->makeUser();

        $vpn = $this->makeResource('VPN', false);

        $this->assignApprover($vpn, $manager, $admin);

        $this->makeRequest($employee, $vpn);

        $this->actingAs($manager, 'web')
            ->getJson('/api/access-requests/assigned')
            ->assertOk()
            ->assertJsonCount(0, 'data');
    }

    /*
    |--------------------------------------------------------------------------
    | 9. UN RESPONSABLE SUSPENDIDO NO PUEDE CONSULTAR
    |--------------------------------------------------------------------------
    */

    public function test_suspended_manager_cannot_view_inbox(): void
    {
        $admin = $this->makeUser('iam_admin');

        $manager = $this->makeUser(
            'resource_manager',
            'suspended'
        );

        $vpn = $this->makeResource();

        $this->assignApprover($vpn, $manager, $admin);

        $this->actingAs($manager, 'web')
            ->getJson('/api/access-requests/assigned')
            ->assertForbidden();
    }

    /*
    |--------------------------------------------------------------------------
    | 10. LA BANDEJA RESPETA LA PAGINACIÓN
    |--------------------------------------------------------------------------
    */

    public function test_inbox_is_paginated(): void
    {
        $admin = $this->makeUser('iam_admin');
        $manager = $this->makeUser('resource_manager');

        $vpn = $this->makeResource();

        $this->assignApprover($vpn, $manager, $admin);

        // Crear 23 solicitudes de distintos empleados.
        for ($i = 0; $i < 23; $i++) {
            $employee = $this->makeUser();
            $this->makeRequest($employee, $vpn);
        }

        $this->actingAs($manager, 'web')
            ->getJson('/api/access-requests/assigned')
            ->assertOk()
            ->assertJsonCount(20, 'data')
            ->assertJsonPath('total', 23)
            ->assertJsonPath('per_page', 20)
            ->assertJsonPath('current_page', 1);

        $this->getJson(
            '/api/access-requests/assigned?page=2'
        )
            ->assertOk()
            ->assertJsonCount(3, 'data')
            ->assertJsonPath('current_page', 2);
    }
}
