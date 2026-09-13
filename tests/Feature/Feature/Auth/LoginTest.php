<?php

namespace Tests\Feature\Feature\Auth;

use App\Models\User;
use App\Models\Workspace;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class LoginTest extends TestCase
{
    use RefreshDatabase;

    public function test_user_can_login_with_email_password_for_own_workspace(): void
    {
        $user = User::factory()->create([
            'email' => 'owner@example.com',
            'password' => 'password',
        ]);
        $workspace = Workspace::factory()->create([
            'owner_user_id' => $user->id,
            'type' => 'company',
        ]);
        $workspace->users()->attach($user->id, [
            'membership_role' => 'owner',
            'status' => 'active',
            'joined_at' => now(),
        ]);

        $response = $this->postJson('/api/auth/login', [
            'email_or_phone' => 'owner@example.com',
            'password' => 'password',
            'workspace_id' => $workspace->id,
        ]);

        $response
            ->assertOk()
            ->assertJsonPath('data.workspace.id', $workspace->id)
            ->assertJsonStructure(['data' => ['token']]);
    }

    public function test_user_cannot_login_to_workspace_without_membership(): void
    {
        $user = User::factory()->create([
            'email' => 'user@example.com',
            'password' => 'password',
        ]);
        $workspace = Workspace::factory()->create();

        $response = $this->postJson('/api/auth/login', [
            'email_or_phone' => 'user@example.com',
            'password' => 'password',
            'workspace_id' => $workspace->id,
        ]);

        $response->assertNotFound();
    }
}
