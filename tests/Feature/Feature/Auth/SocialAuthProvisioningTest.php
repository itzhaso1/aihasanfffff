<?php

namespace Tests\Feature\Feature\Auth;

use App\Models\AuthIdentity;
use App\Models\Subscription;
use App\Models\User;
use App\Notifications\Auth\ResetPasswordNotification;
use App\Services\Auth\SocialAuthService;
use App\Services\Workspace\WorkspaceService;
use App\Support\Tenancy\WorkspaceContext;
use Database\Seeders\FoundationSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Notification;
use Laravel\Socialite\Facades\Socialite;
use Laravel\Socialite\Two\User as SocialiteUser;
use Mockery;
use Tests\TestCase;

class SocialAuthProvisioningTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(FoundationSeeder::class);
    }

    public function test_new_google_user_is_provisioned_via_access_token_with_hashed_password(): void
    {
        $this->fakeGoogleTokenUser('google-new-user', 'oauth-new@hasim.test', 'OAuth New User');

        $result = app(SocialAuthService::class)->loginWithAccessToken('google', 'ya29.google-token');

        $user = $result['user']->fresh();
        $this->assertSame('oauth-new@hasim.test', $user->email);
        $this->assertNotNull($user->password);
        $this->assertNotSame('', $user->password);
        $this->assertFalse(Hash::needsRehash($user->password));
        $this->assertFalse(Hash::check('password', $user->password));
        $this->assertFalse(Hash::check('oauth-new@hasim.test', $user->password));
        $this->assertFalse(Hash::check('google-new-user', $user->password));
        $this->assertFalse(Hash::check('ya29.google-token', $user->password));
        $this->assertNotNull($user->email_verified_at);
        $this->assertSame(1, AuthIdentity::query()->where('provider', 'google')->where('user_id', $user->id)->count());
        $this->assertSame($user->id, $result['workspace']->owner_user_id);
    }

    public function test_existing_google_identity_reuses_user_without_duplicate(): void
    {
        $user = User::factory()->create([
            'email' => 'linked@hasim.test',
        ]);
        $user->forceFill(['password' => 'known-password-123'])->save();

        AuthIdentity::query()->create([
            'user_id' => $user->id,
            'provider' => 'google',
            'provider_user_id' => 'google-linked-user',
            'provider_email' => $user->email,
        ]);

        $this->fakeGoogleTokenUser('google-linked-user', $user->email, $user->name);

        app(SocialAuthService::class)->loginWithAccessToken('google', 'ya29.google-token');
        app(SocialAuthService::class)->loginWithAccessToken('google', 'ya29.google-token');

        $this->assertSame(1, User::query()->where('email', $user->email)->count());
        $this->assertSame(1, AuthIdentity::query()->where('provider', 'google')->where('user_id', $user->id)->count());
    }

    public function test_repeat_website_google_callback_does_not_create_duplicate_user(): void
    {
        $this->fakeWebsiteGoogleUser('web-google-repeat', 'repeat@hasim.test', 'Repeat User');

        $state = str_repeat('b', 40);

        $this->get('/auth/google/callback?code=oauth-code&state='.$state)
            ->assertRedirect(route('workspace.choose'));

        $firstUserId = auth()->id();
        auth()->logout();

        $this->get('/auth/google/callback?code=oauth-code&state='.$state)
            ->assertRedirect(route('workspace.choose'));

        $this->assertSame($firstUserId, auth()->id());
        $this->assertSame(1, User::query()->where('email', 'repeat@hasim.test')->count());
        $this->assertSame(1, AuthIdentity::query()->where('provider', 'google')->where('provider_user_id', 'web-google-repeat')->count());
    }

    public function test_oauth_only_user_cannot_login_with_common_password_guesses(): void
    {
        $this->fakeGoogleTokenUser('google-no-password-login', 'oauth-only@hasim.test', 'OAuth Only');

        app(SocialAuthService::class)->loginWithAccessToken('google', 'ya29.google-token');

        foreach (['password', 'oauth-only@hasim.test', 'google-no-password-login', 'ya29.google-token'] as $guess) {
            $this->postJson('/api/mobile/v1/auth/login', [
                'email' => 'oauth-only@hasim.test',
                'password' => $guess,
            ])->assertStatus(401);
        }
    }

    public function test_oauth_user_can_request_password_reset_and_set_a_new_password(): void
    {
        Notification::fake();

        $this->fakeGoogleTokenUser('google-reset-user', 'oauth-reset@hasim.test', 'OAuth Reset');
        app(SocialAuthService::class)->loginWithAccessToken('google', 'ya29.google-token');

        $user = User::query()->where('email', 'oauth-reset@hasim.test')->firstOrFail();
        $originalHash = $user->password;
        $newPassword = 'oauth-user-reset-password-123';

        $this->post('/forgot-password', ['email' => $user->email]);

        Notification::assertSentTo($user, ResetPasswordNotification::class, function ($notification) use ($user, $newPassword, $originalHash) {
            $this->post('/reset-password', [
                'token' => $notification->token,
                'email' => $user->email,
                'password' => $newPassword,
                'password_confirmation' => $newPassword,
            ])->assertSessionHasNoErrors()
                ->assertRedirect(route('login'));

            $fresh = $user->fresh();
            $this->assertTrue(Hash::check($newPassword, $fresh->password));
            $this->assertFalse(Hash::check('password', $fresh->password));
            $this->assertNotSame($originalHash, $fresh->password);

            return true;
        });
    }

    public function test_matching_email_links_google_identity_without_overwriting_existing_password(): void
    {
        $user = User::factory()->create([
            'email' => 'existing@hasim.test',
        ]);
        $user->forceFill(['password' => 'existing-password-123'])->save();
        $originalHash = $user->fresh()->password;

        $this->fakeGoogleTokenUser('google-link-existing', $user->email, $user->name);

        $result = app(SocialAuthService::class)->loginWithAccessToken('google', 'ya29.google-token');

        $this->assertSame($user->id, $result['user']->id);
        $this->assertSame($originalHash, $user->fresh()->password);
        $this->assertTrue(Hash::check('existing-password-123', $user->fresh()->password));
        $this->assertSame(1, AuthIdentity::query()->where('provider_user_id', 'google-link-existing')->count());
    }

    public function test_first_login_creates_subscription_without_an_ambient_workspace(): void
    {
        $context = app(WorkspaceContext::class);
        $context->clear();
        $context->disableTestFixtureMutations();

        $user = User::factory()->create();

        $workspace = app(WorkspaceService::class)->createForUser($user, 'individual');

        $this->assertNotNull(
            Subscription::withoutGlobalScopes()
                ->where('workspace_id', $workspace->id)
                ->first()
        );
        $this->assertNull($context->workspaceId());
    }

    private function fakeGoogleTokenUser(string $googleId, string $email, string $name): void
    {
        $socialUser = Mockery::mock(SocialiteUser::class);
        $socialUser->shouldReceive('getId')->andReturn($googleId);
        $socialUser->shouldReceive('getEmail')->andReturn($email);
        $socialUser->shouldReceive('getName')->andReturn($name);
        $socialUser->shouldReceive('getNickname')->andReturnNull();
        $socialUser->shouldReceive('getAvatar')->andReturnNull();

        $driver = Mockery::mock();
        $driver->shouldReceive('stateless')->andReturnSelf();
        $driver->shouldReceive('userFromToken')->with('ya29.google-token')->andReturn($socialUser);

        Socialite::shouldReceive('driver')->with('google')->andReturn($driver);
    }

    private function fakeWebsiteGoogleUser(string $googleId, string $email, string $name): void
    {
        $socialUser = Mockery::mock(SocialiteUser::class);
        $socialUser->shouldReceive('getId')->andReturn($googleId);
        $socialUser->shouldReceive('getEmail')->andReturn($email);
        $socialUser->shouldReceive('getName')->andReturn($name);
        $socialUser->shouldReceive('getNickname')->andReturnNull();
        $socialUser->shouldReceive('getAvatar')->andReturnNull();

        $driver = Mockery::mock();
        $driver->shouldReceive('stateless')->andReturnSelf();
        $driver->shouldReceive('user')->andReturn($socialUser);

        Socialite::shouldReceive('driver')->with('google')->andReturn($driver);
    }
}
