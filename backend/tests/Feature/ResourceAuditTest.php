<?php

namespace Tests\Feature;

use App\Models\AuditLog;
use App\Models\Resource;
use App\Models\Role;
use App\Models\User;
use Database\Seeders\RbacSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ResourceAuditTest extends TestCase
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

    private function makeResource(): Resource
    {
        return Resource::create([
            'name' => 'VPN antigua',
            'code' => 'VPN',
            'description' => 'Acceso remoto anterior',
            'is_active' => true,
        ]);
    }

    /*
    |--------------------------------------------------------------------------
    | PRUEBA 1: CREACIÓN
    |--------------------------------------------------------------------------
    */

    public function test_creating_resource_generates_audit_log(): void
    {
        $admin = $this->makeUser('iam_admin');

        $this->actingAs($admin, 'web')
            ->postJson('/api/resources', [
                'name' => 'VPN corporativa',
                'code' => 'VPN',
                'description' => 'Acceso remoto seguro',
            ])
            ->assertCreated();

        $resource = Resource::where('code', 'VPN')
            ->firstOrFail();

        $log = AuditLog::where(
            'action',
            'resource.created'
        )->firstOrFail();

        $this->assertEquals(
            $admin->id,
            $log->actor_user_id
        );

        $this->assertEquals(
            'resource',
            $log->entity_type
        );

        $this->assertEquals(
            $resource->id,
            $log->entity_id
        );

        $this->assertEquals(
            'success',
            $log->outcome
        );

        $this->assertEquals(
            'VPN',
            $log->metadata['code']
        );

        $this->assertDatabaseCount('audit_logs', 1);
    }

    /*
    |--------------------------------------------------------------------------
    | PRUEBA 2: ACTUALIZACIÓN
    |--------------------------------------------------------------------------
    */

    public function test_updating_resource_records_old_and_new_values(): void
    {
        $admin = $this->makeUser('iam_admin');
        $resource = $this->makeResource();

        $this->actingAs($admin, 'web')
            ->patchJson("/api/resources/{$resource->id}", [
                'name' => 'VPN corporativa',
            ])
            ->assertOk();

        $log = AuditLog::where(
            'action',
            'resource.updated'
        )->firstOrFail();

        $changes = $log->metadata['changes'];

        $this->assertEquals(
            'VPN antigua',
            $changes['name']['old']
        );

        $this->assertEquals(
            'VPN corporativa',
            $changes['name']['new']
        );

        $this->assertEquals(
            $admin->id,
            $log->actor_user_id
        );

        $this->assertEquals(
            $resource->id,
            $log->entity_id
        );

        $this->assertDatabaseCount('audit_logs', 1);
    }

    /*
    |--------------------------------------------------------------------------
    | PRUEBA 3: PRIVACIDAD DE LA DESCRIPCIÓN
    |--------------------------------------------------------------------------
    */

    public function test_description_change_does_not_copy_its_content_to_audit(): void
    {
        $admin = $this->makeUser('iam_admin');
        $resource = $this->makeResource();

        $newDescription = 'Contenido privado de ejemplo 98765';

        $this->actingAs($admin, 'web')
            ->patchJson("/api/resources/{$resource->id}", [
                'description' => $newDescription,
            ])
            ->assertOk();

        $log = AuditLog::where(
            'action',
            'resource.updated'
        )->firstOrFail();

        $changes = $log->metadata['changes'];

        $this->assertTrue(
            $changes['description_changed']
        );

        $this->assertArrayNotHasKey(
            'description',
            $changes
        );

        $this->assertStringNotContainsString(
            $newDescription,
            json_encode($log->metadata)
        );

        // El cambio real sí debe guardarse en resources.
        $this->assertEquals(
            $newDescription,
            $resource->fresh()->description
        );
    }

    /*
    |--------------------------------------------------------------------------
    | PRUEBA 4: DESACTIVACIÓN
    |--------------------------------------------------------------------------
    */

    public function test_deactivating_resource_generates_audit_log(): void
    {
        $admin = $this->makeUser('iam_admin');
        $resource = $this->makeResource();

        $this->actingAs($admin, 'web')
            ->patchJson(
                "/api/resources/{$resource->id}/deactivate"
            )
            ->assertOk();

        $log = AuditLog::where(
            'action',
            'resource.deactivated'
        )->firstOrFail();

        $this->assertEquals(
            $admin->id,
            $log->actor_user_id
        );

        $this->assertEquals(
            $resource->id,
            $log->entity_id
        );

        $this->assertTrue(
            $log->metadata['previous_is_active']
        );

        $this->assertFalse(
            $log->metadata['new_is_active']
        );

        $this->assertFalse(
            (bool) $resource->fresh()->is_active
        );
    }

    /*
    |--------------------------------------------------------------------------
    | PRUEBA 5: NO REGISTRAR CAMBIOS INEXISTENTES
    |--------------------------------------------------------------------------
    */

    public function test_unchanged_resource_does_not_generate_update_log(): void
    {
        $admin = $this->makeUser('iam_admin');
        $resource = $this->makeResource();

        $this->actingAs($admin, 'web')
            ->patchJson("/api/resources/{$resource->id}", [
                'name' => 'VPN antigua',
            ])
            ->assertOk();

        $this->assertDatabaseCount('audit_logs', 0);
    }

    /*
    |--------------------------------------------------------------------------
    | PRUEBA 6: OPERACIÓN NO AUTORIZADA
    |--------------------------------------------------------------------------
    */

    public function test_employee_cannot_modify_resource_or_generate_success_log(): void
    {
        $employee = $this->makeUser('employee');
        $resource = $this->makeResource();

        $this->actingAs($employee, 'web')
            ->patchJson("/api/resources/{$resource->id}", [
                'name' => 'VPN modificada sin permiso',
            ])
            ->assertForbidden();

        $this->assertEquals(
            'VPN antigua',
            $resource->fresh()->name
        );

        $this->assertDatabaseCount('audit_logs', 0);
    }
}
