<?php

namespace Tests\Feature;

use App\Models\AuditLog;
use App\Models\Department;
use App\Models\Role;
use App\Models\User;
use Database\Seeders\RbacSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class DepartmentAuditTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

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

    private function makeDepartment(): Department
    {
        return Department::create([
            'name' => 'Finanzas',
            'code' => 'FIN',
            'is_active' => true,
        ]);
    }

    /*
    |--------------------------------------------------------------------------
    | PRUEBA 1: CREACIÓN DE DEPARTAMENTO
    |--------------------------------------------------------------------------
    */
    public function test_creating_department_generates_audit_log(): void
    {
        $admin = $this->makeUser('iam_admin');

        $this->actingAs($admin, 'web')
            ->postJson('/api/departments', [
                'name' => 'Recursos Humanos',
                'code' => 'RH',
            ])
            ->assertCreated();

        $department = Department::where(
            'code',
            'RH'
        )->firstOrFail();

        $log = AuditLog::where(
            'action',
            'department.created'
        )->firstOrFail();

        $this->assertEquals(
            $admin->id,
            $log->actor_user_id
        );

        $this->assertEquals(
            'department',
            $log->entity_type
        );

        $this->assertEquals(
            $department->id,
            $log->entity_id
        );

        $this->assertEquals(
            'success',
            $log->outcome
        );

        $this->assertEquals(
            'Recursos Humanos',
            $log->metadata['name']
        );
    }

    /*
    |--------------------------------------------------------------------------
    | PRUEBA 2: ACTUALIZACIÓN DE DEPARTAMENTO
    |--------------------------------------------------------------------------
    */
    public function test_updating_department_records_old_and_new_values(): void
    {
        $admin = $this->makeUser('iam_admin');
        $department = $this->makeDepartment();

        $this->actingAs($admin, 'web')
            ->patchJson(
                "/api/departments/{$department->id}",
                ['name' => 'Finanzas Corporativas']
            )
            ->assertOk();

        $log = AuditLog::where(
            'action',
            'department.updated'
        )->firstOrFail();

        $this->assertEquals(
            $department->id,
            $log->entity_id
        );

        $this->assertEquals(
            'Finanzas',
            $log->metadata['changes']['name']['old']
        );

        $this->assertEquals(
            'Finanzas Corporativas',
            $log->metadata['changes']['name']['new']
        );

        $this->assertEquals(
            'Finanzas Corporativas',
            $department->fresh()->name
        );
    }

    /*
    |--------------------------------------------------------------------------
    | PRUEBA 3: DESACTIVACIÓN DE DEPARTAMENTO
    |--------------------------------------------------------------------------
    */
    public function test_deactivating_department_generates_audit_log(): void
    {
        $admin = $this->makeUser('iam_admin');
        $department = $this->makeDepartment();

        $this->actingAs($admin, 'web')
            ->patchJson(
                "/api/departments/{$department->id}/deactivate"
            )
            ->assertOk();

        $log = AuditLog::where(
            'action',
            'department.deactivated'
        )->firstOrFail();

        $this->assertEquals(
            $admin->id,
            $log->actor_user_id
        );

        $this->assertEquals(
            $department->id,
            $log->entity_id
        );

        $this->assertTrue(
            $log->metadata['previous_is_active']
        );

        $this->assertFalse(
            $log->metadata['new_is_active']
        );

        $this->assertFalse(
            (bool) $department->fresh()->is_active
        );
    }

    /*
    |--------------------------------------------------------------------------
    | PRUEBA 4: NO AUDITAR CAMBIOS INEXISTENTES
    |--------------------------------------------------------------------------
    */
    public function test_unchanged_department_does_not_generate_update_log(): void
    {
        $admin = $this->makeUser('iam_admin');
        $department = $this->makeDepartment();

        $this->actingAs($admin, 'web')
            ->patchJson(
                "/api/departments/{$department->id}",
                ['name' => 'Finanzas']
            )
            ->assertOk();

        $this->assertDatabaseCount('audit_logs', 0);
    }

    /*
    |--------------------------------------------------------------------------
    | PRUEBA 5: OPERACIÓN NO AUTORIZADA
    |--------------------------------------------------------------------------
    */
    public function test_unauthorized_employee_cannot_create_department_or_log(): void
    {
        $employee = $this->makeUser('employee');

        $this->actingAs($employee, 'web')
            ->postJson('/api/departments', [
                'name' => 'Seguridad',
                'code' => 'SEC',
            ])
            ->assertForbidden();

        $this->assertDatabaseMissing('departments', [
            'code' => 'SEC',
        ]);

        $this->assertDatabaseCount('audit_logs', 0);
    }
}
