<?php

namespace Tests\Feature;

use App\Models\Department;
use App\Models\Role;
use App\Models\User;
use Database\Seeders\RbacSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/*
|--------------------------------------------------------------------------
| YESIAM - PERFIL DE SESIÓN (GET /api/user)
|--------------------------------------------------------------------------
|
| FASE 20
|
| Verifica que el perfil incluya roles y
| permisos efectivos, sin exponer secretos.
|
*/

class CurrentUserProfileTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RbacSeeder::class);
    }

    private function makeUser(
        array $roleCodes,
        string $status = 'active',
        ?int $departmentId = null
    ): User {
        $user = User::factory()->create([
            'status' => $status,
            'department_id' => $departmentId,
        ]);

        $user->roles()->attach(
            Role::whereIn('code', $roleCodes)->pluck('id')
        );

        return $user;
    }

    public function test_visitor_cannot_read_profile(): void
    {
        $this->getJson('/api/user')->assertUnauthorized();
    }

    public function test_profile_includes_roles_permissions_and_department(): void
    {
        $department = Department::create([
            'name' => 'Finanzas',
            'code' => 'FIN',
            'is_active' => true,
        ]);

        $user = $this->makeUser(
            ['employee'],
            'active',
            $department->id
        );

        $this->actingAs($user, 'web')
            ->getJson('/api/user')
            ->assertOk()
            ->assertJsonPath('id', $user->id)
            ->assertJsonPath('email', $user->email)
            ->assertJsonPath('department.code', 'FIN')
            ->assertJsonPath('roles.0.code', 'employee')
            ->assertJsonPath('permissions', [
                'access.request',
                'profile.view',
                'resources.view',
            ])
            ->assertJsonMissingPath('password')
            ->assertJsonMissingPath('remember_token');
    }

    public function test_permissions_are_the_union_of_all_roles(): void
    {
        $user = $this->makeUser([
            'employee',
            'resource_manager',
        ]);

        $permissions = $this->actingAs($user, 'web')
            ->getJson('/api/user')
            ->assertOk()
            ->json('permissions');

        $this->assertContains('access.approve', $permissions);
        $this->assertContains('access.request', $permissions);

        /*
         * Sin duplicados.
         */
        $this->assertSame(
            array_values(array_unique($permissions)),
            $permissions
        );

        $this->assertNotContains('users.manage', $permissions);
    }

    public function test_user_without_department_returns_null_department(): void
    {
        $user = $this->makeUser(['iam_admin']);

        $this->actingAs($user, 'web')
            ->getJson('/api/user')
            ->assertOk()
            ->assertJsonPath('department', null)
            ->assertJsonPath('roles.0.code', 'iam_admin');
    }
}
