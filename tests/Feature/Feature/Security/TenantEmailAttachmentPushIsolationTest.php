<?php

namespace Tests\Feature\Feature\Security;

use App\Exceptions\MissingWorkspaceContextException;
use App\Jobs\Email\SendCampaignRecipientJob;
use App\Jobs\Push\SendPushNotificationJob;
use App\Jobs\SendEmailMessageJob;
use App\Models\Conversation;
use App\Models\Customer;
use App\Models\DevicePushToken;
use App\Models\EmailAccount;
use App\Models\EmailAttachment;
use App\Models\EmailCampaign;
use App\Models\EmailCampaignRecipient;
use App\Models\EmailLog;
use App\Models\EmailMessage;
use App\Models\Message;
use App\Models\MessageAttachment;
use App\Models\Plan;
use App\Models\Subscription;
use App\Models\User;
use App\Models\Workspace;
use App\Services\Email\EmailCampaignService;
use App\Services\Email\WorkspaceEmailSender;
use App\Services\Mobile\MessageAttachmentService;
use App\Services\Mobile\PushDeviceService;
use App\Support\Tenancy\WorkspaceContext;
use Database\Seeders\FoundationSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\URL;
use Tests\TestCase;

class TenantEmailAttachmentPushIsolationTest extends TestCase
{
    use RefreshDatabase;

    private User $ownerA;

    private User $ownerB;

    private User $sharedUser;

    private Workspace $workspaceA;

    private Workspace $workspaceB;

    private EmailLog $logA;

    private EmailLog $logB;

    private EmailAttachment $attachmentA;

    private EmailAttachment $attachmentB;

    private DevicePushToken $tokenA;

    private DevicePushToken $tokenB;

    private MessageAttachment $messageAttachmentA;

    private MessageAttachment $messageAttachmentB;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(FoundationSeeder::class);
        Storage::fake('local');
        Storage::fake('public');

        [$this->ownerA, $this->workspaceA] = $this->createWorkspaceOwner('P06 A');
        [$this->ownerB, $this->workspaceB] = $this->createWorkspaceOwner('P06 B');
        $this->sharedUser = User::factory()->create(['password' => 'password']);
        foreach ([$this->workspaceA, $this->workspaceB] as $workspace) {
            $workspace->users()->attach($this->sharedUser->id, [
                'membership_role' => 'manager',
                'status' => 'active',
                'joined_at' => now(),
            ]);
        }

        $this->logA = $this->makeEmailLog($this->workspaceA, 'a@example.com');
        $this->logB = $this->makeEmailLog($this->workspaceB, 'b@example.com');
        $this->attachmentA = $this->makeEmailAttachment($this->workspaceA, 'own.txt', 'alpha');
        $this->attachmentB = $this->makeEmailAttachment($this->workspaceB, 'secret.txt', 'bravo-secret');
        $this->tokenA = $this->makeDeviceToken($this->sharedUser, $this->workspaceA, 'fcm-shared-device');
        $this->tokenB = $this->makeDeviceToken($this->sharedUser, $this->workspaceB, 'fcm-shared-device');
        $this->messageAttachmentA = $this->makeMessageAttachment($this->workspaceA, 'chat-a.txt', 'chat-a');
        $this->messageAttachmentB = $this->makeMessageAttachment($this->workspaceB, 'chat-b.txt', 'chat-b-secret');
        app(WorkspaceContext::class)->clear();
    }

    public function test_email_log_owner_can_read_own_and_not_b(): void
    {
        $this->webAs($this->ownerA)
            ->getJson(route('workspace.emails.logs.show', $this->logA))
            ->assertOk()
            ->assertJsonPath('data.id', $this->logA->id)
            ->assertJsonPath('data.recipient', 'a@example.com');

        $this->webAs($this->ownerA)
            ->getJson(route('workspace.emails.logs.show', $this->logB))
            ->assertNotFound();

        app(WorkspaceContext::class)->runFor($this->workspaceA, function (): void {
            $this->assertNull(EmailLog::query()->find($this->logB->id));
        });

        $this->webAs($this->ownerA)
            ->deleteJson(route('workspace.emails.logs.destroy', $this->logB))
            ->assertNotFound();

        $this->assertDatabaseHas('email_logs', ['id' => $this->logB->id, 'recipient' => 'b@example.com']);

        app(WorkspaceContext::class)->runFor($this->workspaceA, function (): void {
            $this->assertSame(0, EmailLog::query()->whereKey($this->logB->id)->update(['status' => 'failed']));
        });
        $this->assertDatabaseHas('email_logs', ['id' => $this->logB->id, 'status' => 'sent']);
    }

    public function test_email_log_forged_route_id_is_denied(): void
    {
        $this->webAs($this->ownerA)
            ->getJson(route('workspace.emails.logs.show', $this->logB->id))
            ->assertNotFound();
    }

    public function test_owner_can_download_own_email_attachment_bytes(): void
    {
        $response = $this->webAs($this->ownerA)
            ->get(route('workspace.emails.attachments.download', $this->attachmentA));

        $response->assertOk();
        $this->assertStringContainsString('alpha', $response->streamedContent());
    }

    public function test_owner_cannot_download_preview_or_delete_foreign_email_attachment(): void
    {
        $this->webAs($this->ownerA)
            ->get(route('workspace.emails.attachments.download', $this->attachmentB))
            ->assertNotFound();

        $this->webAs($this->ownerA)
            ->get(route('workspace.emails.attachments.preview', $this->attachmentB))
            ->assertNotFound();

        $this->webAs($this->ownerA)
            ->deleteJson(route('workspace.emails.attachments.destroy', $this->attachmentB))
            ->assertNotFound();

        $this->assertTrue(Storage::disk('local')->exists($this->attachmentB->file_path));
        $this->assertDatabaseHas('email_attachments', ['id' => $this->attachmentB->id]);
    }

    public function test_signed_url_is_not_generated_for_unauthorized_message_attachment(): void
    {
        app(WorkspaceContext::class)->runFor($this->workspaceA, function (): void {
            $this->expectException(\RuntimeException::class);
            app(MessageAttachmentService::class)->downloadUrl($this->messageAttachmentB);
        });
    }

    public function test_signed_download_rejects_forged_attachment_id(): void
    {
        $forged = URL::temporarySignedRoute(
            'mobile.v1.attachments.download',
            now()->addMinutes(5),
            [
                'attachment' => $this->messageAttachmentB->id,
                'workspace' => $this->workspaceA->id,
            ],
        );

        $this->get($forged)->assertUnauthorized();
    }

    public function test_device_token_register_is_workspace_specific(): void
    {
        $token = $this->mobileToken($this->sharedUser, $this->workspaceA);

        $this->withToken($token)
            ->withHeader('X-Workspace-Id', (string) $this->workspaceA->id)
            ->postJson('/api/mobile/v1/devices', [
                'token' => 'fcm-new-a',
                'provider' => 'fcm',
                'platform' => 'android',
            ])
            ->assertCreated()
            ->assertJsonPath('data.workspace_id', $this->workspaceA->id);

        $this->assertSame(1, DevicePushToken::query()->where('token', 'fcm-new-a')->where('workspace_id', $this->workspaceA->id)->count());
        $this->assertSame(0, DevicePushToken::query()->where('token', 'fcm-new-a')->where('workspace_id', $this->workspaceB->id)->count());
    }

    public function test_device_token_cannot_be_modified_or_deleted_cross_workspace(): void
    {
        $token = $this->mobileToken($this->sharedUser, $this->workspaceA);

        $this->withToken($token)
            ->withHeader('X-Workspace-Id', (string) $this->workspaceA->id)
            ->deleteJson('/api/mobile/v1/devices/'.$this->tokenB->id)
            ->assertNotFound();

        $this->assertNull($this->tokenB->fresh()->revoked_at);

        $otherUser = User::factory()->create(['password' => 'password']);
        $this->workspaceA->users()->attach($otherUser->id, [
            'membership_role' => 'member',
            'status' => 'active',
            'joined_at' => now(),
        ]);
        $foreign = $this->makeDeviceToken($otherUser, $this->workspaceA, 'fcm-foreign');
        $this->withToken($token)
            ->withHeader('X-Workspace-Id', (string) $this->workspaceA->id)
            ->deleteJson('/api/mobile/v1/devices/'.$foreign->id)
            ->assertNotFound();
        $this->assertNull($foreign->fresh()->revoked_at);
    }

    public function test_push_job_does_not_target_other_workspace_token(): void
    {
        config()->set('services.fcm.enabled', true);
        config()->set('services.fcm.server_key', 'test-key');

        (new SendPushNotificationJob(
            userId: $this->sharedUser->id,
            title: 'A only',
            body: 'A body',
            workspaceId: $this->workspaceA->id,
        ))->handle(app(PushDeviceService::class));

        $tokens = app(PushDeviceService::class)
            ->activeTokensForUser($this->sharedUser, $this->workspaceA->id)
            ->pluck('id')
            ->all();

        $this->assertContains($this->tokenA->id, $tokens);
        $this->assertNotContains($this->tokenB->id, $tokens);

        (new SendPushNotificationJob(
            userId: $this->ownerA->id,
            title: 'Forged',
            body: 'Forged',
            workspaceId: $this->workspaceB->id,
        ))->handle(app(PushDeviceService::class));

        $this->assertSame(0, app(PushDeviceService::class)
            ->activeTokensForUser($this->ownerA, $this->workspaceB->id)
            ->count());
    }

    public function test_multi_workspace_user_sees_only_current_workspace_resources(): void
    {
        $this->webAs($this->sharedUser, $this->workspaceA)
            ->getJson(route('workspace.emails.logs.show', $this->logA))
            ->assertOk();
        $this->webAs($this->sharedUser, $this->workspaceA)
            ->getJson(route('workspace.emails.logs.show', $this->logB))
            ->assertNotFound();
        $this->webAs($this->ownerB, $this->workspaceB)
            ->getJson(route('workspace.emails.logs.show', $this->logB))
            ->assertOk();
        $this->webAs($this->sharedUser, $this->workspaceB)
            ->getJson(route('workspace.emails.logs.show', $this->logB))
            ->assertOk();

        app(WorkspaceContext::class)->set($this->workspaceA);
        $this->actingAs($this->sharedUser)
            ->withSession(['current_workspace_id' => $this->workspaceB->id])
            ->getJson(route('workspace.emails.logs.show', $this->logB))
            ->assertOk()
            ->assertJsonPath('data.id', $this->logB->id);

        $bytes = $this->webAs($this->sharedUser, $this->workspaceA)
            ->get(route('workspace.emails.attachments.download', $this->attachmentA));
        $bytes->assertOk();
        $this->assertStringContainsString('alpha', $bytes->streamedContent());
        $this->webAs($this->sharedUser, $this->workspaceA)
            ->get(route('workspace.emails.attachments.download', $this->attachmentB))
            ->assertNotFound();

        $mobileToken = $this->mobileToken($this->sharedUser, $this->workspaceA);
        $this->withToken($mobileToken)
            ->withHeader('X-Workspace-Id', (string) $this->workspaceA->id)
            ->get('/api/mobile/v1/emails/attachments/'.$this->attachmentB->id.'/download')
            ->assertNotFound();
        $mobileOwn = $this->withToken($mobileToken)
            ->withHeader('X-Workspace-Id', (string) $this->workspaceA->id)
            ->get('/api/mobile/v1/emails/attachments/'.$this->attachmentA->id.'/download');
        $mobileOwn->assertOk();
        $this->assertStringContainsString('alpha', $mobileOwn->streamedContent());
    }

    public function test_logout_in_workspace_a_does_not_revoke_workspace_b_token(): void
    {
        $login = $this->postJson('/api/mobile/v1/auth/login', [
            'email' => $this->sharedUser->email,
            'password' => 'password',
            'workspace_id' => $this->workspaceA->id,
            'device_name' => 'Phone A',
        ])->assertOk();
        $plain = $login->json('data.token');

        $this->withToken($plain)
            ->withHeader('X-Workspace-Id', (string) $this->workspaceA->id)
            ->postJson('/api/mobile/v1/devices', [
                'token' => 'fcm-session-a',
                'provider' => 'fcm',
                'platform' => 'ios',
            ])
            ->assertCreated();

        $this->assertNotNull(DevicePushToken::query()->where('token', 'fcm-shared-device')->where('workspace_id', $this->workspaceB->id)->whereNull('revoked_at')->first());

        $this->withToken($plain)->postJson('/api/mobile/v1/auth/logout')->assertOk();

        $this->assertNull(DevicePushToken::query()->where('token', 'fcm-shared-device')->where('workspace_id', $this->workspaceB->id)->first()?->revoked_at);
        $this->assertNotNull($this->tokenB->fresh());
        $this->assertNull($this->tokenB->fresh()->revoked_at);
    }

    public function test_forged_email_log_and_recipient_jobs_are_noops(): void
    {
        $service = \Mockery::mock(EmailCampaignService::class);
        $service->shouldNotReceive('sendRecipient');
        $service->shouldNotReceive('refreshCampaignStatus');

        $accountB = EmailAccount::withoutGlobalScopes()->where('workspace_id', $this->workspaceB->id)->firstOrFail();
        $campaignB = EmailCampaign::withoutGlobalScopes()->create([
            'workspace_id' => $this->workspaceB->id,
            'user_id' => $this->ownerB->id,
            'email_account_id' => $accountB->id,
            'subject' => 'B campaign',
            'body' => 'x',
            'status' => EmailCampaign::STATUS_QUEUED,
            'recipient_count' => 1,
        ]);
        $recipientB = EmailCampaignRecipient::withoutGlobalScopes()->create([
            'workspace_id' => $this->workspaceB->id,
            'email_campaign_id' => $campaignB->id,
            'email' => 'forged@b.test',
            'status' => EmailCampaignRecipient::STATUS_PENDING,
        ]);

        (new SendCampaignRecipientJob($recipientB->id, $this->workspaceA->id))->handle($service);
        $this->assertSame(EmailCampaignRecipient::STATUS_PENDING, $recipientB->fresh()->status);

        app(WorkspaceContext::class)->runFor($this->workspaceA, function (): void {
            $this->assertNull(EmailLog::query()->find($this->logB->id));
        });

        $sender = \Mockery::mock(WorkspaceEmailSender::class);
        $sender->shouldNotReceive('send');
        (new SendEmailMessageJob($this->attachmentB->message_id, $this->workspaceA->id))->handle($sender);
        $this->assertTrue(Storage::disk('local')->exists($this->attachmentB->file_path));
        $this->assertSame('bravo-secret', Storage::disk('local')->get($this->attachmentB->file_path));

        (new SendPushNotificationJob(
            userId: $this->sharedUser->id,
            title: 'No workspace',
            body: 'must not fan out',
        ))->handle(app(PushDeviceService::class));

        $this->assertNull($this->tokenB->fresh()->revoked_at);
        $this->assertSame(0, app(PushDeviceService::class)
            ->activeTokensForUser($this->sharedUser, $this->workspaceB->id)
            ->where('id', $this->tokenA->id)
            ->count());
    }

    public function test_no_context_queries_on_new_scoped_models_fail_closed(): void
    {
        app(WorkspaceContext::class)->clear();
        app(WorkspaceContext::class)->disableTestFixtureMutations();

        try {
            EmailLog::query()->get();
            $this->fail('EmailLog query should fail closed');
        } catch (MissingWorkspaceContextException) {
            $this->assertTrue(true);
        }

        try {
            EmailAttachment::query()->get();
            $this->fail('EmailAttachment query should fail closed');
        } catch (MissingWorkspaceContextException) {
            $this->assertTrue(true);
        }

        try {
            EmailCampaignRecipient::query()->create([
                'email' => 'x@y.z',
                'status' => 'pending',
            ]);
            $this->fail('EmailCampaignRecipient create should fail closed');
        } catch (MissingWorkspaceContextException) {
            $this->assertTrue(true);
        }

        $this->expectException(MissingWorkspaceContextException::class);
        $this->logA->update(['status' => 'failed']);
    }

    public function test_email_attachment_path_traversal_is_rejected(): void
    {
        $bad = EmailAttachment::withoutGlobalScopes()->create([
            'workspace_id' => $this->workspaceA->id,
            'message_id' => $this->attachmentA->message_id,
            'file_path' => '../secrets/passwd',
            'disk' => 'local',
            'file_type' => 'text/plain',
            'file_size' => 1,
        ]);

        $this->webAs($this->ownerA)
            ->get(route('workspace.emails.attachments.download', $bad))
            ->assertNotFound();
    }

    /**
     * @return array{0: User, 1: Workspace}
     */
    private function createWorkspaceOwner(string $name): array
    {
        $user = User::factory()->create(['password' => 'password']);
        $workspace = Workspace::factory()->create([
            'owner_user_id' => $user->id,
            'type' => 'company',
            'name' => $name,
        ]);
        $workspace->users()->attach($user->id, [
            'membership_role' => 'owner',
            'status' => 'active',
            'joined_at' => now(),
        ]);
        $this->enableEmailFeature($workspace);

        return [$user, $workspace];
    }

    private function enableEmailFeature(Workspace $workspace): void
    {
        $this->enableWorkspaceFeature($workspace, 'email');
        $plan = Plan::query()->create([
            'code' => 'p06_'.uniqid(),
            'name' => 'P06 Email',
            'tier' => 'pro',
            'workspace_type' => 'company',
            'billing_period' => 'monthly',
            'currency' => 'SAR',
            'price' => 0,
            'is_active' => true,
            'features' => ['email', 'dashboard', 'subscription'],
            'limits' => ['email_sends' => 1000],
        ]);
        Subscription::withoutGlobalScopes()->create([
            'workspace_id' => $workspace->id,
            'plan_id' => $plan->id,
            'status' => 'active',
            'starts_at' => now(),
            'current_period_start' => now(),
            'current_period_end' => now()->addMonth(),
        ]);
    }

    private function makeEmailLog(Workspace $workspace, string $recipient): EmailLog
    {
        return EmailLog::withoutGlobalScopes()->create([
            'workspace_id' => $workspace->id,
            'provider' => 'resend',
            'template' => 'general_notification',
            'recipient' => $recipient,
            'subject' => 'P06 '.$recipient,
            'status' => 'sent',
        ]);
    }

    private function makeEmailAttachment(Workspace $workspace, string $name, string $bytes): EmailAttachment
    {
        $account = EmailAccount::withoutGlobalScopes()->create([
            'workspace_id' => $workspace->id,
            'name' => $workspace->name.' mail',
            'email' => $name.'@mail.test',
            'password' => 'secret',
            'imap_host' => 'imap.test',
            'imap_port' => 993,
            'smtp_host' => 'smtp.test',
            'smtp_port' => 587,
        ]);
        $message = EmailMessage::withoutGlobalScopes()->create([
            'workspace_id' => $workspace->id,
            'email_account_id' => $account->id,
            'sender' => $account->email,
            'recipient' => 'to@example.com',
            'subject' => $name,
            'body' => 'body',
            'type' => 'inbound',
        ]);
        $path = 'workspaces/'.$workspace->id.'/emails/attachments/'.$name;
        Storage::disk('local')->put($path, $bytes);

        return EmailAttachment::withoutGlobalScopes()->create([
            'workspace_id' => $workspace->id,
            'message_id' => $message->id,
            'file_path' => $path,
            'disk' => 'local',
            'file_type' => 'text/plain',
            'file_size' => strlen($bytes),
        ]);
    }

    private function makeDeviceToken(User $user, Workspace $workspace, string $token): DevicePushToken
    {
        return DevicePushToken::query()->create([
            'user_id' => $user->id,
            'workspace_id' => $workspace->id,
            'provider' => 'fcm',
            'token' => $token,
            'platform' => 'android',
            'last_seen_at' => now(),
        ]);
    }

    private function makeMessageAttachment(Workspace $workspace, string $name, string $bytes): MessageAttachment
    {
        $customer = Customer::withoutGlobalScopes()->create([
            'workspace_id' => $workspace->id,
            'name' => $name,
            'phone' => '05'.random_int(10000000, 99999999),
        ]);
        $conversation = Conversation::withoutGlobalScopes()->create([
            'workspace_id' => $workspace->id,
            'customer_id' => $customer->id,
            'channel' => 'whatsapp',
            'status' => 'open',
        ]);
        $message = Message::withoutGlobalScopes()->create([
            'workspace_id' => $workspace->id,
            'conversation_id' => $conversation->id,
            'customer_id' => $customer->id,
            'direction' => 'inbound',
            'message_type' => 'text',
            'content' => 'hi',
        ]);
        $path = 'mobile-attachments/'.$workspace->id.'/'.$message->id.'/'.$name;
        Storage::disk('local')->put($path, $bytes);

        return MessageAttachment::withoutGlobalScopes()->create([
            'workspace_id' => $workspace->id,
            'message_id' => $message->id,
            'disk' => 'local',
            'path' => $path,
            'original_name' => $name,
            'mime_type' => 'text/plain',
            'size_bytes' => strlen($bytes),
            'kind' => 'file',
        ]);
    }

    private function webAs(User $user, ?Workspace $workspace = null)
    {
        $workspace ??= $this->workspaceA;
        app(WorkspaceContext::class)->clear();

        return $this->actingAs($user)->withSession(['current_workspace_id' => $workspace->id]);
    }

    private function mobileToken(User $user, Workspace $workspace): string
    {
        $issued = $user->createToken('p06-mobile', ['mobile', '*']);
        $issued->accessToken->forceFill(['workspace_id' => $workspace->id])->save();

        return $issued->plainTextToken;
    }
}
