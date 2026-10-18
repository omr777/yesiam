<?php

namespace Tests\Feature;

use App\Models\AuditLog;
use App\Models\Department;
use App\Models\Role;
use App\Models\User;
use Database\Seeders\RbacSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class IdentityAuditTest extends TestCase
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

    /*
    |--------------------------------------------------------------------------
    | PRUEBA 1: JOINER
    |--------------------------------------------------------------------------
    */
    public function test_joiner_creates_audit_event(): void
    {
        $admin = $this->makeUser('iam_admin');

        $department = Department::create([
            'name' => 'Finanzas',
            'code' => 'FIN',
            'is_active' => true,
        ]);

        $response = $this->actingAs($admin, 'web')
            ->postJson('/api/users', [
                'name' => 'Daniela Martinez',
                'email' => 'daniela@yesiam.test',
                'department_id' => $department->id,
            ]);

        $response->assertStatus(201);

        $employee = User::where(
            'email',
            'daniela@yesiam.test'
        )->firstOrFail();

        $log = AuditLog::where(
            'action',
            'identity.created'
        )->firstOrFail();

        $this->assertEquals(
            $admin->id,
            $log->actor_user_id
        );

        $this->assertEquals(
            $employee->id,
            $log->entity_id
        );

        $this->assertEquals(
            'success',
            $log->outcome
        );

        $this->assertEquals(
            'suspended',
            $log->metadata['initial_status']
        );
    }

    /*
    |--------------------------------------------------------------------------
    | PRUEBA 2: MOVER
    |--------------------------------------------------------------------------
    */
    public function test_mover_creates_audit_event(): void
    {
        $admin = $this->makeUser('iam_admin');
        $employee = $this->makeUser('employee');

        $finance = Department::create([
            'name' => 'Finanzas',
            'code' => 'FIN',
            'is_active' => true,
        ]);

        $systems = Department::create([
            'name' => 'Sistemas',
            'code' => 'IT',
            'is_active' => true,
        ]);

        $employee->department_id = $finance->id;
        $employee->save();

        $response = $this->actingAs($admin, 'web')
            ->patchJson(
                "/api/users/{$employee->id}/department",
                [
                    'department_id' => $systems->id,
                ]
            );

        $response->assertStatus(200);

        $log = AuditLog::where(
            'action',
            'identity.department_changed'
        )->firstOrFail();

        $this->assertEquals(
            $admin->id,
            $log->actor_user_id
        );

        $this->assertEquals(
            $employee->id,
            $log->entity_id
        );

        $this->assertEquals(
            $finance->id,
            $log->metadata['previous_department_id']
        );

        $this->assertEquals(
            $systems->id,
            $log->metadata['new_department_id']
        );
    }

    /*
    |--------------------------------------------------------------------------
    | PRUEBA 3: LEAVER
    |--------------------------------------------------------------------------
    */
    public function test_leaver_creates_audit_event(): void
    {
        // Las sesiones se guardarán en MySQL durante esta prueba.
        config()->set('session.driver', 'database');

        $admin = $this->makeUser('iam_admin');
        $employee = $this->makeUser('employee');

        $response = $this->actingAs($admin, 'web')
            ->patchJson(
                "/api/users/{$employee->id}/deactivate"
            );

        $response->assertStatus(200);

        $log = AuditLog::where(
            'action',
            'identity.deactivated'
        )->firstOrFail();

        $this->assertEquals(
            $admin->id,
            $log->actor_user_id
        );

        $this->assertEquals(
            $employee->id,
            $log->entity_id
        );

        $this->assertEquals(
            'success',
            $log->outcome
        );

        $this->assertEquals(
            'active',
            $log->metadata['previous_status']
        );

        $this->assertEquals(
            'disabled',
            $log->metadata['new_status']
        );

        $this->assertDatabaseHas('users', [
            'id' => $employee->id,
            'status' => 'disabled',
        ]);
    }
}
