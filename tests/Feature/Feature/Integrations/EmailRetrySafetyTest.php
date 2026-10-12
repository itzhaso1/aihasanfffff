<?php

namespace Tests\Feature\Feature\Integrations;

use App\Exceptions\CustomerMailboxException;
use App\Jobs\Email\SendCampaignRecipientJob;
use App\Models\EmailAccount;
use App\Models\EmailCampaign;
use App\Models\EmailCampaignRecipient;
use App\Models\EmailMessage;
use App\Models\User;
use App\Models\Workspace;
use App\Services\Email\CustomerMailboxErrorClassifier;
use App\Services\Email\EmailCampaignService;
use App\Services\Email\WorkspaceEmailSender;
use App\Support\Tenancy\WorkspaceContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Mockery;
use RuntimeException;
use Tests\TestCase;

class EmailRetrySafetyTest extends TestCase
{
    use RefreshDatabase;

    public function test_transient_campaign_failure_keeps_recipient_pending_for_retry(): void
    {
        [$workspace, $recipient] = $this->makePendingRecipient();

        $sender = Mockery::mock(WorkspaceEmailSender::class);
        $sender->shouldReceive('send')->once()->andThrow(new RuntimeException('SMTP timed out'));
        $this->app->instance(WorkspaceEmailSender::class, $sender);

        $job = new SendCampaignRecipientJob($recipient->id, $workspace->id);

        try {
            $job->handle(app(EmailCampaignService::class));
            $this->fail('Expected retryable exception.');
        } catch (RuntimeException $exception) {
            $this->assertTrue(app(CustomerMailboxErrorClassifier::class)->isRetryable($exception));
        }

        $recipient->refresh();
        $this->assertSame(EmailCampaignRecipient::STATUS_PENDING, $recipient->status);
        $this->assertNotNull($recipient->email_message_id);
        $this->assertSame(1, EmailMessage::withoutGlobalScopes()->where('workspace_id', $workspace->id)->count());
    }

    public function test_permanent_campaign_failure_marks_recipient_failed_without_retry(): void
    {
        [$workspace, $recipient] = $this->makePendingRecipient();

        $sender = Mockery::mock(WorkspaceEmailSender::class);
        $sender->shouldReceive('send')->once()->andThrow(new CustomerMailboxException(
            'Auth failed',
            CustomerMailboxErrorClassifier::AUTH,
            $workspace->id,
        ));
        $this->app->instance(WorkspaceEmailSender::class, $sender);

        $job = new SendCampaignRecipientJob($recipient->id, $workspace->id);
        $job->handle(app(EmailCampaignService::class));

        $recipient->refresh();
        $this->assertSame(EmailCampaignRecipient::STATUS_FAILED, $recipient->status);
    }

    public function test_retry_reuses_existing_message_when_send_already_succeeded(): void
    {
        [$workspace, $recipient, $accountId] = $this->makePendingRecipient();
        $message = EmailMessage::withoutGlobalScopes()->create([
            'workspace_id' => $workspace->id,
            'email_account_id' => $accountId,
            'sender' => 'sender@example.com',
            'recipient' => $recipient->email,
            'subject' => 'Subject',
            'body' => 'Body',
            'type' => 'outbound',
            'delivery_status' => 'sent',
            'delivered_at' => now(),
        ]);
        $recipient->forceFill(['email_message_id' => $message->id])->save();

        $sender = Mockery::mock(WorkspaceEmailSender::class);
        $sender->shouldNotReceive('send');
        $this->app->instance(WorkspaceEmailSender::class, $sender);

        app(WorkspaceContext::class)->runForId($workspace->id, function () use ($recipient): void {
            app(EmailCampaignService::class)->sendRecipient($recipient->fresh());
        });

        $recipient->refresh();
        $this->assertSame(EmailCampaignRecipient::STATUS_SENT, $recipient->status);
        $this->assertSame(1, EmailMessage::withoutGlobalScopes()->where('workspace_id', $workspace->id)->count());
    }

    /**
     * @return array{0: Workspace, 1: EmailCampaignRecipient, 2: int}
     */
    private function makePendingRecipient(): array
    {
        $workspace = Workspace::factory()->create();
        $user = User::factory()->create();
        $account = EmailAccount::withoutGlobalScopes()->create([
            'workspace_id' => $workspace->id,
            'name' => 'Campaign',
            'email' => 'campaign@example.com',
            'password' => 'secret',
            'imap_host' => 'imap.example.com',
            'imap_port' => 993,
            'smtp_host' => 'smtp.example.com',
            'smtp_port' => 587,
        ]);
        $campaign = EmailCampaign::withoutGlobalScopes()->create([
            'workspace_id' => $workspace->id,
            'email_account_id' => $account->id,
            'user_id' => $user->id,
            'subject' => 'Hello',
            'body' => 'Body',
            'status' => EmailCampaign::STATUS_SENDING,
            'sent_count' => 0,
            'failed_count' => 0,
            'recipient_count' => 1,
        ]);
        $recipient = EmailCampaignRecipient::withoutGlobalScopes()->create([
            'workspace_id' => $workspace->id,
            'email_campaign_id' => $campaign->id,
            'email' => 'buyer@example.com',
            'status' => EmailCampaignRecipient::STATUS_PENDING,
        ]);

        return [$workspace, $recipient, $account->id];
    }
}
