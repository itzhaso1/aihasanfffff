<?php

namespace Tests\Feature\Feature\Tenancy;

use App\Exceptions\MissingWorkspaceContextException;
use App\Jobs\Email\ProcessEmailCampaignJob;
use App\Jobs\Email\SendCampaignRecipientJob;
use App\Jobs\ProcessIncomingWhatsAppMessage;
use App\Jobs\Push\SendPushNotificationJob;
use App\Jobs\SendEmailMessageJob;
use App\Jobs\SendWhatsAppMessage;
use App\Jobs\SyncEmailInboxJob;
use App\Models\EmailAccount;
use App\Models\EmailCampaign;
use App\Models\EmailCampaignRecipient;
use App\Models\EmailLog;
use App\Models\EmailMessage;
use App\Models\Message;
use App\Models\User;
use App\Models\WebhookEvent;
use App\Models\WhatsAppOutboundMessage;
use App\Models\Workspace;
use App\Services\Email\EmailCampaignService;
use App\Services\Email\ImapSyncService;
use App\Services\Email\WorkspaceEmailSender;
use App\Services\Mobile\PushDeviceService;
use App\Services\WhatsApp\WhatsAppService;
use App\Support\Tenancy\WorkspaceContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Http;
use Mockery;
use Tests\TestCase;

class JobIsolationExecutionTest extends TestCase
{
    use RefreshDatabase;

    public function test_send_whatsapp_message_updates_only_own_outbound_record(): void
    {
        config()->set('services.whatsapp.token', 'test-token');
        [$workspaceA, $workspaceB] = $this->twoWorkspaces();
        $outboundA = $this->outbound($workspaceA, '966500000001');
        $outboundB = $this->outbound($workspaceB, '966500000002');

        Http::fake(['graph.facebook.com/*' => Http::response(['messages' => [['id' => 'wamid.A']]], 200)]);

        (new SendWhatsAppMessage('pnid-a', '966500000001', 'hi-a', $outboundA->id, $workspaceA->id))->handle();

        $this->assertSame(WhatsAppOutboundMessage::STATUS_SENT, $outboundA->fresh()->status);
        $this->assertSame(WhatsAppOutboundMessage::STATUS_QUEUED, $outboundB->fresh()->status);
    }

    public function test_send_whatsapp_message_missing_parent_writes_nothing(): void
    {
        [$workspaceA, $workspaceB] = $this->twoWorkspaces();
        $outboundB = $this->outbound($workspaceB, '966500000002');

        Http::fake();
        (new SendWhatsAppMessage('pnid-missing', '966500000099', 'ghost', 999_999, $workspaceA->id))->handle();

        $this->assertSame(0, WhatsAppOutboundMessage::withoutGlobalScopes()->where('workspace_id', $workspaceA->id)->count());
        $this->assertSame(WhatsAppOutboundMessage::STATUS_QUEUED, $outboundB->fresh()->status);
        Http::assertNothingSent();
    }

    public function test_send_whatsapp_message_forged_id_does_not_mutate_other_workspace(): void
    {
        config()->set('services.whatsapp.token', 'test-token');
        [$workspaceA, $workspaceB] = $this->twoWorkspaces();
        $outboundA = $this->outbound($workspaceA, '966500000001');
        $outboundB = $this->outbound($workspaceB, '966500000002');

        Http::fake(['graph.facebook.com/*' => Http::response(['messages' => [['id' => 'wamid.B']]], 200)]);
        (new SendWhatsAppMessage('pnid-b', '966500000002', 'hi-b', $outboundB->id, $workspaceA->id))->handle();

        $this->assertSame(WhatsAppOutboundMessage::STATUS_QUEUED, $outboundA->fresh()->status);
        $this->assertSame(WhatsAppOutboundMessage::STATUS_QUEUED, $outboundB->fresh()->status);
        Http::assertNothingSent();
    }

    public function test_send_email_message_job_sends_only_target_message(): void
    {
        [$workspaceA, $workspaceB] = $this->twoWorkspaces();
        $messageA = $this->outboundEmail($workspaceA, 'a@example.com');
        $messageB = $this->outboundEmail($workspaceB, 'b@example.com');

        $sender = Mockery::mock(WorkspaceEmailSender::class);
        $sender->shouldReceive('send')->once()->andReturn(new EmailLog(['provider_message_id' => 'mid-a']));

        (new SendEmailMessageJob($messageA->id, $workspaceA->id))->handle($sender);

        $this->assertSame('sent', $messageA->fresh()->delivery_status);
        $this->assertSame('pending', $messageB->fresh()->delivery_status);
    }

    public function test_send_email_message_job_missing_parent_is_a_noop(): void
    {
        [$workspaceA, $workspaceB] = $this->twoWorkspaces();
        $messageB = $this->outboundEmail($workspaceB, 'b@example.com');
        $sender = Mockery::mock(WorkspaceEmailSender::class);
        $sender->shouldNotReceive('send');

        (new SendEmailMessageJob(999_999, $workspaceA->id))->handle($sender);

        $this->assertSame(0, EmailMessage::withoutGlobalScopes()->where('workspace_id', $workspaceA->id)->count());
        $this->assertSame('pending', $messageB->fresh()->delivery_status);
    }

    public function test_send_email_message_job_forged_id_does_not_send_other_workspace(): void
    {
        [$workspaceA, $workspaceB] = $this->twoWorkspaces();
        $messageA = $this->outboundEmail($workspaceA, 'a@example.com');
        $messageB = $this->outboundEmail($workspaceB, 'b@example.com');
        $sender = Mockery::mock(WorkspaceEmailSender::class);
        $sender->shouldNotReceive('send');

        (new SendEmailMessageJob($messageB->id, $workspaceA->id))->handle($sender);

        $this->assertSame('pending', $messageA->fresh()->delivery_status);
        $this->assertSame('pending', $messageB->fresh()->delivery_status);
    }

    public function test_process_email_campaign_job_queues_only_its_workspace(): void
    {
        [$workspaceA, $workspaceB] = $this->twoWorkspaces();
        $userA = User::factory()->create();
        $campaignA = $this->queuedCampaign($workspaceA, $userA);
        $campaignB = $this->queuedCampaign($workspaceB, $userA);
        EmailCampaignRecipient::query()->create([
            'workspace_id' => $workspaceA->id,
            'email_campaign_id' => $campaignA->id,
            'email' => 'a@example.com',
            'status' => EmailCampaignRecipient::STATUS_PENDING,
        ]);

        Bus::fake([SendCampaignRecipientJob::class]);
        (new ProcessEmailCampaignJob($campaignA->id, $workspaceA->id))->handle(app(EmailCampaignService::class));

        $this->assertSame(EmailCampaign::STATUS_SENDING, $campaignA->fresh()->status);
        $this->assertSame(EmailCampaign::STATUS_QUEUED, $campaignB->fresh()->status);
        Bus::assertDispatched(SendCampaignRecipientJob::class);
    }

    public function test_process_email_campaign_job_missing_campaign_writes_nothing(): void
    {
        [$workspaceA, $workspaceB] = $this->twoWorkspaces();
        $userA = User::factory()->create();
        $campaignB = $this->queuedCampaign($workspaceB, $userA);

        Bus::fake([SendCampaignRecipientJob::class]);
        (new ProcessEmailCampaignJob(999_999, $workspaceA->id))->handle(app(EmailCampaignService::class));

        $this->assertSame(0, EmailCampaign::withoutGlobalScopes()->where('workspace_id', $workspaceA->id)->count());
        $this->assertSame(EmailCampaign::STATUS_QUEUED, $campaignB->fresh()->status);
        Bus::assertNothingDispatched(SendCampaignRecipientJob::class);
    }

    public function test_process_email_campaign_job_forged_id_does_not_touch_other_workspace(): void
    {
        [$workspaceA, $workspaceB] = $this->twoWorkspaces();
        $userA = User::factory()->create();
        $campaignA = $this->queuedCampaign($workspaceA, $userA);
        $campaignB = $this->queuedCampaign($workspaceB, $userA);

        Bus::fake([SendCampaignRecipientJob::class]);
        (new ProcessEmailCampaignJob($campaignB->id, $workspaceA->id))->handle(app(EmailCampaignService::class));

        $this->assertSame(EmailCampaign::STATUS_QUEUED, $campaignA->fresh()->status);
        $this->assertSame(EmailCampaign::STATUS_QUEUED, $campaignB->fresh()->status);
        Bus::assertNothingDispatched(SendCampaignRecipientJob::class);
    }

    public function test_send_campaign_recipient_job_missing_recipient_is_a_noop(): void
    {
        $service = Mockery::mock(EmailCampaignService::class);
        $service->shouldNotReceive('sendRecipient');
        $service->shouldNotReceive('refreshCampaignStatus');

        (new SendCampaignRecipientJob(999_999, 1))->handle($service);
        $this->assertSame(0, EmailMessage::withoutGlobalScopes()->count());
    }

    public function test_send_campaign_recipient_job_does_not_send_other_workspace_recipient(): void
    {
        [$workspaceA, $workspaceB] = $this->twoWorkspaces();
        $user = User::factory()->create();
        $campaignA = $this->queuedCampaign($workspaceA, $user);
        $campaignB = $this->queuedCampaign($workspaceB, $user);
        $recipientA = EmailCampaignRecipient::query()->create([
            'workspace_id' => $workspaceA->id,
            'email_campaign_id' => $campaignA->id,
            'email' => 'a@example.com',
            'status' => EmailCampaignRecipient::STATUS_PENDING,
        ]);
        $recipientB = EmailCampaignRecipient::query()->create([
            'workspace_id' => $workspaceB->id,
            'email_campaign_id' => $campaignB->id,
            'email' => 'b@example.com',
            'status' => EmailCampaignRecipient::STATUS_PENDING,
        ]);

        $service = Mockery::mock(EmailCampaignService::class);
        $service->shouldReceive('sendRecipient')->once()->withArgs(fn ($recipient) => (int) $recipient->id === (int) $recipientA->id);
        $service->shouldReceive('refreshCampaignStatus')->once();

        (new SendCampaignRecipientJob($recipientA->id, $workspaceA->id))->handle($service);

        $this->assertSame(EmailCampaignRecipient::STATUS_PENDING, $recipientB->fresh()->status);
    }

    public function test_send_campaign_recipient_job_forged_id_does_not_send_other_workspace(): void
    {
        [$workspaceA, $workspaceB] = $this->twoWorkspaces();
        $user = User::factory()->create();
        $this->queuedCampaign($workspaceA, $user);
        $campaignB = $this->queuedCampaign($workspaceB, $user);
        $recipientB = EmailCampaignRecipient::query()->create([
            'workspace_id' => $workspaceB->id,
            'email_campaign_id' => $campaignB->id,
            'email' => 'b@example.com',
            'status' => EmailCampaignRecipient::STATUS_PENDING,
        ]);

        $service = Mockery::mock(EmailCampaignService::class);
        $service->shouldNotReceive('sendRecipient');
        $service->shouldNotReceive('refreshCampaignStatus');

        (new SendCampaignRecipientJob($recipientB->id, $workspaceA->id))->handle($service);

        $this->assertSame(EmailCampaignRecipient::STATUS_PENDING, $recipientB->fresh()->status);
    }

    public function test_sync_email_inbox_job_syncs_only_the_bound_account(): void
    {
        [$workspaceA, $workspaceB] = $this->twoWorkspaces();
        $accountA = $this->emailAccount($workspaceA, 'a@example.com');
        $accountB = $this->emailAccount($workspaceB, 'b@example.com');

        $imap = Mockery::mock(ImapSyncService::class);
        $imap->shouldReceive('syncAccount')->once()->withArgs(fn ($account) => (int) $account->id === (int) $accountA->id)->andReturn(0);

        (new SyncEmailInboxJob($accountA->id, $workspaceA->id))->handle($imap);

        $this->assertSame(0, EmailMessage::withoutGlobalScopes()->where('workspace_id', $workspaceB->id)->count());
    }

    public function test_sync_email_inbox_job_missing_account_does_not_touch_other_tenants(): void
    {
        [$workspaceA, $workspaceB] = $this->twoWorkspaces();
        $this->emailAccount($workspaceB, 'b@example.com');
        $imap = Mockery::mock(ImapSyncService::class);
        $imap->shouldNotReceive('syncAccount');

        (new SyncEmailInboxJob(999_999, $workspaceA->id))->handle($imap);

        $this->assertSame(0, EmailMessage::withoutGlobalScopes()->where('workspace_id', $workspaceA->id)->count());
    }

    public function test_sync_email_inbox_job_forged_id_does_not_sync_other_workspace(): void
    {
        [$workspaceA, $workspaceB] = $this->twoWorkspaces();
        $this->emailAccount($workspaceA, 'a@example.com');
        $accountB = $this->emailAccount($workspaceB, 'b@example.com');
        $imap = Mockery::mock(ImapSyncService::class);
        $imap->shouldNotReceive('syncAccount');

        (new SyncEmailInboxJob($accountB->id, $workspaceA->id))->handle($imap);
    }

    public function test_send_push_notification_job_missing_user_is_a_noop(): void
    {
        [$workspaceA] = $this->twoWorkspaces();

        (new SendPushNotificationJob(999_999, 't', 'b', [], 'messages', $workspaceA->id))
            ->handle(app(PushDeviceService::class));

        $this->assertSame(0, EmailMessage::withoutGlobalScopes()->count());
        $this->assertSame(0, WebhookEvent::withoutGlobalScopes()->count());
    }

    public function test_send_push_notification_job_does_not_query_other_tenant_models(): void
    {
        [$workspaceA, $workspaceB] = $this->twoWorkspaces();
        $user = User::factory()->create();

        (new SendPushNotificationJob($user->id, 't', 'b', [], 'messages', $workspaceA->id))
            ->handle(app(PushDeviceService::class));

        $this->assertNull(app(WorkspaceContext::class)->workspaceId());
        $this->assertSame(0, WebhookEvent::withoutGlobalScopes()->where('workspace_id', $workspaceB->id)->count());
    }

    public function test_process_incoming_whatsapp_message_writes_only_job_workspace(): void
    {
        [$workspaceA, $workspaceB] = $this->twoWorkspaces();
        $eventA = $this->webhookEvent($workspaceA, 'wa-a');
        $eventB = $this->webhookEvent($workspaceB, 'wa-b');

        Bus::fake();
        (new ProcessIncomingWhatsAppMessage(
            $workspaceA->id,
            ['from' => '966511111111', 'id' => 'wamid.1', 'text' => ['body' => 'hello']],
            $eventA->id,
            'pnid-a',
        ))->handle(app(WhatsAppService::class));

        $this->assertSame('processed', $eventA->fresh()->status);
        $this->assertSame('pending', $eventB->fresh()->status);
        $this->assertSame(1, Message::withoutGlobalScopes()->where('workspace_id', $workspaceA->id)->count());
        $this->assertSame(0, Message::withoutGlobalScopes()->where('workspace_id', $workspaceB->id)->count());
    }

    public function test_process_incoming_whatsapp_message_missing_workspace_does_not_write(): void
    {
        [$workspaceA, $workspaceB] = $this->twoWorkspaces();
        $eventB = $this->webhookEvent($workspaceB, 'wa-b');

        try {
            (new ProcessIncomingWhatsAppMessage(
                999_999,
                ['from' => '966511111111', 'id' => 'wamid.missing', 'text' => ['body' => 'hello']],
                $eventB->id,
            ))->handle(app(WhatsAppService::class));
            $this->fail('Expected missing workspace to fail closed.');
        } catch (MissingWorkspaceContextException) {
            // controlled failure
        }

        $this->assertSame('pending', $eventB->fresh()->status);
        $this->assertSame(0, Message::withoutGlobalScopes()->where('workspace_id', $workspaceA->id)->count());
        $this->assertSame(0, Message::withoutGlobalScopes()->where('workspace_id', $workspaceB->id)->count());
    }

    public function test_process_incoming_whatsapp_message_forged_event_id_does_not_mark_other_workspace(): void
    {
        [$workspaceA, $workspaceB] = $this->twoWorkspaces();
        $eventB = $this->webhookEvent($workspaceB, 'wa-forged');

        Bus::fake();
        (new ProcessIncomingWhatsAppMessage(
            $workspaceA->id,
            ['from' => '966522222222', 'id' => 'wamid.forged', 'text' => ['body' => 'x']],
            $eventB->id,
            'pnid-a',
        ))->handle(app(WhatsAppService::class));

        $this->assertSame('pending', $eventB->fresh()->status);
        $this->assertSame(0, Message::withoutGlobalScopes()->where('workspace_id', $workspaceB->id)->count());
    }

    /**
     * @return array{0: Workspace, 1: Workspace}
     */
    private function twoWorkspaces(): array
    {
        $userA = User::factory()->create();
        $userB = User::factory()->create();

        return [
            Workspace::factory()->create(['owner_user_id' => $userA->id, 'type' => 'company']),
            Workspace::factory()->create(['owner_user_id' => $userB->id, 'type' => 'company']),
        ];
    }

    private function outbound(Workspace $workspace, string $to): WhatsAppOutboundMessage
    {
        return WhatsAppOutboundMessage::withoutGlobalScopes()->create([
            'workspace_id' => $workspace->id,
            'to' => $to,
            'type' => 'text',
            'body' => 'queued',
            'status' => WhatsAppOutboundMessage::STATUS_QUEUED,
            'attempts' => 0,
        ]);
    }

    private function emailAccount(Workspace $workspace, string $email): EmailAccount
    {
        return EmailAccount::withoutGlobalScopes()->create([
            'workspace_id' => $workspace->id,
            'name' => 'Inbox '.$email,
            'email' => $email,
            'password' => 'secret',
            'imap_host' => 'imap.example.test',
            'imap_port' => 993,
            'smtp_host' => 'smtp.example.test',
            'smtp_port' => 587,
        ]);
    }

    private function outboundEmail(Workspace $workspace, string $recipient): EmailMessage
    {
        $account = $this->emailAccount($workspace, 'from-'.$recipient);

        return EmailMessage::withoutGlobalScopes()->create([
            'workspace_id' => $workspace->id,
            'email_account_id' => $account->id,
            'sender' => $account->email,
            'recipient' => $recipient,
            'subject' => 'Subject',
            'body' => 'Body',
            'type' => 'outbound',
            'delivery_status' => 'pending',
        ]);
    }

    private function queuedCampaign(Workspace $workspace, User $user): EmailCampaign
    {
        $account = $this->emailAccount($workspace, 'campaign-'.$workspace->id.'@example.com');

        return EmailCampaign::withoutGlobalScopes()->create([
            'workspace_id' => $workspace->id,
            'user_id' => $user->id,
            'email_account_id' => $account->id,
            'subject' => 'Campaign',
            'body' => 'Hello',
            'status' => EmailCampaign::STATUS_QUEUED,
            'recipient_count' => 1,
        ]);
    }

    private function webhookEvent(Workspace $workspace, string $externalId): WebhookEvent
    {
        return WebhookEvent::withoutGlobalScopes()->create([
            'workspace_id' => $workspace->id,
            'provider' => 'whatsapp',
            'event_type' => 'message',
            'external_event_id' => $externalId,
            'idempotency_key' => $externalId,
            'headers' => [],
            'payload' => [],
            'status' => 'pending',
        ]);
    }
}
