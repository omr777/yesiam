<?php

namespace Tests\Feature;

use App\Models\Department;
use App\Models\Role;
use App\Models\User;
use Database\Seeders\RbacSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class IdentityMovementTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RbacSeeder::class);
    }

    private function userWithRole(string $code): User
    {
        $user = User::factory()->create([
            'status' => 'active',
        ]);

        $role = Role::where('code', $code)->firstOrFail();

        $user->roles()->attach($role->id);

        return $user;
    }

    private function department(
        string $name,
        string $code,
        bool $active = true
    ): Department {
        return Department::create([
            'name' => $name,
            'code' => $code,
            'is_active' => $active,
        ]);
    }

    public function test_visitor_cannot_move_employee(): void
    {
        $employee = $this->userWithRole('employee');

        $this->patchJson(
            "/api/users/{$employee->id}/department",
            ['department_id' => 1]
        )->assertStatus(401);
    }

    public function test_employee_cannot_move_another_employee(): void
    {
        $actor = $this->userWithRole('employee');
        $target = $this->userWithRole('employee');
        $systems = $this->department('Sistemas', 'IT');

        $this->actingAs($actor, 'web')
            ->patchJson(
                "/api/users/{$target->id}/department",
                ['department_id' => $systems->id]
            )->assertStatus(403);

        $this->assertNull($target->fresh()->department_id);
    }

    public function test_admin_can_move_employee(): void
    {
        $admin = $this->userWithRole('iam_admin');
        $employee = $this->userWithRole('employee');

        $finance = $this->department('Finanzas', 'FIN');
        $systems = $this->department('Sistemas', 'IT');

        $employee->department_id = $finance->id;
        $employee->save();

        $this->actingAs($admin, 'web')
            ->patchJson(
                "/api/users/{$employee->id}/department",
                ['department_id' => $systems->id]
            )
            ->assertOk()
            ->assertJsonPath(
                'data.previous_department_id',
                $finance->id
            )
            ->assertJsonPath(
                'data.department_id',
                $systems->id
            );

        $this->assertDatabaseHas('users', [
            'id' => $employee->id,
            'department_id' => $systems->id,
        ]);

        // El movimiento no debe elevar privilegios.
        $this->assertTrue($employee->fresh()->hasRole('employee'));
        $this->assertFalse($employee->fresh()->hasRole('iam_admin'));
    }

    public function test_admin_cannot_move_self(): void
    {
        $admin = $this->userWithRole('iam_admin');
        $systems = $this->department('Sistemas', 'IT');

        $this->actingAs($admin, 'web')
            ->patchJson(
                "/api/users/{$admin->id}/department",
                ['department_id' => $systems->id]
            )->assertStatus(403);

        $this->assertNull($admin->fresh()->department_id);
    }

    public function test_cannot_move_to_inactive_department(): void
    {
        $admin = $this->userWithRole('iam_admin');
        $employee = $this->userWithRole('employee');

        $inactive = $this->department(
            'Departamento antiguo',
            'OLD',
            false
        );

        $this->actingAs($admin, 'web')
            ->patchJson(
                "/api/users/{$employee->id}/department",
                ['department_id' => $inactive->id]
            )
            ->assertStatus(422)
            ->assertJsonValidationErrors(['department_id']);

        $this->assertNull($employee->fresh()->department_id);
    }

    public function test_cannot_move_to_same_department(): void
    {
        $admin = $this->userWithRole('iam_admin');
        $employee = $this->userWithRole('employee');
        $finance = $this->department('Finanzas', 'FIN');

        $employee->department_id = $finance->id;
        $employee->save();

        $this->actingAs($admin, 'web')
            ->patchJson(
                "/api/users/{$employee->id}/department",
                ['department_id' => $finance->id]
            )->assertStatus(409);
    }

    public function test_cannot_move_disabled_identity(): void
    {
        $admin = $this->userWithRole('iam_admin');
        $employee = $this->userWithRole('employee');
        $systems = $this->department('Sistemas', 'IT');

        $employee->forceFill([
            'status' => 'disabled',
        ])->save();

        $this->actingAs($admin, 'web')
            ->patchJson(
                "/api/users/{$employee->id}/department",
                ['department_id' => $systems->id]
            )->assertStatus(409);

        $this->assertNull($employee->fresh()->department_id);
    }
}
