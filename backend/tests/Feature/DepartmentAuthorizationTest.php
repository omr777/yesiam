<?php

namespace Tests\Feature;

use App\Models\Department;
use App\Models\Role;
use App\Models\User;
use Database\Seeders\RbacSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class DepartmentAuthorizationTest extends TestCase
{
    use RefreshDatabase;

    private function userWithRole(string $roleCode): User
    {
        // Prepara roles y permisos en la base de pruebas.
        $this->seed(RbacSeeder::class);

        $user = User::factory()->create([
            'status' => 'active',
        ]);

        $role = Role::where('code', $roleCode)
            ->firstOrFail();

        $user->roles()->attach($role->id);

        return $user;
    }

    public function test_visitor_cannot_create_department(): void
    {
        $this->postJson('/api/departments', [
            'name' => 'Finanzas',
            'code' => 'FIN',
        ])->assertStatus(401);
    }

    public function test_employee_cannot_create_department(): void
    {
        $employee = $this->userWithRole('employee');

        $this->actingAs($employee, 'web')
            ->postJson('/api/departments', [
                'name' => 'Finanzas',
                'code' => 'FIN',
            ])
            ->assertStatus(403);

        $this->assertDatabaseMissing('departments', [
            'code' => 'FIN',
        ]);
    }

    public function test_admin_can_create_department(): void
    {
        $admin = $this->userWithRole('iam_admin');

        $this->actingAs($admin, 'web')
            ->postJson('/api/departments', [
                'name' => 'Finanzas',
                'code' => 'FIN',
            ])
            ->assertStatus(201)
            ->assertJsonPath('data.code', 'FIN');

        $this->assertDatabaseHas('departments', [
            'name' => 'Finanzas',
            'code' => 'FIN',
        ]);
    }

    public function test_admin_can_list_departments(): void
    {
        $admin = $this->userWithRole('iam_admin');

        Department::create([
            'name' => 'Sistemas',
            'code' => 'IT',
            'is_active' => true,
        ]);

        $this->actingAs($admin, 'web')
            ->getJson('/api/departments')
            ->assertOk()
            ->assertJsonFragment([
                'code' => 'IT',
            ]);
    }

    public function test_suspended_admin_cannot_create_department(): void
    {
        $admin = $this->userWithRole('iam_admin');

        $this->actingAs($admin, 'web');

        $admin->forceFill([
            'status' => 'suspended',
        ])->save();

        $this->postJson('/api/departments', [
            'name' => 'Finanzas',
            'code' => 'FIN',
        ])->assertStatus(403);
    }
    
public function test_employee_cannot_update_department(): void
{
    $employee = $this->userWithRole('employee');

    $department = Department::create([
        'name' => 'Finanzas',
        'code' => 'FIN',
        'is_active' => true,
    ]);

    $this->actingAs($employee, 'web')
        ->patchJson("/api/departments/{$department->id}", [
            'name' => 'Finanzas Modificado',
        ])
        ->assertStatus(403);

    $this->assertDatabaseHas('departments', [
        'id' => $department->id,
        'name' => 'Finanzas',
    ]);
}

public function test_admin_can_update_department(): void
{
    $admin = $this->userWithRole('iam_admin');

    $department = Department::create([
        'name' => 'Finanzas',
        'code' => 'FIN',
        'is_active' => true,
    ]);

    $this->actingAs($admin, 'web')
        ->patchJson("/api/departments/{$department->id}", [
            'name' => 'Administración Financiera',
        ])
        ->assertOk()
        ->assertJsonPath(
            'data.name',
            'Administración Financiera'
        );

    $this->assertDatabaseHas('departments', [
        'id' => $department->id,
        'name' => 'Administración Financiera',
        'code' => 'FIN',
    ]);
}

public function test_employee_cannot_deactivate_department(): void
{
    $employee = $this->userWithRole('employee');

    $department = Department::create([
        'name' => 'Finanzas',
        'code' => 'FIN',
        'is_active' => true,
    ]);

    $this->actingAs($employee, 'web')
        ->patchJson(
            "/api/departments/{$department->id}/deactivate"
        )
        ->assertStatus(403);

    $this->assertDatabaseHas('departments', [
        'id' => $department->id,
        'is_active' => true,
    ]);
}

public function test_admin_can_deactivate_department(): void
{
    $admin = $this->userWithRole('iam_admin');

    $department = Department::create([
        'name' => 'Finanzas',
        'code' => 'FIN',
        'is_active' => true,
    ]);

    $this->actingAs($admin, 'web')
        ->patchJson(
            "/api/departments/{$department->id}/deactivate"
        )
        ->assertOk()
        ->assertJsonPath('data.is_active', false);

    $this->assertDatabaseHas('departments', [
        'id' => $department->id,
        'name' => 'Finanzas',
        'is_active' => false,
    ]);
}

public function test_inactive_department_cannot_be_updated(): void
{
    $admin = $this->userWithRole('iam_admin');

    $department = Department::create([
        'name' => 'Finanzas',
        'code' => 'FIN',
        'is_active' => false,
    ]);

    $this->actingAs($admin, 'web')
        ->patchJson("/api/departments/{$department->id}", [
            'name' => 'Nuevo nombre',
        ])
        ->assertStatus(409);

    $this->assertDatabaseHas('departments', [
        'id' => $department->id,
        'name' => 'Finanzas',
    ]);
}

public function test_admin_cannot_duplicate_department_code(): void
{
    $admin = $this->userWithRole('iam_admin');

    Department::create([
        'name' => 'Finanzas',
        'code' => 'FIN',
        'is_active' => true,
    ]);

    $department = Department::create([
        'name' => 'Sistemas',
        'code' => 'IT',
        'is_active' => true,
    ]);

    $this->actingAs($admin, 'web')
        ->patchJson("/api/departments/{$department->id}", [
            'code' => 'FIN',
        ])
        ->assertStatus(422)
        ->assertJsonValidationErrors(['code']);

    $this->assertDatabaseHas('departments', [
        'id' => $department->id,
        'code' => 'IT',
    ]);
}

}
