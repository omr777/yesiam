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
}
