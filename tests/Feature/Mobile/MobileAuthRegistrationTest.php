<?php

namespace Tests\Feature\Mobile;

use App\Models\Subscription;
use App\Models\User;
use App\Models\Workspace;
use App\Notifications\Auth\VerifyEmailNotification;
use Database\Seeders\FoundationSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Notification;
use Tests\TestCase;

class MobileAuthRegistrationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(FoundationSeeder::class);
        Notification::fake();
    }

    public function test_valid_payload_creates_user_workspace_and_returns_201(): void
    {
        $response = $this->postJson('/api/mobile/v1/auth/register', $this->validPayload());

        $response
            ->assertCreated()
            ->assertJsonPath('success', true)
            ->assertJsonPath('message', 'تم إنشاء الحساب بنجاح.')
            ->assertJsonPath('data.user.email', 'noura.register@example.test')
            ->assertJsonPath('data.user.name', 'نورة العتيبي')
            ->assertJsonPath('data.workspace.name', 'متجر نورة')
            ->assertJsonPath('data.workspace.type', 'company')
            ->assertJsonPath('data.workspace.is_owner', true)
            ->assertJsonPath('data.participant.type', 'company_staff')
            ->assertJsonStructure(['data' => ['token', 'token_type', 'user', 'workspace', 'workspaces']]);

        $this->assertNotEmpty($response->json('data.token'));

        $user = User::query()->where('email', 'noura.register@example.test')->first();
        $this->assertNotNull($user);
        $this->assertTrue(Hash::check('password', $user->password));
        $this->assertNull($user->email_verified_at);
        $this->assertFalse($user->workerProfile()->exists());

        $workspace = Workspace::query()->where('owner_user_id', $user->id)->first();
        $this->assertNotNull($workspace);
        $this->assertSame('company', $workspace->type);
        $this->assertNotSame('', (string) $workspace->slug);
        $this->assertDatabaseHas('workspace_users', [
            'workspace_id' => $workspace->id,
            'user_id' => $user->id,
            'membership_role' => 'owner',
            'status' => 'active',
        ]);
        $this->assertSame(
            1,
            Subscription::withoutGlobalScopes()->where('workspace_id', $workspace->id)->count()
        );

        Notification::assertSentTo($user, VerifyEmailNotification::class);

        $this->withToken($response->json('data.token'))
            ->getJson('/api/mobile/v1/auth/me')
            ->assertOk()
            ->assertJsonPath('data.user.email', 'noura.register@example.test')
            ->assertJsonPath('data.workspace.id', $workspace->id);
    }

    public function test_omitted_workspace_name_uses_user_name_workspace(): void
    {
        $payload = $this->validPayload();
        unset($payload['workspace_name']);

        $this->postJson('/api/mobile/v1/auth/register', $payload)
            ->assertCreated()
            ->assertJsonPath('data.workspace.name', 'نورة العتيبي Workspace');
    }

    public function test_returns_422_when_email_is_already_taken(): void
    {
        User::factory()->create(['email' => 'noura.register@example.test']);

        $this->postJson('/api/mobile/v1/auth/register', $this->validPayload())
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['email']);

        $this->assertSame(1, User::query()->where('email', 'noura.register@example.test')->count());
    }

    public function test_returns_422_when_required_fields_are_missing(): void
    {
        $this->postJson('/api/mobile/v1/auth/register', [])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['name', 'email', 'password']);
    }

    public function test_returns_422_when_password_confirmation_does_not_match(): void
    {
        $payload = $this->validPayload();
        $payload['password_confirmation'] = 'different-password';

        $this->postJson('/api/mobile/v1/auth/register', $payload)
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['password']);
    }

    public function test_returns_422_when_password_is_too_short(): void
    {
        $payload = $this->validPayload();
        $payload['password'] = 'short';
        $payload['password_confirmation'] = 'short';

        $this->postJson('/api/mobile/v1/auth/register', $payload)
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['password']);
    }

    public function test_returns_422_when_phone_is_already_taken(): void
    {
        User::factory()->create(['phone' => '+966500000099']);

        $payload = $this->validPayload();
        $payload['phone'] = '+966500000099';

        $this->postJson('/api/mobile/v1/auth/register', $payload)
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['phone']);
    }

    public function test_does_not_create_a_second_workspace_for_duplicate_email(): void
    {
        $this->postJson('/api/mobile/v1/auth/register', $this->validPayload())->assertCreated();

        $this->postJson('/api/mobile/v1/auth/register', $this->validPayload())
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['email']);

        $this->assertSame(1, Workspace::query()->where('name', 'متجر نورة')->count());
    }

    public function test_returns_429_when_register_is_rate_limited(): void
    {
        $server = ['REMOTE_ADDR' => '203.0.113.88'];

        for ($i = 0; $i < 10; $i++) {
            $this->withServerVariables($server)
                ->postJson('/api/mobile/v1/auth/register', [])
                ->assertUnprocessable();
        }

        $this->withServerVariables($server)
            ->postJson('/api/mobile/v1/auth/register', $this->validPayload([
                'email' => 'rate-limited@example.test',
            ]))
            ->assertTooManyRequests();
    }

    /**
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    private function validPayload(array $overrides = []): array
    {
        return array_merge([
            'name' => 'نورة العتيبي',
            'email' => 'noura.register@example.test',
            'password' => 'password',
            'password_confirmation' => 'password',
            'workspace_name' => 'متجر نورة',
            'device_name' => 'Hasim Test',
            'device_type' => 'android',
        ], $overrides);
    }
}
