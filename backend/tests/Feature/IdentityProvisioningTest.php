<?php

namespace Tests\Feature;

use App\Models\Department;
use App\Models\Role;
use App\Models\User;
use Database\Seeders\RbacSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class IdentityProvisioningTest extends TestCase
{
    use RefreshDatabase;

    private function createUserWithRole(string $roleCode): User
    {
        $this->seed(RbacSeeder::class);

        $user = User::factory()->create([
            'status' => 'active',
        ]);

        $role = Role::where('code', $roleCode)
            ->firstOrFail();

        $user->roles()->attach($role->id);

        return $user;
    }

    private function createDepartment(bool $active = true): Department
    {
        return Department::create([
            'name' => 'Finanzas',
            'code' => 'FIN',
            'is_active' => $active,
        ]);
    }

    private function employeeData(int $departmentId): array
    {
        return [
            'name' => 'Daniela Martinez',
            'email' => 'daniela@yesiam.test',
            'department_id' => $departmentId,
        ];
    }

    public function test_visitor_cannot_create_identity(): void
    {
        $this->postJson('/api/users', [])
            ->assertStatus(401);
    }

    public function test_employee_cannot_create_identity(): void
    {
        $employee = $this->createUserWithRole('employee');
        $department = $this->createDepartment();

        $this->actingAs($employee, 'web')
            ->postJson(
                '/api/users',
                $this->employeeData($department->id)
            )
            ->assertStatus(403);

        $this->assertDatabaseMissing('users', [
            'email' => 'daniela@yesiam.test',
        ]);
    }

    public function test_admin_can_provision_employee(): void
    {
        $admin = $this->createUserWithRole('iam_admin');
        $department = $this->createDepartment();

        $this->actingAs($admin, 'web')
            ->postJson(
                '/api/users',
                $this->employeeData($department->id)
            )
            ->assertStatus(201)
            ->assertJsonPath('data.status', 'suspended')
            ->assertJsonPath('data.roles.0', 'employee')
            ->assertJsonMissingPath('data.password');

        $user = User::where(
            'email',
            'daniela@yesiam.test'
        )->firstOrFail();

        $this->assertEquals($department->id, $user->department_id);
        $this->assertTrue($user->hasRole('employee'));
        $this->assertFalse($user->hasRole('iam_admin'));
    }

    public function test_cannot_assign_inactive_department(): void
    {
        $admin = $this->createUserWithRole('iam_admin');
        $department = $this->createDepartment(false);

        $this->actingAs($admin, 'web')
            ->postJson(
                '/api/users',
                $this->employeeData($department->id)
            )
            ->assertStatus(422)
            ->assertJsonValidationErrors(['department_id']);
    }

    public function test_cannot_create_duplicate_email(): void
    {
        $admin = $this->createUserWithRole('iam_admin');
        $department = $this->createDepartment();

        User::factory()->create([
            'email' => 'daniela@yesiam.test',
        ]);

        $this->actingAs($admin, 'web')
            ->postJson(
                '/api/users',
                $this->employeeData($department->id)
            )
            ->assertStatus(422)
            ->assertJsonValidationErrors(['email']);
    }

    public function test_client_cannot_choose_role_or_status(): void
    {
        $admin = $this->createUserWithRole('iam_admin');
        $department = $this->createDepartment();

        $data = $this->employeeData($department->id);

        $data['status'] = 'active';
        $data['role'] = 'iam_admin';
        $data['password'] = 'UnsafePassword123!';

        $this->actingAs($admin, 'web')
            ->postJson('/api/users', $data)
            ->assertStatus(201)
            ->assertJsonPath('data.status', 'suspended');

        $user = User::where(
            'email',
            'daniela@yesiam.test'
        )->firstOrFail();

        $this->assertEquals('suspended', $user->status);
        $this->assertTrue($user->hasRole('employee'));
        $this->assertFalse($user->hasRole('iam_admin'));
    }
}
