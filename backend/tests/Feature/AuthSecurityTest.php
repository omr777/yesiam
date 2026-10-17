<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class AuthSecurityTest extends TestCase
{
    use RefreshDatabase;

    public function test_visitor_cannot_access_profile(): void
    {
        $this->getJson('/api/user')
            ->assertStatus(401);
    }

    public function test_active_user_can_access_profile(): void
    {
        $user = User::factory()->create([
            'status' => 'active',
        ]);

        $this->actingAs($user, 'web')
            ->getJson('/api/user')
            ->assertOk()
            ->assertJsonPath('email', $user->email);
    }

    public function test_suspended_user_is_denied_access(): void
    {
        $user = User::factory()->create([
            'status' => 'active',
        ]);

        $this->actingAs($user, 'web');

        $user->forceFill([
            'status' => 'suspended',
        ])->save();

        $this->getJson('/api/user')
            ->assertStatus(403);
    }

    public function test_incorrect_password_is_rejected(): void
    {
        $user = User::factory()->create([
            'status' => 'active',
            'password' => Hash::make('ExamplePassword123!'),
        ]);

        $this->postJson('/login', [
            'email' => $user->email,
            'password' => 'IncorrectPassword!',
        ])->assertStatus(422);

        $this->assertGuest('web');
    }

    public function test_suspended_user_cannot_log_in(): void
    {
        $user = User::factory()->create([
            'status' => 'suspended',
            'password' => Hash::make('ExamplePassword123!'),
        ]);

        $this->postJson('/login', [
            'email' => $user->email,
            'password' => 'ExamplePassword123!',
        ])->assertStatus(422);

        $this->assertGuest('web');
    }
}
