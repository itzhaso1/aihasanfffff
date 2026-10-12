<?php

namespace Tests\Feature\Feature\Platform;

use App\Models\PlatformAdmin;
use App\Models\User;
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

    public function test_authenticated_platform_admin_opening_the_platform_lands_on_the_platform_dashboard(): void
    {
        $admin = PlatformAdmin::factory()->create();

        $this->actingAs($admin, 'platform_admin')
            ->get('/platform')
            ->assertRedirect(route('platform.dashboard'));

        $this->actingAs($admin, 'platform_admin')
            ->get('/platform/login')
            ->assertRedirect(route('platform.dashboard'));
    }

    public function test_guest_opening_the_platform_lands_on_the_platform_login(): void
    {
        $this->get('/platform')
            ->assertRedirect(route('platform.login'));
    }

    public function test_workspace_user_opening_the_platform_stays_on_the_platform_login(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)
            ->get('/platform/login')
            ->assertOk()
            ->assertSee('دخول منصة الإدارة');
    }
}
