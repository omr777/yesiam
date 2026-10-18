<?php

namespace Tests\Feature;

use App\Models\Role;
use App\Models\User;
use Database\Seeders\RbacSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class IdentityDeactivationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        // Leaver necesita comprobar sesiones persistidas.
        config()->set('session.driver', 'database');

        $this->seed(RbacSeeder::class);
    }

    private function userWithRole(string $roleCode): User
    {
        $user = User::factory()->create([
            'status' => 'active',
        ]);

        $role = Role::where('code', $roleCode)
            ->firstOrFail();

        $user->roles()->attach($role->id);

        return $user;
    }

    public function test_visitor_cannot_deactivate_identity(): void
    {
        $target = $this->userWithRole('employee');

        $this->patchJson(
            "/api/users/{$target->id}/deactivate"
        )->assertStatus(401);
    }

    public function test_employee_cannot_deactivate_identity(): void
    {
        $actor = $this->userWithRole('employee');
        $target = $this->userWithRole('employee');

        $this->actingAs($actor, 'web')
            ->patchJson("/api/users/{$target->id}/deactivate")
            ->assertStatus(403);

        $this->assertEquals('active', $target->fresh()->status);
    }

    public function test_admin_cannot_deactivate_self(): void
    {
        $admin = $this->userWithRole('iam_admin');

        $this->actingAs($admin, 'web')
            ->patchJson("/api/users/{$admin->id}/deactivate")
            ->assertStatus(403);

        $this->assertEquals('active', $admin->fresh()->status);
    }

    public function test_admin_can_deactivate_employee(): void
    {
        $admin = $this->userWithRole('iam_admin');
        $target = $this->userWithRole('employee');

        $this->actingAs($admin, 'web')
            ->patchJson("/api/users/{$target->id}/deactivate")
            ->assertOk()
            ->assertJsonPath('data.status', 'disabled');

        $this->assertDatabaseHas('users', [
            'id' => $target->id,
            'status' => 'disabled',
        ]);
    }

    public function test_cannot_deactivate_twice(): void
    {
        $admin = $this->userWithRole('iam_admin');
        $target = $this->userWithRole('employee');

        $target->forceFill([
            'status' => 'disabled',
        ])->save();

        $this->actingAs($admin, 'web')
            ->patchJson("/api/users/{$target->id}/deactivate")
            ->assertStatus(409);
    }

    public function test_deactivation_revokes_sessions_and_tokens(): void
    {
        $admin = $this->userWithRole('iam_admin');
        $target = $this->userWithRole('employee');

        $sessionId = 'yesiam-target-test-session';

        DB::table('sessions')->insert([
            'id' => $sessionId,
            'user_id' => $target->id,
            'ip_address' => '127.0.0.1',
            'user_agent' => 'YESIAM automated test',
            'payload' => base64_encode('test-session'),
            'last_activity' => time(),
        ]);

        $target->createToken('test-device');
        $tokenId = $target->tokens()->firstOrFail()->id;

        $this->assertDatabaseHas('sessions', [
            'id' => $sessionId,
            'user_id' => $target->id,
        ]);

        $this->assertDatabaseHas('personal_access_tokens', [
            'id' => $tokenId,
        ]);

        $this->actingAs($admin, 'web')
            ->patchJson("/api/users/{$target->id}/deactivate")
            ->assertOk();

        $this->assertDatabaseMissing('sessions', [
            'id' => $sessionId,
        ]);

        $this->assertDatabaseMissing('personal_access_tokens', [
            'id' => $tokenId,
        ]);

        $this->assertDatabaseHas('users', [
            'id' => $target->id,
            'status' => 'disabled',
        ]);
    }

    public function test_suspended_admin_cannot_deactivate_employee(): void
    {
        $admin = $this->userWithRole('iam_admin');
        $target = $this->userWithRole('employee');

        $admin->forceFill([
            'status' => 'suspended',
        ])->save();

        $this->actingAs($admin, 'web')
            ->patchJson("/api/users/{$target->id}/deactivate")
            ->assertStatus(403);

        $this->assertEquals('active', $target->fresh()->status);
    }
}
