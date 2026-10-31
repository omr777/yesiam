<?php

namespace Tests\Feature;

use App\Models\AccessGrant;
use App\Models\AccessRequest;
use App\Models\AuditLog;
use App\Models\Department;
use App\Models\Resource;
use App\Models\Role;
use App\Models\User;
use Database\Seeders\RbacSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/*
|--------------------------------------------------------------------------
| YESIAM - ENDPOINTS DE SOPORTE PARA LA INTERFAZ
|--------------------------------------------------------------------------
|
| FASE 20
|
| - Responsables: consultar y retirar.
| - Cola de concesión.
| - Recursos inactivos para administradores.
| - Actor en la bitácora de auditoría.
| - Resumen del dashboard por permisos.
|
*/

class GovernanceSupportEndpointsTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RbacSeeder::class);
    }

    private function makeUser(
        string $roleCode = 'employee',
        string $status = 'active'
    ): User {
        $user = User::factory()->create([
            'status' => $status,
        ]);

        $user->roles()->attach(
            Role::where('code', $roleCode)->firstOrFail()->id
        );

        return $user;
    }

    private function makeResource(
        string $code = 'VPN',
        bool $active = true
    ): Resource {
        return Resource::create([
            'name' => "Recurso {$code}",
            'code' => $code,
            'description' => null,
            'is_active' => $active,
        ]);
    }

    private function makeRequest(
        User $requester,
        Resource $resource,
        string $status = 'pending',
        ?User $decider = null
    ): AccessRequest {
        $accessRequest = AccessRequest::create([
            'requester_user_id' => $requester->id,
            'resource_id' => $resource->id,
            'justification' => 'Necesito este acceso para mi trabajo.',
        ]);

        if ($status !== 'pending') {
            $accessRequest->forceFill([
                'status' => $status,
                'decided_by_user_id' => $decider?->id,
                'decision_reason' => 'Decisión registrada en la prueba.',
                'decided_at' => now(),
            ])->save();
        }

        return $accessRequest->refresh();
    }

    private function grant(
        AccessRequest $accessRequest,
        User $admin
    ): AccessGrant {
        $grant = new AccessGrant();

        $grant->forceFill([
            'access_request_id' => $accessRequest->id,
            'user_id' => $accessRequest->requester_user_id,
            'resource_id' => $accessRequest->resource_id,
            'status' => 'active',
            'granted_by_user_id' => $admin->id,
            'granted_at' => now(),
        ])->save();

        return $grant;
    }

    /*
    |--------------------------------------------------------------------------
    | RESPONSABLES
    |--------------------------------------------------------------------------
    */

    public function test_admin_can_list_resource_approvers(): void
    {
        $admin = $this->makeUser('iam_admin');
        $manager = $this->makeUser('resource_manager');
        $resource = $this->makeResource();

        $resource->approvers()->attach(
            $manager->id,
            ['assigned_by_user_id' => $admin->id]
        );

        $this->actingAs($admin, 'web')
            ->getJson("/api/resources/{$resource->id}/approvers")
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', $manager->id)
            ->assertJsonPath('data.0.status', 'active')
            ->assertJsonMissingPath('data.0.password');
    }

    public function test_employee_cannot_list_resource_approvers(): void
    {
        $employee = $this->makeUser('employee');
        $resource = $this->makeResource();

        $this->actingAs($employee, 'web')
            ->getJson("/api/resources/{$resource->id}/approvers")
            ->assertForbidden();
    }

    public function test_admin_can_remove_resource_approver(): void
    {
        $admin = $this->makeUser('iam_admin');
        $manager = $this->makeUser('resource_manager');
        $resource = $this->makeResource();

        $resource->approvers()->attach(
            $manager->id,
            ['assigned_by_user_id' => $admin->id]
        );

        $this->actingAs($admin, 'web')
            ->deleteJson(
                "/api/resources/{$resource->id}/approvers/{$manager->id}"
            )
            ->assertOk();

        $this->assertDatabaseMissing('resource_approvers', [
            'resource_id' => $resource->id,
            'user_id' => $manager->id,
        ]);

        $log = AuditLog::where('action', 'resource.approver_removed')
            ->firstOrFail();

        $this->assertSame('manual', $log->metadata['reason']);
        $this->assertSame($manager->id, $log->metadata['approver_user_id']);
    }

    public function test_removing_unassigned_approver_returns_not_found(): void
    {
        $admin = $this->makeUser('iam_admin');
        $manager = $this->makeUser('resource_manager');
        $resource = $this->makeResource();

        $this->actingAs($admin, 'web')
            ->deleteJson(
                "/api/resources/{$resource->id}/approvers/{$manager->id}"
            )
            ->assertNotFound();

        $this->assertDatabaseMissing('audit_logs', [
            'action' => 'resource.approver_removed',
        ]);
    }

    public function test_employee_cannot_remove_approvers(): void
    {
        $admin = $this->makeUser('iam_admin');
        $employee = $this->makeUser('employee');
        $manager = $this->makeUser('resource_manager');
        $resource = $this->makeResource();

        $resource->approvers()->attach(
            $manager->id,
            ['assigned_by_user_id' => $admin->id]
        );

        $this->actingAs($employee, 'web')
            ->deleteJson(
                "/api/resources/{$resource->id}/approvers/{$manager->id}"
            )
            ->assertForbidden();

        $this->assertDatabaseHas('resource_approvers', [
            'user_id' => $manager->id,
        ]);
    }

    /*
    |--------------------------------------------------------------------------
    | COLA DE CONCESIÓN
    |--------------------------------------------------------------------------
    */

    public function test_grant_queue_lists_only_approved_requests_without_grant(): void
    {
        $admin = $this->makeUser('iam_admin');
        $manager = $this->makeUser('resource_manager');
        $employee = $this->makeUser('employee');
        $inactiveEmployee = $this->makeUser('employee', 'suspended');

        $vpn = $this->makeResource('VPN');
        $erp = $this->makeResource('ERP');
        $crm = $this->makeResource('CRM');
        $legacy = $this->makeResource('OLD', false);

        $ready = $this->makeRequest($employee, $vpn, 'approved', $manager);

        // Ya concedida.
        $granted = $this->makeRequest($employee, $erp, 'approved', $manager);
        $this->grant($granted, $admin);

        // Pendiente y rechazada.
        $this->makeRequest($employee, $crm);
        $this->makeRequest($employee, $crm, 'rejected', $manager);

        // Solicitante inactivo y recurso inactivo.
        $this->makeRequest($inactiveEmployee, $vpn, 'approved', $manager);
        $this->makeRequest($employee, $legacy, 'approved', $manager);

        // Solicitud propia del administrador.
        $this->makeRequest($admin, $crm, 'approved', $manager);

        $this->actingAs($admin, 'web')
            ->getJson('/api/access-requests/ready-to-grant')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', $ready->id)
            ->assertJsonPath('data.0.resource.code', 'VPN')
            ->assertJsonPath('data.0.requester.id', $employee->id)
            ->assertJsonPath('data.0.decided_by.id', $manager->id)
            ->assertJsonMissingPath('data.0.requester.password');
    }

    public function test_grant_queue_requires_grant_permission(): void
    {
        $manager = $this->makeUser('resource_manager');

        $this->actingAs($manager, 'web')
            ->getJson('/api/access-requests/ready-to-grant')
            ->assertForbidden();
    }

    /*
    |--------------------------------------------------------------------------
    | RECURSOS INACTIVOS
    |--------------------------------------------------------------------------
    */

    public function test_admin_can_include_inactive_resources(): void
    {
        $admin = $this->makeUser('iam_admin');

        $this->makeResource('VPN');
        $this->makeResource('OLD', false);

        $this->actingAs($admin, 'web')
            ->getJson('/api/resources')
            ->assertOk()
            ->assertJsonCount(1, 'data');

        $this->actingAs($admin, 'web')
            ->getJson('/api/resources?include_inactive=1')
            ->assertOk()
            ->assertJsonCount(2, 'data')
            ->assertJsonPath('data.0.code', 'VPN');
    }

    public function test_employee_cannot_include_inactive_resources(): void
    {
        $employee = $this->makeUser('employee');

        $this->makeResource('VPN');
        $this->makeResource('OLD', false);

        $this->actingAs($employee, 'web')
            ->getJson('/api/resources?include_inactive=1')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.code', 'VPN');
    }

    /*
    |--------------------------------------------------------------------------
    | AUDITORÍA
    |--------------------------------------------------------------------------
    */

    public function test_audit_log_includes_actor_name_without_email(): void
    {
        $admin = $this->makeUser('iam_admin');

        $this->actingAs($admin, 'web')
            ->postJson('/api/departments', [
                'name' => 'Seguridad',
                'code' => 'SEC',
            ])
            ->assertCreated();

        $this->actingAs($admin, 'web')
            ->getJson('/api/audit-logs')
            ->assertOk()
            ->assertJsonPath('data.0.actor.id', $admin->id)
            ->assertJsonPath('data.0.actor.name', $admin->name)
            ->assertJsonMissingPath('data.0.actor.email');
    }

    /*
    |--------------------------------------------------------------------------
    | DASHBOARD
    |--------------------------------------------------------------------------
    */

    public function test_visitor_cannot_read_dashboard(): void
    {
        $this->getJson('/api/dashboard')->assertUnauthorized();
    }

    public function test_employee_dashboard_only_contains_allowed_sections(): void
    {
        $employee = $this->makeUser('employee');
        $resource = $this->makeResource();

        $this->makeRequest($employee, $resource);

        $data = $this->actingAs($employee, 'web')
            ->getJson('/api/dashboard')
            ->assertOk()
            ->assertJsonPath('data.my_requests.pending', 1)
            ->assertJsonPath('data.resources.active', 1)
            ->assertJsonPath('data.my_access.active_grants', 0)
            ->json('data');

        $this->assertArrayNotHasKey('identities', $data);
        $this->assertArrayNotHasKey('approvals', $data);
        $this->assertArrayNotHasKey('provisioning', $data);
        $this->assertArrayNotHasKey('access', $data);
        $this->assertArrayNotHasKey('recent_activity', $data);
        $this->assertArrayNotHasKey('departments', $data);
    }

    public function test_admin_dashboard_contains_governance_sections(): void
    {
        $admin = $this->makeUser('iam_admin');
        $manager = $this->makeUser('resource_manager');
        $employee = $this->makeUser('employee');
        $this->makeUser('employee', 'suspended');

        Department::create([
            'name' => 'Ventas',
            'code' => 'VTA',
            'is_active' => true,
        ]);

        $resource = $this->makeResource();

        $this->makeRequest($employee, $resource, 'approved', $manager);

        $this->actingAs($admin, 'web')
            ->getJson('/api/dashboard')
            ->assertOk()
            ->assertJsonPath('data.identities.active', 3)
            ->assertJsonPath('data.identities.suspended', 1)
            ->assertJsonPath('data.departments.active', 1)
            ->assertJsonPath('data.provisioning.ready_to_grant', 1)
            ->assertJsonPath('data.access.active_grants', 0)
            ->assertJsonStructure([
                'data' => ['recent_activity'],
            ]);
    }

    public function test_manager_dashboard_counts_assigned_approvals(): void
    {
        $admin = $this->makeUser('iam_admin');
        $manager = $this->makeUser('resource_manager');
        $employee = $this->makeUser('employee');

        $assigned = $this->makeResource('VPN');
        $other = $this->makeResource('ERP');

        $assigned->approvers()->attach(
            $manager->id,
            ['assigned_by_user_id' => $admin->id]
        );

        $this->makeRequest($employee, $assigned);
        $this->makeRequest($employee, $other);

        $data = $this->actingAs($manager, 'web')
            ->getJson('/api/dashboard')
            ->assertOk()
            ->assertJsonPath('data.approvals.pending', 1)
            ->json('data');

        $this->assertArrayNotHasKey('identities', $data);
        $this->assertArrayNotHasKey('provisioning', $data);
    }
}
