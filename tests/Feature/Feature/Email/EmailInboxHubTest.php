<?php

namespace Tests\Feature\Feature\Email;

use App\Models\EmailAccount;
use App\Models\EmailAttachment;
use App\Models\EmailContact;
use App\Models\EmailLog;
use App\Models\EmailMessage;
use App\Models\Plan;
use App\Models\Subscription;
use App\Models\User;
use App\Models\Workspace;
use App\Services\Email\WorkspaceEmailSender;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class EmailInboxHubTest extends TestCase
{
    use RefreshDatabase;

    public function test_workspace_can_create_email_account_with_isolation(): void
    {
        [$userA, $workspaceA] = $this->createWorkspaceOwner('company');
        [, $workspaceB] = $this->createWorkspaceOwner('store');

        EmailAccount::withoutGlobalScopes()->create([
            'workspace_id' => $workspaceB->id,
            'name' => 'Other Workspace Mail',
            'email' => 'other@workspace-b.test',
            'password' => 'secret',
            'imap_host' => 'imap.other.test',
            'imap_port' => 993,
            'smtp_host' => 'smtp.other.test',
            'smtp_port' => 587,
            'brand_color' => '#000000',
            'aliases' => ['Other'],
        ]);

        $this->actingAs($userA)
            ->withSession(['current_workspace_id' => $workspaceA->id])
            ->post(route('workspace.emails.accounts.store'), [
                'name' => 'Support Mail',
                'email' => 'support@workspace-a.test',
                'password' => 'secret',
                'imap_host' => 'imap.workspace-a.test',
                'imap_port' => 993,
                'smtp_host' => 'smtp.workspace-a.test',
                'smtp_port' => 587,
                'brand_color' => '#06C2A4',
                'aliases' => "Support\nSales",
            ])
            ->assertRedirect(route('workspace.emails.accounts.index'));

        $this->assertDatabaseHas('email_accounts', [
            'workspace_id' => $workspaceA->id,
            'email' => 'support@workspace-a.test',
        ]);

        $otherAccount = EmailAccount::withoutGlobalScopes()
            ->where('workspace_id', $workspaceB->id)
            ->firstOrFail();

        // A member of workspace A cannot trigger sync for account in workspace B.
        $this->actingAs($userA)
            ->withSession(['current_workspace_id' => $workspaceA->id])
            ->post(route('workspace.emails.accounts.sync', $otherAccount))
            ->assertNotFound();
    }

    public function test_sending_email_uses_selected_company_account_and_marks_message_as_sent(): void
    {
        [$user, $workspace] = $this->createWorkspaceOwner('company');
        $account = EmailAccount::withoutGlobalScopes()->create([
            'workspace_id' => $workspace->id,
            'name' => 'Company Mail',
            'email' => 'company@mail.test',
            'password' => 'secret',
            'imap_host' => 'imap.mail.test',
            'imap_port' => 993,
            'smtp_host' => 'smtp.mail.test',
            'smtp_port' => 587,
            'brand_color' => '#06C2A4',
            'aliases' => ['Support'],
        ]);

        $this->mock(WorkspaceEmailSender::class, function ($mock): void {
            $mock->shouldReceive('send')->once()->andReturn(new EmailLog([
                'provider_message_id' => 'test-msg-1',
                'status' => 'sent',
            ]));
        });

        $this->actingAs($user)
            ->withSession(['current_workspace_id' => $workspace->id])
            ->post(route('workspace.emails.messages.send'), [
                'email_account_id' => $account->id,
                'recipient' => 'customer@example.com',
                'subject' => 'Welcome',
                'body' => 'Hello from workspace mail.',
            ])
            ->assertRedirect(route('workspace.emails.compose', ['account_id' => $account->id]))
            ->assertSessionHas('success', 'تم إرسال الرسالة بنجاح.');

        $this->assertDatabaseHas('email_messages', [
            'workspace_id' => $workspace->id,
            'email_account_id' => $account->id,
            'type' => 'outbound',
            'recipient' => 'customer@example.com',
            'delivery_status' => 'sent',
        ]);
    }

    public function test_bulk_send_can_target_selected_subset_of_contacts(): void
    {
        [$user, $workspace] = $this->createWorkspaceOwner('company');
        $account = EmailAccount::withoutGlobalScopes()->create([
            'workspace_id' => $workspace->id,
            'name' => 'Company Mail',
            'email' => 'company@mail.test',
            'password' => 'secret',
            'imap_host' => 'imap.mail.test',
            'imap_port' => 993,
            'smtp_host' => 'smtp.mail.test',
            'smtp_port' => 587,
            'brand_color' => '#06C2A4',
            'aliases' => ['Support'],
        ]);

        $contactA = EmailContact::withoutGlobalScopes()->create([
            'workspace_id' => $workspace->id,
            'name' => 'شركة ألف',
            'email' => 'a@example.com',
            'normalized_email' => 'a@example.com',
        ]);
        EmailContact::withoutGlobalScopes()->create([
            'workspace_id' => $workspace->id,
            'name' => 'شركة باء',
            'email' => 'b@example.com',
            'normalized_email' => 'b@example.com',
        ]);
        $contactC = EmailContact::withoutGlobalScopes()->create([
            'workspace_id' => $workspace->id,
            'name' => 'شركة جيم',
            'email' => 'c@example.com',
            'normalized_email' => 'c@example.com',
        ]);

        $this->mock(WorkspaceEmailSender::class, function ($mock): void {
            $mock->shouldReceive('send')->once()->andReturn(new EmailLog([
                'provider_message_id' => 'test-msg-1',
                'status' => 'sent',
            ]));
        });

        $this->actingAs($user)
            ->withSession(['current_workspace_id' => $workspace->id])
            ->post(route('workspace.emails.messages.send'), [
                'email_account_id' => $account->id,
                'recipient' => '',
                'recipient_contact_ids' => [$contactA->id, $contactC->id],
                'subject' => 'Bulk',
                'body' => 'Bulk body',
            ])
            ->assertRedirect(route('workspace.emails.compose', ['account_id' => $account->id]))
            ->assertSessionHas('success', 'تم إرسال الرسالة بنجاح.');

        $this->assertDatabaseHas('email_messages', [
            'workspace_id' => $workspace->id,
            'email_account_id' => $account->id,
            'recipient' => 'a@example.com, c@example.com',
            'delivery_status' => 'sent',
        ]);
    }

    public function test_workspace_can_update_email_account_settings(): void
    {
        [$user, $workspace] = $this->createWorkspaceOwner('company');

        Storage::fake('public');
        Storage::disk('public')->put('workspaces/'.$workspace->id.'/email-logos/old-logo.png', 'old');

        $account = EmailAccount::withoutGlobalScopes()->create([
            'workspace_id' => $workspace->id,
            'name' => 'Primary Account',
            'email' => 'old@company.test',
            'password' => 'old-password',
            'imap_host' => 'imap.old.test',
            'imap_port' => 993,
            'smtp_host' => 'smtp.old.test',
            'smtp_port' => 587,
            'logo_path' => 'workspaces/'.$workspace->id.'/email-logos/old-logo.png',
            'brand_color' => '#06C2A4',
            'aliases' => ['Old Alias'],
        ]);

        $this->actingAs($user)
            ->withSession(['current_workspace_id' => $workspace->id])
            ->put(route('workspace.emails.accounts.update', $account), [
                'name' => 'Updated Account',
                'email' => 'new@company.test',
                'password' => 'new-password',
                'imap_host' => 'imap.new.test',
                'imap_port' => 995,
                'smtp_host' => 'smtp.new.test',
                'smtp_port' => 465,
                'brand_color' => '#112233',
                'aliases' => "Support Team\nSales Team",
                'remove_logo' => 1,
            ])
            ->assertRedirect(route('workspace.emails.accounts.index', ['account_id' => $account->id]));

        $account->refresh();
        $this->assertSame('Updated Account', $account->name);
        $this->assertSame('new@company.test', $account->email);
        $this->assertSame('imap.new.test', $account->imap_host);
        $this->assertSame(995, $account->imap_port);
        $this->assertSame('smtp.new.test', $account->smtp_host);
        $this->assertSame(465, $account->smtp_port);
        $this->assertSame('#112233', $account->brand_color);
        $this->assertSame(['Support Team', 'Sales Team'], $account->aliases);
        $this->assertNull($account->logo_path);
        Storage::disk('public')->assertMissing('workspaces/'.$workspace->id.'/email-logos/old-logo.png');
    }

    public function test_workspace_can_delete_message_and_attachment_files(): void
    {
        [$user, $workspace] = $this->createWorkspaceOwner('company');
        Storage::fake('public');

        $account = EmailAccount::withoutGlobalScopes()->create([
            'workspace_id' => $workspace->id,
            'name' => 'Company Mail',
            'email' => 'mail@company.test',
            'password' => 'secret',
            'imap_host' => 'imap.company.test',
            'imap_port' => 993,
            'smtp_host' => 'smtp.company.test',
            'smtp_port' => 587,
            'brand_color' => '#06C2A4',
            'aliases' => ['Support'],
        ]);

        $message = EmailMessage::withoutGlobalScopes()->create([
            'workspace_id' => $workspace->id,
            'email_account_id' => $account->id,
            'sender' => 'agent@company.test',
            'recipient' => 'customer@example.com',
            'subject' => 'Message for delete',
            'body' => 'Body text.',
            'type' => 'inbound',
            'message_id' => 'msg-delete-1',
            'thread_key' => 'msg-delete-1',
        ]);

        $attachmentPath = 'workspaces/'.$workspace->id.'/emails/attachments/sample.txt';
        Storage::disk('public')->put($attachmentPath, 'attachment-content');
        EmailAttachment::query()->create([
            'workspace_id' => $workspace->id,
            'message_id' => $message->id,
            'file_path' => $attachmentPath,
            'disk' => 'public',
            'file_type' => 'text/plain',
            'file_size' => 18,
        ]);

        $this->actingAs($user)
            ->withSession(['current_workspace_id' => $workspace->id])
            ->delete(route('workspace.emails.messages.destroy', $message), [
                'account_id' => $account->id,
                'return_to' => 'inbox',
            ])
            ->assertRedirect(route('workspace.emails.inbox', [
                'account_id' => $account->id,
            ]));

        $this->assertDatabaseMissing('email_messages', ['id' => $message->id]);
        $this->assertDatabaseMissing('email_attachments', ['message_id' => $message->id]);
        Storage::disk('public')->assertMissing($attachmentPath);
    }

    public function test_failed_send_returns_clear_error_and_keeps_message_marked_failed(): void
    {
        [$user, $workspace] = $this->createWorkspaceOwner('company');
        $account = EmailAccount::withoutGlobalScopes()->create([
            'workspace_id' => $workspace->id,
            'name' => 'Failed Sender',
            'email' => 'failed@mail.test',
            'password' => 'secret',
            'imap_host' => 'imap.mail.test',
            'imap_port' => 993,
            'smtp_host' => 'smtp.mail.test',
            'smtp_port' => 587,
            'brand_color' => '#06C2A4',
            'aliases' => ['Support'],
        ]);

        $this->mock(WorkspaceEmailSender::class, function ($mock): void {
            $mock->shouldReceive('send')->once()->andThrow(new \RuntimeException('SMTP auth failed'));
        });

        $this->actingAs($user)
            ->withSession(['current_workspace_id' => $workspace->id])
            ->post(route('workspace.emails.messages.send'), [
                'email_account_id' => $account->id,
                'recipient' => 'customer@example.com',
                'subject' => 'Fail me',
                'body' => 'Body',
            ])
            ->assertRedirect(route('workspace.emails.compose', ['account_id' => $account->id]))
            ->assertSessionHas('error', 'تعذر إرسال الرسالة حاليًا. يرجى المحاولة لاحقًا.');

        $this->assertDatabaseHas('email_messages', [
            'workspace_id' => $workspace->id,
            'email_account_id' => $account->id,
            'delivery_status' => 'failed',
        ]);
    }

    public function test_send_fails_when_no_recipients_or_selected_contacts(): void
    {
        [$user, $workspace] = $this->createWorkspaceOwner('company');
        $account = EmailAccount::withoutGlobalScopes()->create([
            'workspace_id' => $workspace->id,
            'name' => 'No Recipient Sender',
            'email' => 'noreply@mail.test',
            'password' => 'secret',
            'imap_host' => 'imap.mail.test',
            'imap_port' => 993,
            'smtp_host' => 'smtp.mail.test',
            'smtp_port' => 587,
            'brand_color' => '#06C2A4',
            'aliases' => ['Support'],
        ]);

        $this->actingAs($user)
            ->withSession(['current_workspace_id' => $workspace->id])
            ->post(route('workspace.emails.messages.send'), [
                'email_account_id' => $account->id,
                'recipient' => '',
                'subject' => 'No recipient',
                'body' => 'Body',
            ])
            ->assertRedirect(route('workspace.emails.compose', ['account_id' => $account->id]))
            ->assertSessionHas('error', 'يرجى إدخال مستلم واحد على الأقل أو اختيار جهات اتصال.');
    }

    public function test_workspace_can_delete_company_email_account_with_related_files(): void
    {
        [$user, $workspace] = $this->createWorkspaceOwner('company');
        Storage::fake('public');

        $logoPath = 'workspaces/'.$workspace->id.'/email-logos/logo.png';
        Storage::disk('public')->put($logoPath, 'logo');

        $account = EmailAccount::withoutGlobalScopes()->create([
            'workspace_id' => $workspace->id,
            'name' => 'Delete Company',
            'email' => 'delete@company.test',
            'password' => 'secret',
            'imap_host' => 'imap.company.test',
            'imap_port' => 993,
            'smtp_host' => 'smtp.company.test',
            'smtp_port' => 587,
            'logo_path' => $logoPath,
            'brand_color' => '#06C2A4',
            'aliases' => ['Support'],
        ]);

        $message = EmailMessage::withoutGlobalScopes()->create([
            'workspace_id' => $workspace->id,
            'email_account_id' => $account->id,
            'sender' => 'agent@company.test',
            'recipient' => 'customer@example.com',
            'subject' => 'Attachment',
            'body' => 'Body',
            'type' => 'outbound',
            'delivery_status' => 'sent',
        ]);

        $attachmentPath = 'workspaces/'.$workspace->id.'/emails/attachments/attached.txt';
        Storage::disk('public')->put($attachmentPath, 'file');
        EmailAttachment::query()->create([
            'workspace_id' => $workspace->id,
            'message_id' => $message->id,
            'file_path' => $attachmentPath,
            'disk' => 'public',
            'file_type' => 'text/plain',
            'file_size' => 4,
        ]);

        $this->actingAs($user)
            ->withSession(['current_workspace_id' => $workspace->id])
            ->delete(route('workspace.emails.accounts.destroy', $account))
            ->assertRedirect(route('workspace.emails.accounts.index'));

        $this->assertDatabaseMissing('email_accounts', ['id' => $account->id]);
        $this->assertDatabaseMissing('email_messages', ['id' => $message->id]);
        Storage::disk('public')->assertMissing($logoPath);
        Storage::disk('public')->assertMissing($attachmentPath);
    }

    public function test_contact_email_is_unique_case_insensitive(): void
    {
        [$user, $workspace] = $this->createWorkspaceOwner('company');

        EmailContact::withoutGlobalScopes()->create([
            'workspace_id' => $workspace->id,
            'name' => 'شركة أحمد للهواتف',
            'email' => 'ahmed@example.com',
            'normalized_email' => 'ahmed@example.com',
        ]);

        $this->actingAs($user)
            ->withSession(['current_workspace_id' => $workspace->id])
            ->post(route('workspace.emails.contacts.store'), [
                'name' => 'أحمد للاتصالات',
                'email' => 'AHMED@EXAMPLE.COM',
            ])
            ->assertRedirect()
            ->assertSessionHas('error', function (string $message): bool {
                return str_contains($message, 'هذا البريد الإلكتروني مسجل مسبقًا')
                    && str_contains($message, 'شركة أحمد للهواتف')
                    && str_contains($message, 'ahmed@example.com');
            });

        $this->assertDatabaseCount('email_contacts', 1);
        $this->assertDatabaseHas('email_contacts', [
            'workspace_id' => $workspace->id,
            'name' => 'شركة أحمد للهواتف',
            'normalized_email' => 'ahmed@example.com',
        ]);
    }

    public function test_contact_lookup_and_search_work_by_name_and_email(): void
    {
        [$user, $workspace] = $this->createWorkspaceOwner('company');
        EmailContact::withoutGlobalScopes()->create([
            'workspace_id' => $workspace->id,
            'name' => 'شركة أحمد للهواتف',
            'email' => 'ahmed@example.com',
            'normalized_email' => 'ahmed@example.com',
        ]);

        $this->actingAs($user)
            ->withSession(['current_workspace_id' => $workspace->id])
            ->getJson(route('workspace.emails.contacts.lookup', ['email' => 'AHMED@EXAMPLE.COM']))
            ->assertOk()
            ->assertJsonPath('found', true)
            ->assertJsonPath('contact.name', 'شركة أحمد للهواتف');

        $this->actingAs($user)
            ->withSession(['current_workspace_id' => $workspace->id])
            ->getJson(route('workspace.emails.contacts.search', ['q' => 'أحمد']))
            ->assertOk()
            ->assertJsonCount(1, 'contacts')
            ->assertJsonPath('contacts.0.email', 'ahmed@example.com');

        $this->actingAs($user)
            ->withSession(['current_workspace_id' => $workspace->id])
            ->getJson(route('workspace.emails.contacts.search', ['q' => 'ahmed@example.com']))
            ->assertOk()
            ->assertJsonCount(1, 'contacts')
            ->assertJsonPath('contacts.0.name', 'شركة أحمد للهواتف');
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
            'code' => $workspaceType.'_email_test_'.uniqid(),
            'name' => 'Email Test Plan',
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
