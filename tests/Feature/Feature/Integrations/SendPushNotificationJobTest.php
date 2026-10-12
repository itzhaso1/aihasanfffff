<?php

namespace Tests\Feature\Feature\Integrations;

use App\Jobs\Push\SendPushNotificationJob;
use App\Models\DevicePushToken;
use App\Models\NotificationPreference;
use App\Models\User;
use App\Models\Workspace;
use App\Services\Mobile\PushDeviceService;
use Database\Seeders\FoundationSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Mockery;
use Tests\TestCase;

class SendPushNotificationJobTest extends TestCase
{
    use RefreshDatabase;

    private const SECRET_FCM_TOKEN = 'fcm-secret-token-do-not-log-me-abc123';

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(FoundationSeeder::class);
    }

    public function test_fcm_disabled_skips_with_info_log_and_completes_without_retryable_failure(): void
    {
        config()->set('services.fcm.enabled', false);
        config()->set('services.fcm.server_key', null);

        [$user, $workspace] = $this->seedPushTarget();

        Log::shouldReceive('info')
            ->once()
            ->with('Push notification skipped: FCM provider not configured', Mockery::on(function (array $context): bool {
                $this->assertSame(1, $context['device_count']);
                $this->assertArrayNotHasKey('token', $context);
                $this->assertStringNotContainsString(self::SECRET_FCM_TOKEN, json_encode($context, JSON_THROW_ON_ERROR));

                return true;
            }));

        Log::shouldReceive('warning')->never();

        (new SendPushNotificationJob(
            userId: $user->id,
            title: 'Test',
            body: 'Body',
            workspaceId: $workspace->id,
        ))->handle(app(PushDeviceService::class));
    }

    public function test_fcm_enabled_posts_payload_without_logging_token(): void
    {
        config()->set('services.fcm.enabled', true);
        config()->set('services.fcm.server_key', 'server-key-not-used-yet');

        [$user, $workspace] = $this->seedPushTarget();

        Http::fake([
            'https://fcm.googleapis.com/fcm/send' => Http::response([
                'success' => 1,
                'failure' => 0,
                'results' => [['message_id' => 'msg-1']],
            ], 200),
        ]);

        (new SendPushNotificationJob(
            userId: $user->id,
            title: 'Configured',
            body: 'Delivered',
            data: [
                'type' => 'message',
                'conversation_id' => '7',
                'message_id' => '9',
                'workspace_id' => (string) $workspace->id,
            ],
            workspaceId: $workspace->id,
        ))->handle(app(PushDeviceService::class));

        Http::assertSent(function ($request) use ($workspace): bool {
            $this->assertSame('https://fcm.googleapis.com/fcm/send', $request->url());
            $payload = $request->data();
            $this->assertSame('Configured', $payload['notification']['title']);
            $this->assertSame('Delivered', $payload['notification']['body']);
            $this->assertSame('7', $payload['data']['conversation_id']);
            $this->assertSame((string) $workspace->id, $payload['data']['workspace_id']);
            $this->assertSame('message', $payload['data']['type']);
            $this->assertSame(self::SECRET_FCM_TOKEN, $payload['to']);

            return true;
        });
        Http::assertSentCount(1);
    }

    public function test_invalid_token_is_revoked_without_retry_throw(): void
    {
        config()->set('services.fcm.enabled', true);
        config()->set('services.fcm.server_key', 'server-key-not-used-yet');

        [$user, $workspace] = $this->seedPushTarget();

        Http::fake([
            'https://fcm.googleapis.com/fcm/send' => Http::response([
                'success' => 0,
                'failure' => 1,
                'results' => [['error' => 'NotRegistered']],
            ], 200),
        ]);

        (new SendPushNotificationJob(
            userId: $user->id,
            title: 'Gone',
            body: 'Device dead',
            workspaceId: $workspace->id,
        ))->handle(app(PushDeviceService::class));

        $this->assertNotNull(
            DevicePushToken::query()
                ->where('workspace_id', $workspace->id)
                ->where('user_id', $user->id)
                ->value('revoked_at')
        );
    }

    public function test_disabled_notification_preference_skips_before_fcm_check(): void
    {
        config()->set('services.fcm.enabled', true);
        config()->set('services.fcm.server_key', 'server-key-not-used-yet');

        [$user, $workspace] = $this->seedPushTarget();

        NotificationPreference::query()->create([
            'user_id' => $user->id,
            'workspace_id' => $workspace->id,
            'messages' => false,
            'email' => true,
            'marketing' => false,
        ]);

        Log::shouldReceive('info')
            ->once()
            ->with('Push notification skipped due to user preferences', Mockery::on(function (array $context): bool {
                $this->assertSame('messages', $context['category']);

                return true;
            }));

        Log::shouldReceive('warning')->never();

        (new SendPushNotificationJob(
            userId: $user->id,
            title: 'Muted',
            body: 'Should not send',
            category: 'messages',
            workspaceId: $workspace->id,
        ))->handle(app(PushDeviceService::class));
    }

    public function test_missing_workspace_id_is_fail_closed_without_logging_delivery_attempt(): void
    {
        config()->set('services.fcm.enabled', true);

        [$user, $workspace] = $this->seedPushTarget();

        Log::shouldReceive('info')->never();
        Log::shouldReceive('warning')->never();

        (new SendPushNotificationJob(
            userId: $user->id,
            title: 'No workspace',
            body: 'Must not fan out',
        ))->handle(app(PushDeviceService::class));

        $this->assertNull(
            DevicePushToken::query()
                ->where('workspace_id', $workspace->id)
                ->where('user_id', $user->id)
                ->value('revoked_at')
        );
    }

    public function test_job_retry_policy_is_defined_for_future_delivery_client(): void
    {
        $job = new SendPushNotificationJob(1, 'title', 'body', [], 'messages', 1);

        $this->assertSame(3, $job->tries);
        $this->assertSame(60, $job->timeout);
        $this->assertSame([30, 120, 300], $job->backoff);
        $this->assertSame(120, $job->uniqueFor);
        $this->assertSame('push:1:1:messages:', $job->uniqueId());
    }

    public function test_mobile_device_registration_persists_workspace_scoped_token_without_exposing_it_in_api(): void
    {
        [$user, $workspace] = $this->seedPushTarget();

        $token = $user->createToken('mobile-push-register');
        $token->accessToken->forceFill(['workspace_id' => $workspace->id])->save();

        $response = $this->actingAs($user, 'sanctum')
            ->withToken($token->plainTextToken)
            ->withHeader('X-Workspace-Id', (string) $workspace->id)
            ->postJson('/api/mobile/v1/devices', [
                'token' => self::SECRET_FCM_TOKEN,
                'provider' => 'fcm',
                'platform' => 'android',
                'device_name' => 'Pixel Test',
            ]);

        $response->assertCreated()
            ->assertJsonPath('data.provider', 'fcm')
            ->assertJsonMissing(['token' => self::SECRET_FCM_TOKEN])
            ->assertJsonMissingPath('data.token');

        $this->assertDatabaseHas('device_push_tokens', [
            'user_id' => $user->id,
            'workspace_id' => $workspace->id,
            'token' => self::SECRET_FCM_TOKEN,
            'provider' => 'fcm',
            'revoked_at' => null,
        ]);
    }

    /**
     * @return array{0: User, 1: Workspace}
     */
    private function seedPushTarget(): array
    {
        $user = User::factory()->create();
        $workspace = Workspace::factory()->create([
            'owner_user_id' => $user->id,
            'type' => 'company',
        ]);
        $workspace->users()->attach($user->id, [
            'membership_role' => 'owner',
            'status' => 'active',
            'joined_at' => now(),
        ]);

        DevicePushToken::query()->create([
            'user_id' => $user->id,
            'workspace_id' => $workspace->id,
            'provider' => 'fcm',
            'token' => self::SECRET_FCM_TOKEN,
            'platform' => 'android',
            'device_name' => 'Test Device',
            'last_seen_at' => now(),
        ]);

        return [$user, $workspace];
    }
}
