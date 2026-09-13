<?php

namespace Tests\Feature\Mobile;

use App\Models\EmailAccount;
use App\Models\User;
use App\Models\Workspace;
use Database\Seeders\FoundationSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class MobileV2ExtensionsTest extends TestCase
{
    use RefreshDatabase;

    public function test_forgot_password_validation_requires_email(): void
    {
        $this->seed(FoundationSeeder::class);

        $this->postJson('/api/mobile/v1/auth/forgot-password', [])
            ->assertStatus(422)
            ->assertJsonPath('success', false)
            ->assertJsonStructure(['errors' => ['email']]);
    }

    public function test_emails_accounts_lists_workspace_accounts_without_secrets(): void
    {
        $this->seed(FoundationSeeder::class);
        [$user, $workspace, $token] = $this->authMember('emails-accounts@example.com');

        EmailAccount::withoutGlobalScopes()->create([
            'workspace_id' => $workspace->id,
            'name' => 'Support Mail',
            'email' => 'support@example.test',
            'password' => 'secret-password',
            'imap_host' => 'imap.example.test',
            'imap_port' => 993,
            'smtp_host' => 'smtp.example.test',
            'smtp_port' => 587,
            'brand_color' => '#06C2A4',
            'logo_path' => null,
            'aliases' => ['Support'],
        ]);

        $response = $this->withToken($token)
            ->withHeader('X-Workspace-Id', (string) $workspace->id)
            ->getJson('/api/mobile/v1/emails/accounts')
            ->assertOk()
            ->assertJsonPath('success', true);

        $data = $response->json('data');
        $this->assertIsArray($data);
        $this->assertCount(1, $data);
        $this->assertSame('Support Mail', $data[0]['name']);
        $this->assertSame('support@example.test', $data[0]['email']);
        $this->assertSame('#06C2A4', $data[0]['brand_color']);
        $this->assertArrayNotHasKey('password', $data[0]);
        $this->assertArrayNotHasKey('imap_host', $data[0]);
    }

    public function test_channels_returns_array(): void
    {
        $this->seed(FoundationSeeder::class);
        [$user, $workspace, $token] = $this->authMember('channels@example.com');

        $response = $this->withToken($token)
            ->withHeader('X-Workspace-Id', (string) $workspace->id)
            ->getJson('/api/mobile/v1/channels')
            ->assertOk()
            ->assertJsonPath('success', true);

        $data = $response->json('data');
        $this->assertIsArray($data);
        $this->assertNotEmpty($data);
        $this->assertArrayHasKey('key', $data[0]);
        $this->assertArrayHasKey('status', $data[0]);
        $this->assertArrayHasKey('status_label', $data[0]);
        $this->assertArrayHasKey('can_connect_in_app', $data[0]);
    }

    public function test_plan_returns_success_with_workspace(): void
    {
        $this->seed(FoundationSeeder::class);
        [$user, $workspace, $token] = $this->authMember('plan@example.com');

        $this->withToken($token)
            ->withHeader('X-Workspace-Id', (string) $workspace->id)
            ->getJson('/api/mobile/v1/plan')
            ->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonStructure(['data' => ['features', 'limits', 'meters']]);
    }

    public function test_profile_patch_updates_name_and_locale(): void
    {
        $this->seed(FoundationSeeder::class);
        [$user, $workspace, $token] = $this->authMember('profile@example.com');

        $this->withToken($token)
            ->patchJson('/api/mobile/v1/auth/profile', [
                'name' => 'اسم محدّث',
                'locale' => 'en',
                'timezone' => 'Asia/Riyadh',
            ])
            ->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.name', 'اسم محدّث')
            ->assertJsonPath('data.locale', 'en')
            ->assertJsonPath('data.timezone', 'Asia/Riyadh');

        $this->assertSame('اسم محدّث', $user->fresh()->name);
        $this->assertSame('en', $user->fresh()->locale);
    }

    public function test_password_change_with_current_password(): void
    {
        $this->seed(FoundationSeeder::class);
        [$user, $workspace, $token] = $this->authMember('password@example.com');

        $this->withToken($token)
            ->putJson('/api/mobile/v1/auth/password', [
                'current_password' => 'password',
                'password' => 'new-password-123',
                'password_confirmation' => 'new-password-123',
            ])
            ->assertOk()
            ->assertJsonPath('success', true);

        $this->assertTrue(Hash::check('new-password-123', $user->fresh()->password));
    }

    /**
     * @return array{0:User,1:Workspace,2:string}
     */
    private function authMember(string $email = 'mobile-v2@example.com'): array
    {
        [$user, $workspace] = $this->makeMember($email);
        $login = $this->postJson('/api/mobile/v1/auth/login', [
            'email' => $user->email,
            'password' => 'password',
            'workspace_id' => $workspace->id,
        ])->assertOk();

        return [$user, $workspace, $login->json('data.token')];
    }

    /**
     * @return array{0:User,1:Workspace}
     */
    private function makeMember(string $email = 'mobile-v2@example.com'): array
    {
        $user = User::factory()->create([
            'email' => $email,
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

        return [$user, $workspace];
    }
}
