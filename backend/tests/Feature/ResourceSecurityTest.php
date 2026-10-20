<?php

namespace Tests\Feature;

use App\Models\Resource;
use App\Models\Role;
use App\Models\User;
use Database\Seeders\RbacSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ResourceSecurityTest extends TestCase
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

    private function makeResource(
        string $code = 'VPN',
        bool $active = true
    ): Resource {
        return Resource::create([
            'name' => "Recurso {$code}",
            'code' => $code,
            'description' => 'Recurso de prueba',
            'is_active' => $active,
        ]);
    }

    // PRUEBA 1: Un visitante no puede consultar.
    public function test_guest_cannot_view_resources(): void
    {
        $this->getJson('/api/resources')
            ->assertUnauthorized();
    }

    // PRUEBA 2: Empleado consulta solo recursos activos.
    public function test_employee_can_view_active_resources(): void
    {
        $employee = $this->makeUser('employee');

        $this->makeResource('VPN', true);
        $this->makeResource('OLD', false);

        $this->actingAs($employee, 'web')
            ->getJson('/api/resources')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.code', 'VPN');
    }

    // PRUEBA 3: Un empleado no puede crear recursos.
    public function test_employee_cannot_create_resource(): void
    {
        $employee = $this->makeUser('employee');

        $this->actingAs($employee, 'web')
            ->postJson('/api/resources', [
                'name' => 'Sistema contable',
                'code' => 'ACCOUNTING',
            ])
            ->assertForbidden();

        $this->assertDatabaseMissing('resources', [
            'code' => 'ACCOUNTING',
        ]);
    }

    // PRUEBA 4: Administrador puede crear recursos.
    public function test_admin_can_create_resource(): void
    {
        $admin = $this->makeUser('iam_admin');

        $this->actingAs($admin, 'web')
            ->postJson('/api/resources', [
                'name' => 'VPN corporativa',
                'code' => 'VPN',
                'description' => 'Acceso remoto',
                'is_active' => false,
            ])
            ->assertCreated()
            ->assertJsonPath('data.code', 'VPN')
            ->assertJsonPath('data.is_active', true);

        $this->assertDatabaseHas('resources', [
            'code' => 'VPN',
            'is_active' => true,
        ]);
    }

    // PRUEBA 5: No se permiten códigos duplicados.
    public function test_duplicate_resource_code_is_rejected(): void
    {
        $admin = $this->makeUser('iam_admin');

        $this->makeResource('VPN');

        $this->actingAs($admin, 'web')
            ->postJson('/api/resources', [
                'name' => 'Otra VPN',
                'code' => 'VPN',
            ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['code']);

        $this->assertDatabaseCount('resources', 1);
    }

    // PRUEBA 6: Administrador puede modificar recursos.
    public function test_admin_can_update_resource(): void
    {
        $admin = $this->makeUser('iam_admin');
        $resource = $this->makeResource('VPN');

        $this->actingAs($admin, 'web')
            ->patchJson("/api/resources/{$resource->id}", [
                'name' => 'VPN corporativa',
            ])
            ->assertOk()
            ->assertJsonPath(
                'data.name',
                'VPN corporativa'
            );

        $this->assertDatabaseHas('resources', [
            'id' => $resource->id,
            'name' => 'VPN corporativa',
        ]);
    }

    // PRUEBA 7: Administrador puede desactivar.
    public function test_admin_can_deactivate_resource(): void
    {
        $admin = $this->makeUser('iam_admin');
        $resource = $this->makeResource('VPN');

        $this->actingAs($admin, 'web')
            ->patchJson(
                "/api/resources/{$resource->id}/deactivate"
            )
            ->assertOk()
            ->assertJsonPath('data.is_active', false);

        $this->assertDatabaseHas('resources', [
            'id' => $resource->id,
            'is_active' => false,
        ]);
    }

    // PRUEBA 8: Recurso inactivo no puede modificarse.
    public function test_admin_cannot_update_inactive_resource(): void
    {
        $admin = $this->makeUser('iam_admin');
        $resource = $this->makeResource('OLD', false);

        $this->actingAs($admin, 'web')
            ->patchJson("/api/resources/{$resource->id}", [
                'name' => 'Nuevo nombre',
            ])
            ->assertForbidden();

        $this->assertDatabaseHas('resources', [
            'id' => $resource->id,
            'name' => 'Recurso OLD',
        ]);
    }

    // PRUEBA 9: Administrador suspendido no puede entrar.
    public function test_suspended_admin_cannot_view_resources(): void
    {
        $admin = $this->makeUser('iam_admin');

        $admin->forceFill([
            'status' => 'suspended',
        ])->save();

        $this->actingAs($admin, 'web')
            ->getJson('/api/resources')
            ->assertForbidden();
    }
}
