<?php

namespace Tests\Feature\Feature\Email;

use App\Exceptions\CustomerMailboxException;
use App\Jobs\SendEmailMessageJob;
use App\Jobs\SyncEmailInboxJob;
use App\Mail\WorkspaceBrandedEmail;
use App\Models\EmailAccount;
use App\Models\EmailLog;
use App\Models\EmailMessage;
use App\Models\Plan;
use App\Models\Subscription;
use App\Models\User;
use App\Models\Workspace;
use App\Services\Email\CentralEmailService;
use App\Services\Email\CustomerMailboxErrorClassifier;
use App\Services\Email\CustomerSmtpTransportFactory;
use App\Services\Email\ImapSyncService;
use App\Services\Email\WorkspaceEmailSender;
use App\Services\Mobile\MobileEmailService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Symfony\Component\Mailer\Exception\TransportException;
use Tests\Support\Email\RecordingCustomerSmtpTransportFactory;
use Tests\TestCase;

class CustomerMailboxTest extends TestCase
{
    use RefreshDatabase;

    private RecordingCustomerSmtpTransportFactory $transportFactory;

    protected function setUp(): void
    {
        parent::setUp();

        $this->transportFactory = new RecordingCustomerSmtpTransportFactory;
        $this->app->instance(CustomerSmtpTransportFactory::class, $this->transportFactory);
    }

    public function test_account_a_and_b_use_independent_smtp_settings(): void
    {
        [, $workspace] = $this->createWorkspaceOwner('company');
        $accountA = $this->createAccount($workspace, [
            'email' => 'a@hostinger.test',
            'smtp_host' => 'smtp.hostinger.test',
            'smtp_port' => 465,
            'smtp_encryption' => 'ssl',
            'smtp_username' => 'a-smtp-user',
            'password' => 'password-a',
        ]);
        $accountB = $this->createAccount($workspace, [
            'email' => 'b@namecheap.test',
            'smtp_host' => 'smtp.namecheap.test',
            'smtp_port' => 587,
            'smtp_encryption' => 'tls',
            'smtp_username' => 'b-smtp-user',
            'password' => 'password-b',
        ]);

        $this->inWorkspace($workspace, function () use ($accountA, $accountB): void {
            app(WorkspaceEmailSender::class)->send($this->outboundMessage($accountA, 'one@example.com'));
            app(WorkspaceEmailSender::class)->send($this->outboundMessage($accountB, 'two@example.com'));
        });

        $this->assertCount(2, $this->transportFactory->built);
        $this->assertSame('smtp.hostinger.test', $this->transportFactory->built[0]['host']);
        $this->assertSame('a-smtp-user', $this->transportFactory->built[0]['username']);
        $this->assertSame('ssl', $this->transportFactory->built[0]['encryption']);
        $this->assertSame('smtp.namecheap.test', $this->transportFactory->built[1]['host']);
        $this->assertSame('b-smtp-user', $this->transportFactory->built[1]['username']);
        $this->assertSame('tls', $this->transportFactory->built[1]['encryption']);
        $this->assertSame($accountA->id, $this->transportFactory->built[0]['email_account_id']);
        $this->assertSame($accountB->id, $this->transportFactory->built[1]['email_account_id']);
        $this->assertArrayNotHasKey('password', $this->transportFactory->built[0]);
        $this->assertArrayNotHasKey('password', $this->transportFactory->built[1]);
    }

    public function test_workspace_a_cannot_use_workspace_b_account(): void
    {
        [$userA, $workspaceA] = $this->createWorkspaceOwner('company');
        [, $workspaceB] = $this->createWorkspaceOwner('store');
        $accountB = $this->createAccount($workspaceB, [
            'email' => 'b@other.test',
            'smtp_host' => 'smtp.other.test',
        ]);

        $this->actingAs($userA)
            ->withSession(['current_workspace_id' => $workspaceA->id])
            ->post(route('workspace.emails.messages.send'), [
                'email_account_id' => $accountB->id,
                'recipient' => 'customer@example.com',
                'subject' => 'Nope',
                'body' => 'Body',
            ])
            ->assertSessionHasErrors('email_account_id');

        $this->actingAs($userA)
            ->withSession(['current_workspace_id' => $workspaceA->id])
            ->post(route('workspace.emails.accounts.test-smtp', $accountB))
            ->assertNotFound();

        $this->actingAs($userA)
            ->withSession(['current_workspace_id' => $workspaceA->id])
            ->put(route('workspace.emails.accounts.update', $accountB), [
                'name' => 'Hacked',
                'email' => 'hacked@example.com',
                'imap_host' => 'imap.hack.test',
                'imap_port' => 993,
                'smtp_host' => 'smtp.hack.test',
                'smtp_port' => 587,
            ])
            ->assertNotFound();

        $this->assertSame(0, count($this->transportFactory->built));
    }

    public function test_password_is_encrypted_hidden_from_api_and_json(): void
    {
        [$user, $workspace] = $this->createWorkspaceOwner('company');
        $account = $this->createAccount($workspace, [
            'email' => 'secret@mail.test',
            'password' => 'plain-mailbox-secret',
        ]);

        $raw = DB::table('email_accounts')->where('id', $account->id)->value('password');
        $this->assertNotSame('plain-mailbox-secret', $raw);
        $this->assertSame('plain-mailbox-secret', $account->fresh()->password);

        $json = $account->fresh()->toArray();
        $this->assertArrayNotHasKey('password', $json);
        $this->assertStringNotContainsString('plain-mailbox-secret', $account->fresh()->toJson());

        $this->actingAs($user)
            ->withSession(['current_workspace_id' => $workspace->id])
            ->get(route('workspace.emails.accounts.index'))
            ->assertOk()
            ->assertDontSee('plain-mailbox-secret');
    }

    public function test_smtp_auth_and_timeout_failures_are_safe(): void
    {
        [, $workspace] = $this->createWorkspaceOwner('company');
        $account = $this->createAccount($workspace, [
            'email' => 'fail@mail.test',
            'password' => 'plain-mailbox-secret',
        ]);
        $message = $this->outboundMessage($account, 'to@example.com');

        $recordedLogs = [];
        Log::listen(function (object $event) use (&$recordedLogs): void {
            $recordedLogs[] = json_encode([
                $event->level ?? null,
                $event->message ?? null,
                $event->context ?? [],
            ], JSON_UNESCAPED_UNICODE);
        });
        $this->transportFactory->nextError = new TransportException('535 Authentication failed password=plain-mailbox-secret');

        try {
            $this->inWorkspace($workspace, fn () => app(WorkspaceEmailSender::class)->send($message));
            $this->fail('Expected customer mailbox exception.');
        } catch (\Throwable $exception) {
            $this->assertSame('تعذر إرسال الرسالة. تحقق من إعدادات SMTP.', $exception->getMessage());
            $this->assertStringNotContainsString('plain-mailbox-secret', $exception->getMessage());
        }

        $message = EmailMessage::withoutGlobalScopes()->findOrFail($message->id);
        $log = EmailLog::withoutGlobalScopes()->where('email_message_id', $message->id)->first();
        $this->assertNotNull($log);
        $this->assertSame('failed', $log->status);
        $this->assertSame('customer_smtp', $log->provider);
        $this->assertStringNotContainsString('plain-mailbox-secret', (string) $log->error);
        $this->assertStringNotContainsString('535', (string) $log->error);

        $this->assertNotEmpty($recordedLogs);
        foreach ($recordedLogs as $entry) {
            $this->assertStringNotContainsString('plain-mailbox-secret', (string) $entry);
        }

        $this->transportFactory->nextError = new TransportException('Connection timed out contacting smtp.mail.test');
        $timeoutMessage = $this->outboundMessage($account, 'timeout@example.com');
        try {
            $this->inWorkspace($workspace, fn () => app(WorkspaceEmailSender::class)->send($timeoutMessage));
            $this->fail('Expected timeout exception.');
        } catch (CustomerMailboxException $exception) {
            $this->assertSame('timeout', $exception->category);
            $this->assertSame('تعذر إرسال الرسالة. تحقق من إعدادات SMTP.', $exception->getMessage());
        }
    }

    public function test_from_and_reply_to_use_selected_account_and_alias_cannot_forge_from(): void
    {
        [, $workspace] = $this->createWorkspaceOwner('company');
        $account = $this->createAccount($workspace, [
            'email' => 'info@company.test',
            'name' => 'Company Mail',
            'aliases' => ['Support Desk'],
        ]);

        $forged = $this->outboundMessage($account, 'customer@example.com', [
            'sender' => 'stolen@other.com <stolen@other.com>',
            'subject' => 'Alias forge',
        ]);

        $this->inWorkspace($workspace, fn () => app(WorkspaceEmailSender::class)->send($forged));

        $sent = $this->transportFactory->lastTransport?->messages()->last();
        $this->assertNotNull($sent);
        $email = $sent->getOriginalMessage();
        $this->assertSame('info@company.test', $email->getFrom()[0]->getAddress());
        $this->assertSame('Company Mail', $email->getFrom()[0]->getName());
        $this->assertSame('info@company.test', $email->getReplyTo()[0]->getAddress());

        $aliased = $this->outboundMessage($account, 'customer@example.com', [
            'sender' => 'Support Desk <info@company.test>',
            'subject' => 'Valid alias',
        ]);
        $this->inWorkspace($workspace, fn () => app(WorkspaceEmailSender::class)->send($aliased));
        $aliasedSent = $this->transportFactory->lastTransport?->messages()->last()?->getOriginalMessage();
        $this->assertSame('info@company.test', $aliasedSent->getFrom()[0]->getAddress());
        $this->assertSame('Support Desk', $aliasedSent->getFrom()[0]->getName());
        $this->assertSame('info@company.test', $aliasedSent->getReplyTo()[0]->getAddress());
    }

    public function test_imap_uses_selected_account_and_keeps_messages_isolated(): void
    {
        [, $workspace] = $this->createWorkspaceOwner('company');
        $accountA = $this->createAccount($workspace, [
            'email' => 'a@mail.test',
            'imap_host' => 'imap.a.test',
            'imap_port' => 993,
            'imap_encryption' => 'ssl',
            'imap_username' => 'a-imap',
        ]);
        $accountB = $this->createAccount($workspace, [
            'email' => 'b@mail.test',
            'imap_host' => 'imap.b.test',
            'imap_port' => 143,
            'imap_encryption' => 'tls',
            'imap_username' => 'b-imap',
        ]);

        $sync = app(ImapSyncService::class);
        $this->assertSame('{imap.a.test:993/imap/ssl}INBOX', $sync->connectionSettings($accountA)['mailbox']);
        $this->assertSame('a-imap', $sync->connectionSettings($accountA)['username']);
        $this->assertSame('{imap.b.test:143/imap/tls}INBOX', $sync->connectionSettings($accountB)['mailbox']);
        $this->assertSame('b-imap', $sync->connectionSettings($accountB)['username']);

        $sharedId = 'shared-provider-id@mail.test';
        $messageA = $sync->importMessage($accountA, [
            'message_id' => $sharedId,
            'from' => 'client@example.com',
            'to' => $accountA->email,
            'subject' => 'For A',
            'body' => 'A body',
        ]);
        $messageB = $sync->importMessage($accountB, [
            'message_id' => $sharedId,
            'from' => 'client@example.com',
            'to' => $accountB->email,
            'subject' => 'For B',
            'body' => 'B body',
        ]);

        $this->assertNotNull($messageA);
        $this->assertNotNull($messageB);
        $this->assertSame($workspace->id, $messageA->workspace_id);
        $this->assertSame($accountA->id, $messageA->email_account_id);
        $this->assertSame($workspace->id, $messageB->workspace_id);
        $this->assertSame($accountB->id, $messageB->email_account_id);
        $this->assertSame($sharedId, $messageA->message_id);
        $this->assertSame($sharedId, $messageB->message_id);
        $this->assertNotSame($messageA->id, $messageB->id);

        $this->assertTrue(
            EmailMessage::withoutGlobalScopes()
                ->where('email_account_id', $accountA->id)
                ->where('subject', 'For A')
                ->exists()
        );
        $this->assertFalse(
            EmailMessage::withoutGlobalScopes()
                ->where('email_account_id', $accountA->id)
                ->where('subject', 'For B')
                ->exists()
        );
    }

    public function test_forged_job_ids_fail_closed(): void
    {
        [, $workspaceA] = $this->createWorkspaceOwner('company');
        [, $workspaceB] = $this->createWorkspaceOwner('store');
        $accountA = $this->createAccount($workspaceA, ['email' => 'a@mail.test']);
        $accountB = $this->createAccount($workspaceB, ['email' => 'b@mail.test']);
        $messageA = $this->outboundMessage($accountA, 'to@example.com');

        $this->transportFactory->built = [];
        (new SendEmailMessageJob($messageA->id, $workspaceB->id))->handle(app(WorkspaceEmailSender::class));
        $this->assertSame(0, count($this->transportFactory->built));
        $this->assertSame('sending', $messageA->fresh()->delivery_status);

        (new SendEmailMessageJob($messageA->id, null))->handle(app(WorkspaceEmailSender::class));
        $this->assertSame(0, count($this->transportFactory->built));

        $sync = $this->mock(ImapSyncService::class);
        $sync->shouldNotReceive('syncAccount');
        (new SyncEmailInboxJob($accountB->id, $workspaceA->id))->handle($sync, app(CustomerMailboxErrorClassifier::class));
        (new SyncEmailInboxJob($accountA->id, null))->handle($sync, app(CustomerMailboxErrorClassifier::class));
    }

    public function test_central_email_service_remains_on_resend_path(): void
    {
        config()->set('email_templates.mailer', 'resend');
        config()->set('services.resend.key', '');

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Resend API key is not configured.');

        app(CentralEmailService::class)->send([
            'to' => ['ops@example.com'],
            'template' => 'general_notification',
            'subject' => 'System notice',
        ]);
    }

    public function test_workspace_email_sender_does_not_use_central_email_service(): void
    {
        [, $workspace] = $this->createWorkspaceOwner('company');
        $account = $this->createAccount($workspace, ['email' => 'info@company.test']);
        $central = \Mockery::mock(CentralEmailService::class);
        $central->shouldNotReceive('send');
        $this->app->instance(CentralEmailService::class, $central);

        $log = $this->inWorkspace($workspace, fn () => app(WorkspaceEmailSender::class)->send(
            $this->outboundMessage($account, 'customer@example.com')
        ));

        $this->assertSame('customer_smtp', $log->provider);
        $this->assertSame('sent', $log->status);
        $this->assertSame($account->id, $log->meta['email_account_id']);
        $this->assertSame('smtp.mail.test', $log->meta['smtp_host']);
        $this->assertArrayNotHasKey('password', $log->meta);
    }

    public function test_successful_customer_smtp_marks_log_and_message_sent(): void
    {
        [, $workspace] = $this->createWorkspaceOwner('company');
        $account = $this->createAccount($workspace, ['email' => 'info@company.test']);
        $message = $this->outboundMessage($account, 'customer@example.com');

        $log = $this->inWorkspace($workspace, fn () => app(WorkspaceEmailSender::class)->send($message));

        $message = EmailMessage::withoutGlobalScopes()->findOrFail($message->id);
        $this->assertSame('sent', $log->status);
        $this->assertSame('customer_smtp', $log->provider);
        $this->assertSame('sent', $message->delivery_status);
        $this->assertNotNull($message->delivered_at);
        $this->assertNull($message->delivery_error);
        $this->assertSame($workspace->id, $message->workspace_id);
        $this->assertSame($account->id, $message->email_account_id);
    }

    public function test_successful_mobile_send_marks_message_sent(): void
    {
        [$user, $workspace] = $this->createWorkspaceOwner('company');
        $account = $this->createAccount($workspace, ['email' => 'info@company.test']);

        $message = $this->inWorkspace($workspace, fn () => app(MobileEmailService::class)->send(
            $workspace,
            $user,
            [
                'email_account_id' => $account->id,
                'to' => 'customer@example.com',
                'subject' => 'Mobile',
                'body' => 'Hello from mobile.',
            ],
        ));

        $this->assertSame('sent', $message->delivery_status);
        $this->assertNotNull($message->delivered_at);
        $this->assertDatabaseHas('email_logs', [
            'email_message_id' => $message->id,
            'workspace_id' => $workspace->id,
            'provider' => 'customer_smtp',
            'status' => 'sent',
        ]);
        $this->assertDatabaseHas('email_messages', [
            'id' => $message->id,
            'workspace_id' => $workspace->id,
            'email_account_id' => $account->id,
            'delivery_status' => 'sent',
        ]);
    }

    public function test_smtp_failure_does_not_mark_message_sent(): void
    {
        [, $workspace] = $this->createWorkspaceOwner('company');
        $account = $this->createAccount($workspace, ['email' => 'fail@mail.test']);
        $message = $this->outboundMessage($account, 'to@example.com');

        $this->transportFactory->nextError = new TransportException('535 Authentication failed');

        try {
            $this->inWorkspace($workspace, fn () => app(WorkspaceEmailSender::class)->send($message));
            $this->fail('Expected customer mailbox exception.');
        } catch (CustomerMailboxException) {
            // Sender must not mark the message sent when SMTP fails.
        }

        $message = EmailMessage::withoutGlobalScopes()->findOrFail($message->id);
        $this->assertNotSame('sent', $message->delivery_status);
        $this->assertNull($message->delivered_at);
        $this->assertDatabaseHas('email_logs', [
            'email_message_id' => $message->id,
            'status' => 'failed',
        ]);
    }

    public function test_delivery_status_update_stays_workspace_isolated(): void
    {
        [, $workspaceA] = $this->createWorkspaceOwner('company');
        [, $workspaceB] = $this->createWorkspaceOwner('store');
        $accountA = $this->createAccount($workspaceA, ['email' => 'a@mail.test']);
        $accountB = $this->createAccount($workspaceB, ['email' => 'b@mail.test']);
        $messageA = $this->outboundMessage($accountA, 'a@example.com');
        $messageB = $this->outboundMessage($accountB, 'b@example.com');

        $this->inWorkspace($workspaceA, fn () => app(WorkspaceEmailSender::class)->send($messageA));

        $messageA = EmailMessage::withoutGlobalScopes()->findOrFail($messageA->id);
        $messageB = EmailMessage::withoutGlobalScopes()->findOrFail($messageB->id);

        $this->assertSame('sent', $messageA->delivery_status);
        $this->assertSame($workspaceA->id, $messageA->workspace_id);
        $this->assertSame($accountA->id, $messageA->email_account_id);
        $this->assertSame('sending', $messageB->delivery_status);
        $this->assertNull($messageB->delivered_at);
        $this->assertSame($workspaceB->id, $messageB->workspace_id);

        $this->assertDatabaseHas('email_logs', [
            'email_message_id' => $messageA->id,
            'workspace_id' => $workspaceA->id,
            'status' => 'sent',
        ]);
        $this->assertDatabaseMissing('email_logs', [
            'email_message_id' => $messageB->id,
        ]);

        $this->transportFactory->built = [];
        (new SendEmailMessageJob($messageB->id, $workspaceA->id))->handle(app(WorkspaceEmailSender::class));

        $this->assertSame(0, count($this->transportFactory->built));
        $this->assertSame('sending', EmailMessage::withoutGlobalScopes()->findOrFail($messageB->id)->delivery_status);
        $this->assertNull(EmailMessage::withoutGlobalScopes()->findOrFail($messageB->id)->delivered_at);
    }

    public function test_smtp_connection_test_succeeds_and_fails_safely(): void
    {
        [$user, $workspace] = $this->createWorkspaceOwner('company');
        $account = $this->createAccount($workspace, [
            'email' => 'info@company.test',
            'password' => 'plain-mailbox-secret',
        ]);

        $this->actingAs($user)
            ->withSession(['current_workspace_id' => $workspace->id])
            ->post(route('workspace.emails.accounts.test-smtp', $account))
            ->assertRedirect(route('workspace.emails.accounts.index', ['account_id' => $account->id]))
            ->assertSessionHas('success', 'اتصال SMTP ناجح.');

        $account->refresh();
        $this->assertSame('connected', $account->smtp_status);
        $this->assertNotNull($account->smtp_last_tested_at);

        $this->transportFactory->nextError = new TransportException('535 Authentication failed password=plain-mailbox-secret');

        $this->actingAs($user)
            ->withSession(['current_workspace_id' => $workspace->id])
            ->post(route('workspace.emails.accounts.test-smtp', $account))
            ->assertRedirect(route('workspace.emails.accounts.index', ['account_id' => $account->id]))
            ->assertSessionHas('error', 'تعذر الاتصال بخادم SMTP. تحقق من اسم الخادم والمنفذ وبيانات الدخول.')
            ->assertDontSee('plain-mailbox-secret');

        $account->refresh();
        $this->assertSame('failed', $account->smtp_status);
        $this->assertStringNotContainsString('plain-mailbox-secret', (string) session('error'));
    }

    public function test_imap_extension_missing_does_not_claim_success(): void
    {
        [$user, $workspace] = $this->createWorkspaceOwner('company');
        $account = $this->createAccount($workspace, ['email' => 'info@company.test']);

        $imap = $this->mock(ImapSyncService::class);
        $imap->shouldReceive('extensionAvailable')->once()->andReturn(false);
        $imap->shouldNotReceive('syncAccount');

        $this->actingAs($user)
            ->withSession(['current_workspace_id' => $workspace->id])
            ->post(route('workspace.emails.accounts.sync', $account))
            ->assertRedirect(route('workspace.emails.accounts.index', ['account_id' => $account->id]))
            ->assertSessionHas('error', 'تعذر مزامنة الوارد. امتداد IMAP غير مفعّل على الخادم.');
    }

    public function test_branded_mailable_always_uses_account_from_address(): void
    {
        [, $workspace] = $this->createWorkspaceOwner('company');
        $account = $this->createAccount($workspace, ['email' => 'info@company.test', 'name' => 'Info']);
        $message = $this->outboundMessage($account, 'to@example.com', [
            'sender' => 'Forged <forged@evil.test>',
        ]);

        $envelope = (new WorkspaceBrandedEmail($message, $account))->envelope();
        $this->assertSame('info@company.test', $envelope->from->address);
        $this->assertSame('Forged', $envelope->from->name);
        $this->assertSame('info@company.test', $envelope->replyTo[0]->address);
    }

    /**
     * @param  array<string, mixed>  $overrides
     */
    private function createAccount(Workspace $workspace, array $overrides = []): EmailAccount
    {
        return EmailAccount::withoutGlobalScopes()->create(array_merge([
            'workspace_id' => $workspace->id,
            'name' => 'Mailbox',
            'email' => 'mail@company.test',
            'password' => 'secret',
            'imap_host' => 'imap.mail.test',
            'imap_port' => 993,
            'smtp_host' => 'smtp.mail.test',
            'smtp_port' => 587,
            'brand_color' => '#06C2A4',
            'aliases' => ['Support'],
        ], $overrides));
    }

    /**
     * @param  array<string, mixed>  $overrides
     */
    private function outboundMessage(EmailAccount $account, string $recipient, array $overrides = []): EmailMessage
    {
        return EmailMessage::withoutGlobalScopes()->create(array_merge([
            'workspace_id' => $account->workspace_id,
            'email_account_id' => $account->id,
            'sender' => $account->email,
            'recipient' => $recipient,
            'subject' => 'Hello',
            'body' => 'Body',
            'type' => 'outbound',
            'delivery_status' => 'sending',
            'message_id' => '<test-'.uniqid().'@company.test>',
        ], $overrides));
    }

    /**
     * @return array{0: User, 1: Workspace}
     */
    private function createWorkspaceOwner(string $workspaceType): array
    {
        $user = User::factory()->create();
        $workspace = Workspace::factory()->create([
            'owner_user_id' => $user->id,
            'type' => $workspaceType,
        ]);

        $workspace->users()->attach($user->id, [
            'membership_role' => 'owner',
            'status' => 'active',
            'joined_at' => now(),
        ]);

        $plan = Plan::query()->create([
            'code' => $workspaceType.'_customer_mail_'.uniqid(),
            'name' => 'Customer Mail Test Plan',
            'tier' => 'pro',
            'workspace_type' => $workspaceType,
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

        return [$user, $workspace];
    }
}
