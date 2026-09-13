<?php

namespace Tests\Feature\Feature\Platform;

use App\Models\PlatformAdmin;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PlatformAdminAuthTest extends TestCase
{
    use RefreshDatabase;

    public function test_platform_admin_can_login_and_open_dashboard(): void
    {
        $admin = PlatformAdmin::factory()->create([
            'email' => 'platform@example.com',
            'password' => 'password',
        ]);

        $this->postJson('/platform/login', [
            'email' => 'platform@example.com',
            'password' => 'password',
        ])->assertOk();

        $this->getJson('/platform/dashboard')
            ->assertOk()
            ->assertJsonPath('data.admin.id', $admin->id);
    }

    public function test_regular_user_cannot_open_platform_dashboard(): void
    {
        $this->getJson('/platform/dashboard')->assertForbidden();
    }
}
