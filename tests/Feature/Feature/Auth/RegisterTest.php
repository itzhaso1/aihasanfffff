<?php

namespace Tests\Feature\Feature\Auth;

use Database\Seeders\FoundationSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class RegisterTest extends TestCase
{
    use RefreshDatabase;

    public function test_register_creates_user_workspace_and_token(): void
    {
        $this->seed(FoundationSeeder::class);

        $response = $this->postJson('/api/auth/register', [
            'name' => 'Hassan',
            'email' => 'hassan@example.com',
            'phone' => '+966500000001',
            'password' => 'StrongPass123!',
            'password_confirmation' => 'StrongPass123!',
            'workspace_name' => 'Hassan Personal',
        ]);

        $response
            ->assertCreated()
            ->assertJsonPath('data.workspace.type', 'company')
            ->assertJsonStructure(['data' => ['token', 'user', 'workspace']]);

        $this->assertDatabaseHas('users', [
            'email' => 'hassan@example.com',
        ]);

        $this->assertDatabaseHas('workspaces', [
            'name' => 'Hassan Personal',
            'type' => 'company',
        ]);

        $this->assertDatabaseHas('workspace_users', [
            'membership_role' => 'owner',
            'status' => 'active',
        ]);
    }

    public function test_register_still_accepts_optional_legacy_workspace_type(): void
    {
        $this->seed(FoundationSeeder::class);

        $response = $this->postJson('/api/auth/register', [
            'name' => 'Legacy User',
            'email' => 'legacy@example.com',
            'password' => 'StrongPass123!',
            'password_confirmation' => 'StrongPass123!',
            'workspace_type' => 'individual',
            'workspace_name' => 'Legacy Shop',
        ]);

        $response
            ->assertCreated()
            ->assertJsonPath('data.workspace.type', 'individual');
    }
}
