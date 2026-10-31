<?php

namespace Tests\Feature;

use App\Models\AuditLog;
use App\Models\Department;
use App\Models\Resource;
use App\Models\Role;
use App\Models\User;
use Database\Seeders\RbacSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

/*
|--------------------------------------------------------------------------
| YESIAM - ADMINISTRACIÓN DE IDENTIDADES
|--------------------------------------------------------------------------
|
| FASE 20
|
| GET   /api/users/{user}
| PATCH /api/users/{user}/activate
| PUT   /api/users/{user}/roles
| GET   /api/roles
|
*/

class IdentityAdministrationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RbacSeeder::class);
    }

    private function makeUser(
        string $roleCode = 'employee',
        string $status = 'active',
        ?int $departmentId = null
    ): User {
        $user = User::factory()->create([
            'status' => $status,
            'department_id' => $departmentId,
        ]);

        $user->roles()->attach(
            Role::where('code', $roleCode)->firstOrFail()->id
        );

        return $user;
    }

    private function makeResource(string $code = 'VPN'): Resource
    {
        return Resource::create([
            'name' => "Recurso {$code}",
            'code' => $code,
            'description' => null,
            'is_active' => true,
        ]);
    }

    /*
    |--------------------------------------------------------------------------
    | DETALLE
    |--------------------------------------------------------------------------
    */

    public function test_admin_can_view_identity_detail(): void
    {
        $admin = $this->makeUser('iam_admin');

        $department = Department::create([
            'name' => 'Ventas',
            'code' => 'VTA',
            'is_active' => true,
        ]);

        $target = $this->makeUser(
            'resource_manager',
            'active',
            $department->id
        );

        $resource = $this->makeResource();

        $resource->approvers()->attach(
            $target->id,
            ['assigned_by_user_id' => $admin->id]
        );

        $this->actingAs($admin, 'web')
            ->getJson("/api/users/{$target->id}")
            ->assertOk()
            ->assertJsonPath('data.id', $target->id)
            ->assertJsonPath('data.department.code', 'VTA')
            ->assertJsonPath('data.roles.0.code', 'resource_manager')
            ->assertJsonPath('data.managed_resources.0.code', 'VPN')
            ->assertJsonPath('data.active_access_grants_count', 0)
            ->assertJsonMissingPath('data.password')
            ->assertJsonMissingPath('data.remember_token');
    }

    public function test_employee_cannot_view_identity_detail(): void
    {
        $employee = $this->makeUser('employee');
        $other = $this->makeUser('employee');

        $this->actingAs($employee, 'web')
            ->getJson("/api/users/{$other->id}")
            ->assertForbidden();
    }

    public function test_visitor_cannot_view_identity_detail(): void
    {
        $user = $this->makeUser();

        $this->getJson("/api/users/{$user->id}")
            ->assertUnauthorized();
    }

    /*
    |--------------------------------------------------------------------------
    | ACTIVACIÓN
    |--------------------------------------------------------------------------
    */

    public function test_admin_can_activate_suspended_identity(): void
    {
        $admin = $this->makeUser('iam_admin');
        $target = $this->makeUser('employee', 'suspended');

        $response = $this->actingAs($admin, 'web')
            ->patchJson("/api/users/{$target->id}/activate")
            ->assertOk()
            ->assertJsonPath('data.status', 'active');

        $this->assertStringContainsString(
            'no-store',
            (string) $response->headers->get('Cache-Control')
        );

        $temporaryPassword = $response->json(
            'data.temporary_password'
        );

        $this->assertIsString($temporaryPassword);
        $this->assertGreaterThanOrEqual(16, strlen($temporaryPassword));

        $target->refresh();

        $this->assertSame('active', $target->status);

        /*
         * Solo se guarda el hash.
         */
        $this->assertNotSame($temporaryPassword, $target->password);
        $this->assertTrue(
            Hash::check($temporaryPassword, $target->password)
        );
    }

    public function test_activation_is_audited_without_secrets(): void
    {
        $admin = $this->makeUser('iam_admin');
        $target = $this->makeUser('employee', 'suspended');

        $temporaryPassword = $this->actingAs($admin, 'web')
            ->patchJson("/api/users/{$target->id}/activate")
            ->assertOk()
            ->json('data.temporary_password');

        $log = AuditLog::where('action', 'identity.activated')
            ->where('entity_id', $target->id)
            ->firstOrFail();

        $this->assertSame($admin->id, $log->actor_user_id);
        $this->assertSame('suspended', $log->metadata['previous_status']);
        $this->assertSame('active', $log->metadata['new_status']);

        $this->assertStringNotContainsString(
            $temporaryPassword,
            json_encode($log->metadata)
        );
    }

    public function test_activated_identity_can_log_in_with_temporary_password(): void
    {
        $admin = $this->makeUser('iam_admin');
        $target = $this->makeUser('employee', 'suspended');

        $temporaryPassword = $this->actingAs($admin, 'web')
            ->patchJson("/api/users/{$target->id}/activate")
            ->json('data.temporary_password');

        auth('web')->logout();

        $this->postJson('/login', [
            'email' => $target->email,
            'password' => $temporaryPassword,
        ])->assertOk();

        $this->assertAuthenticatedAs($target, 'web');
    }

    public function test_active_identity_cannot_be_activated_again(): void
    {
        $admin = $this->makeUser('iam_admin');
        $target = $this->makeUser('employee', 'active');

        $this->actingAs($admin, 'web')
            ->patchJson("/api/users/{$target->id}/activate")
            ->assertStatus(409);

        $this->assertDatabaseMissing('audit_logs', [
            'action' => 'identity.activated',
        ]);
    }

    public function test_disabled_identity_cannot_be_reactivated(): void
    {
        $admin = $this->makeUser('iam_admin');
        $target = $this->makeUser('employee', 'disabled');

        $this->actingAs($admin, 'web')
            ->patchJson("/api/users/{$target->id}/activate")
            ->assertStatus(409);

        $this->assertSame('disabled', $target->fresh()->status);
    }

    public function test_employee_cannot_activate_identities(): void
    {
        $employee = $this->makeUser('employee');
        $target = $this->makeUser('employee', 'suspended');

        $this->actingAs($employee, 'web')
            ->patchJson("/api/users/{$target->id}/activate")
            ->assertForbidden();

        $this->assertSame('suspended', $target->fresh()->status);
    }

    /*
    |--------------------------------------------------------------------------
    | ROLES
    |--------------------------------------------------------------------------
    */

    public function test_admin_can_replace_identity_roles(): void
    {
        $admin = $this->makeUser('iam_admin');
        $target = $this->makeUser('employee');

        $this->actingAs($admin, 'web')
            ->putJson("/api/users/{$target->id}/roles", [
                'roles' => ['resource_manager', 'employee'],
            ])
            ->assertOk()
            ->assertJsonPath('data.changed', true)
            ->assertJsonPath('data.roles', [
                'employee',
                'resource_manager',
            ]);

        $this->assertTrue($target->hasRole('resource_manager'));
        $this->assertTrue($target->hasPermission('access.approve'));

        $log = AuditLog::where('action', 'identity.roles_changed')
            ->firstOrFail();

        $this->assertSame(['employee'], $log->metadata['previous_roles']);
        $this->assertSame(
            ['employee', 'resource_manager'],
            $log->metadata['new_roles']
        );
    }

    public function test_unchanged_roles_do_not_create_audit_events(): void
    {
        $admin = $this->makeUser('iam_admin');
        $target = $this->makeUser('employee');

        $this->actingAs($admin, 'web')
            ->putJson("/api/users/{$target->id}/roles", [
                'roles' => ['employee'],
            ])
            ->assertOk()
            ->assertJsonPath('data.changed', false);

        $this->assertDatabaseMissing('audit_logs', [
            'action' => 'identity.roles_changed',
        ]);
    }

    public function test_admin_cannot_change_own_roles(): void
    {
        $admin = $this->makeUser('iam_admin');

        $this->actingAs($admin, 'web')
            ->putJson("/api/users/{$admin->id}/roles", [
                'roles' => ['employee'],
            ])
            ->assertForbidden();

        $this->assertTrue($admin->hasRole('iam_admin'));
    }

    public function test_roles_must_exist_and_cannot_be_empty(): void
    {
        $admin = $this->makeUser('iam_admin');
        $target = $this->makeUser('employee');

        $this->actingAs($admin, 'web')
            ->putJson("/api/users/{$target->id}/roles", [
                'roles' => ['super_admin'],
            ])
            ->assertStatus(422)
            ->assertJsonValidationErrors('roles.0');

        $this->actingAs($admin, 'web')
            ->putJson("/api/users/{$target->id}/roles", [
                'roles' => [],
            ])
            ->assertStatus(422)
            ->assertJsonValidationErrors('roles');

        $this->assertTrue($target->hasRole('employee'));
    }

    public function test_disabled_identity_roles_cannot_change(): void
    {
        $admin = $this->makeUser('iam_admin');
        $target = $this->makeUser('employee', 'disabled');

        $this->actingAs($admin, 'web')
            ->putJson("/api/users/{$target->id}/roles", [
                'roles' => ['iam_admin'],
            ])
            ->assertStatus(409);

        $this->assertFalse($target->hasRole('iam_admin'));
    }

    public function test_employee_cannot_assign_roles(): void
    {
        $employee = $this->makeUser('employee');
        $target = $this->makeUser('employee');

        $this->actingAs($employee, 'web')
            ->putJson("/api/users/{$target->id}/roles", [
                'roles' => ['iam_admin'],
            ])
            ->assertForbidden();

        $this->assertFalse($target->hasRole('iam_admin'));
    }

    public function test_losing_approval_permission_removes_approver_assignments(): void
    {
        $admin = $this->makeUser('iam_admin');
        $manager = $this->makeUser('resource_manager');

        $vpn = $this->makeResource('VPN');
        $erp = $this->makeResource('ERP');

        foreach ([$vpn, $erp] as $resource) {
            $resource->approvers()->attach(
                $manager->id,
                ['assigned_by_user_id' => $admin->id]
            );
        }

        $this->actingAs($admin, 'web')
            ->putJson("/api/users/{$manager->id}/roles", [
                'roles' => ['employee'],
            ])
            ->assertOk()
            ->assertJsonPath(
                'data.removed_approver_resource_ids',
                [$vpn->id, $erp->id]
            );

        $this->assertDatabaseMissing('resource_approvers', [
            'user_id' => $manager->id,
        ]);

        $this->assertSame(
            2,
            AuditLog::where('action', 'resource.approver_removed')
                ->count()
        );

        $removal = AuditLog::where('action', 'resource.approver_removed')
            ->firstOrFail();

        $this->assertSame('role_change', $removal->metadata['reason']);
    }

    public function test_keeping_approval_permission_keeps_assignments(): void
    {
        $admin = $this->makeUser('iam_admin');
        $manager = $this->makeUser('resource_manager');
        $resource = $this->makeResource();

        $resource->approvers()->attach(
            $manager->id,
            ['assigned_by_user_id' => $admin->id]
        );

        $this->actingAs($admin, 'web')
            ->putJson("/api/users/{$manager->id}/roles", [
                'roles' => ['resource_manager', 'employee'],
            ])
            ->assertOk()
            ->assertJsonPath('data.removed_approver_resource_ids', []);

        $this->assertDatabaseHas('resource_approvers', [
            'user_id' => $manager->id,
            'resource_id' => $resource->id,
        ]);
    }

    /*
    |--------------------------------------------------------------------------
    | CATÁLOGO DE ROLES
    |--------------------------------------------------------------------------
    */

    public function test_admin_can_list_roles_with_permissions(): void
    {
        $admin = $this->makeUser('iam_admin');

        $roles = $this->actingAs($admin, 'web')
            ->getJson('/api/roles')
            ->assertOk()
            ->json('data');

        $codes = array_column($roles, 'code');

        $this->assertContains('employee', $codes);
        $this->assertContains('resource_manager', $codes);
        $this->assertContains('iam_admin', $codes);

        $employee = collect($roles)->firstWhere('code', 'employee');

        $this->assertContains('access.request', $employee['permissions']);
    }

    public function test_employee_cannot_list_roles(): void
    {
        $employee = $this->makeUser('employee');

        $this->actingAs($employee, 'web')
            ->getJson('/api/roles')
            ->assertForbidden();
    }
}
