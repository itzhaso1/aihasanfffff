<?php

namespace Tests\Feature\Feature\Finance;

use App\Models\AuditLog;
use App\Models\Customer;
use App\Models\EmailLog;
use App\Models\EmailMessage;
use App\Models\Finance\FinanceDocumentDelivery;
use App\Models\Finance\FinanceInvoice;
use App\Models\Finance\FinanceInvoicePayment;
use App\Models\Finance\FinanceJournalEntry;
use App\Models\Finance\IssuedDocumentSnapshot;
use App\Models\Payment;
use App\Models\Plan;
use App\Models\Subscription;
use App\Models\User;
use App\Models\WhatsAppOutboundMessage;
use App\Models\Workspace;
use App\Models\WorkspaceFeatureFlag;
use App\Services\Email\CentralEmailService;
use App\Services\Finance\FinanceBootstrapService;
use App\Services\Finance\InvoiceService;
use App\Services\WhatsApp\WhatsAppOutboundService;
use Database\Seeders\FoundationSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Storage;
use Mockery;
use RuntimeException;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

class FinancePhaseESendInvoiceTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Notification::fake();
        Storage::fake('public');
        $this->seed(FoundationSeeder::class);
    }

    public function test_owner_can_send_issued_invoice_email_with_pdf_and_delivery_record(): void
    {
        [$user, $workspace, $invoice, $customer] = $this->issuedInvoice();
        $payloads = [];
        $this->fakeSuccessfulMailer($payloads);
        $this->forbidWhatsApp();
        $before = $this->financialSnapshot($invoice);

        $this->actingAs($user)
            ->withSession(['current_workspace_id' => $workspace->id])
            ->post(route('workspace.finance.invoices.send', $invoice), [
                'email' => $customer->email,
                'phone' => '0500000000',
                'subject' => 'فاتورة رقم '.$invoice->invoice_number,
                'message' => 'السلام عليكم، نرفق الفاتورة.',
                'attach_pdf' => '1',
            ])
            ->assertRedirect(route('workspace.finance.invoices.show', $invoice))
            ->assertSessionHas('success');

        $this->assertCount(1, $payloads);
        $sent = $payloads[0];
        $this->assertSame('invoice_email', $sent['template']);
        $this->assertSame([$customer->email], $sent['to']);
        $this->assertStringContainsString($invoice->invoice_number, (string) $sent['subject']);
        $this->assertStringContainsString($customer->name, implode("\n", $sent['data']['lines'] ?? []));
        $this->assertStringContainsString($invoice->invoice_number, implode("\n", $sent['data']['lines'] ?? []));
        $this->assertStringContainsString(number_format((float) $invoice->total, 2), implode("\n", $sent['data']['lines'] ?? []));
        $this->assertNotEmpty($sent['attachments']);
        $this->assertSame('invoice-'.$invoice->invoice_number.'.pdf', $sent['attachments'][0]['name']);
        $this->assertSame('application/pdf', $sent['attachments'][0]['mime']);
        $storedPath = $sent['attachments'][0]['storage_path'];
        $this->assertTrue(Storage::disk('public')->exists($storedPath));
        $this->assertStringStartsWith('%PDF', (string) Storage::disk('public')->get($storedPath));
        $this->assertStringContainsString((string) $workspace->id, $storedPath);
        $this->assertStringContainsString($invoice->invoice_number, $storedPath);

        $delivery = FinanceDocumentDelivery::withoutGlobalScopes()->firstOrFail();
        $this->assertSame('invoice', $delivery->document_type);
        $this->assertSame($invoice->id, $delivery->document_id);
        $this->assertSame('email', $delivery->channel);
        $this->assertSame($customer->email, $delivery->recipient);
        $this->assertSame('0500000000', $delivery->recipient_phone);
        $this->assertSame('sent', $delivery->status);
        $this->assertNotNull($delivery->sent_at);
        $this->assertNotNull($delivery->provider_message_id);
        $this->assertNotNull($delivery->email_log_id);

        $this->assertTrue(
            AuditLog::withoutGlobalScopes()
                ->where('workspace_id', $workspace->id)
                ->where('action', 'invoice_sent')
                ->where('entity_id', $invoice->id)
                ->where('entity_type', FinanceInvoice::class)
                ->exists()
        );

        $this->assertSame($before, $this->financialSnapshot($invoice->fresh()));
        $this->assertSame(0, FinanceInvoicePayment::withoutGlobalScopes()->count());
        $this->assertSame(0, Payment::withoutGlobalScopes()->count());
        $this->assertSame(0, EmailMessage::withoutGlobalScopes()->count());
        $this->assertSame(0, WhatsAppOutboundMessage::withoutGlobalScopes()->count());
        $this->assertSame($customer->email, $customer->fresh()->email);
    }

    public function test_unauthorized_user_cannot_send_invoice(): void
    {
        [, $workspace, $invoice, $customer] = $this->issuedInvoice();
        $this->fakeSuccessfulMailer();
        $editor = $this->attachStaff($workspace, ['invoices.view', 'invoices.edit', 'invoices.issue']);

        $this->actingAs($editor)
            ->withSession(['current_workspace_id' => $workspace->id])
            ->post(route('workspace.finance.invoices.send', $invoice), [
                'email' => $customer->email,
                'attach_pdf' => '1',
            ])
            ->assertForbidden();

        $this->assertSame(0, FinanceDocumentDelivery::withoutGlobalScopes()->count());
        $this->assertSame(0, AuditLog::withoutGlobalScopes()->where('action', 'invoice_sent')->count());
    }

    public function test_agent_with_invoices_send_can_send(): void
    {
        [, $workspace, $invoice, $customer] = $this->issuedInvoice();
        $this->fakeSuccessfulMailer();
        $sender = $this->attachStaff($workspace, ['invoices.view', 'invoices.send']);

        $this->actingAs($sender)
            ->withSession(['current_workspace_id' => $workspace->id])
            ->post(route('workspace.finance.invoices.send', $invoice), [
                'email' => $customer->email,
                'attach_pdf' => '1',
            ])
            ->assertRedirect();

        $this->assertSame(1, FinanceDocumentDelivery::withoutGlobalScopes()->where('status', 'sent')->count());
    }

    public function test_workspace_isolation_prevents_cross_workspace_sending(): void
    {
        [$userA, $workspaceA] = $this->createWorkspaceOwner('company');
        [, , $invoiceB, $customerB] = $this->issuedInvoice();
        $this->fakeSuccessfulMailer();

        $this->actingAs($userA)
            ->withSession(['current_workspace_id' => $workspaceA->id])
            ->post(route('workspace.finance.invoices.send', $invoiceB), [
                'email' => $customerB->email,
                'attach_pdf' => '1',
            ])
            ->assertNotFound();

        $this->assertSame(0, FinanceDocumentDelivery::withoutGlobalScopes()->count());
    }

    public function test_multiple_sends_create_multiple_delivery_records(): void
    {
        [$user, $workspace, $invoice, $customer] = $this->issuedInvoice();
        $this->fakeSuccessfulMailer();

        $this->actingAs($user)->withSession(['current_workspace_id' => $workspace->id])
            ->post(route('workspace.finance.invoices.send', $invoice), [
                'email' => $customer->email,
                'attach_pdf' => '1',
            ])
            ->assertRedirect();

        $this->actingAs($user)->withSession(['current_workspace_id' => $workspace->id])
            ->post(route('workspace.finance.invoices.send', $invoice), [
                'email' => 'override@example.com',
                'attach_pdf' => '1',
            ])
            ->assertRedirect();

        $deliveries = FinanceDocumentDelivery::withoutGlobalScopes()->orderBy('id')->get();
        $this->assertCount(2, $deliveries);
        $this->assertSame($customer->email, $deliveries[0]->recipient);
        $this->assertSame('override@example.com', $deliveries[1]->recipient);
        $this->assertSame($customer->email, $customer->fresh()->email);
        $this->assertSame(2, AuditLog::withoutGlobalScopes()->where('action', 'invoice_sent')->count());
    }

    public function test_failed_email_records_failed_delivery_without_changing_invoice_or_audit(): void
    {
        [$user, $workspace, $invoice, $customer] = $this->issuedInvoice();
        $before = $this->financialSnapshot($invoice);
        $journalCount = FinanceJournalEntry::withoutGlobalScopes()->count();
        $snapshotCount = IssuedDocumentSnapshot::withoutGlobalScopes()->count();

        $mailer = Mockery::mock(CentralEmailService::class);
        $mailer->shouldReceive('send')->andThrow(new RuntimeException('provider down'));
        $this->app->instance(CentralEmailService::class, $mailer);

        $this->actingAs($user)
            ->withSession(['current_workspace_id' => $workspace->id])
            ->from(route('workspace.finance.invoices.show', $invoice))
            ->post(route('workspace.finance.invoices.send', $invoice), [
                'email' => $customer->email,
                'attach_pdf' => '1',
            ])
            ->assertRedirect()
            ->assertSessionHas('error');

        $delivery = FinanceDocumentDelivery::withoutGlobalScopes()->firstOrFail();
        $this->assertSame('failed', $delivery->status);
        $this->assertNotNull($delivery->error);
        $this->assertNull($delivery->sent_at);
        $this->assertSame(0, AuditLog::withoutGlobalScopes()->where('action', 'invoice_sent')->count());
        $this->assertSame($before, $this->financialSnapshot($invoice->fresh()));
        $this->assertSame($journalCount, FinanceJournalEntry::withoutGlobalScopes()->count());
        $this->assertSame($snapshotCount, IssuedDocumentSnapshot::withoutGlobalScopes()->count());
        $this->assertSame(0, FinanceInvoicePayment::withoutGlobalScopes()->count());
    }

    public function test_draft_and_cancelled_invoices_cannot_be_sent(): void
    {
        [$user, $workspace] = $this->createWorkspaceOwner('company');
        $customer = $this->makeCustomer($workspace, 'Lifecycle Invoice Customer');
        app(FinanceBootstrapService::class)->ensureWorkspaceFinanceSetup($workspace);
        $draft = app(InvoiceService::class)->create($workspace, $this->invoicePayload($customer->id), (int) $user->id);
        $this->fakeSuccessfulMailer();

        $this->actingAs($user)->withSession(['current_workspace_id' => $workspace->id])
            ->from(route('workspace.finance.invoices.show', $draft))
            ->post(route('workspace.finance.invoices.send', $draft), [
                'email' => $customer->email,
                'attach_pdf' => '1',
            ])
            ->assertRedirect()
            ->assertSessionHas('error');

        $issued = app(InvoiceService::class)->issue($draft, (int) $user->id);
        app(InvoiceService::class)->cancel($issued, (int) $user->id);

        $this->actingAs($user)->withSession(['current_workspace_id' => $workspace->id])
            ->from(route('workspace.finance.invoices.show', $issued))
            ->post(route('workspace.finance.invoices.send', $issued), [
                'email' => $customer->email,
                'attach_pdf' => '1',
            ])
            ->assertRedirect()
            ->assertSessionHas('error');

        $this->assertSame(0, FinanceDocumentDelivery::withoutGlobalScopes()->where('status', 'sent')->count());
        $this->assertSame(0, AuditLog::withoutGlobalScopes()->where('action', 'invoice_sent')->count());
    }

    public function test_missing_customer_email_is_rejected(): void
    {
        [$user, $workspace, $invoice] = $this->issuedInvoice();
        $this->fakeSuccessfulMailer();

        $this->actingAs($user)
            ->withSession(['current_workspace_id' => $workspace->id])
            ->from(route('workspace.finance.invoices.show', $invoice))
            ->post(route('workspace.finance.invoices.send', $invoice), [
                'email' => '',
                'attach_pdf' => '1',
            ])
            ->assertSessionHasErrors('email');

        $this->actingAs($user)
            ->withSession(['current_workspace_id' => $workspace->id])
            ->from(route('workspace.finance.invoices.show', $invoice))
            ->post(route('workspace.finance.invoices.send', $invoice), [
                'email' => 'not-an-email',
                'attach_pdf' => '1',
            ])
            ->assertSessionHasErrors('email');

        $this->assertSame(0, FinanceDocumentDelivery::withoutGlobalScopes()->count());
        $this->assertSame('issued', $invoice->fresh()->resolvedInvoiceStatus());
    }

    public function test_send_route_is_post_only_and_show_page_exposes_send_ui_for_issued_invoices(): void
    {
        [$user, $workspace, $invoice, $customer] = $this->issuedInvoice();
        $route = Route::getRoutes()->getByName('workspace.finance.invoices.send');
        $this->assertNotNull($route);
        $this->assertContains('POST', $route->methods());
        $this->assertNotContains('GET', $route->methods());

        $this->actingAs($user)->withSession(['current_workspace_id' => $workspace->id])
            ->get(route('workspace.finance.invoices.show', $invoice))
            ->assertOk()
            ->assertSee('إرسال بالبريد الإلكتروني')
            ->assertSee('إرسال عبر البريد')
            ->assertSee($customer->email)
            ->assertSee('سجل الإرسال')
            ->assertSee('إرفاق ملف PDF')
            ->assertSee('حالة المستند')
            ->assertDontSee('WhatsApp', false);

        $draftCustomer = $this->makeCustomer($workspace, 'Draft Show Customer');
        $draft = app(InvoiceService::class)->create($workspace, $this->invoicePayload($draftCustomer->id), (int) $user->id);
        $this->actingAs($user)->withSession(['current_workspace_id' => $workspace->id])
            ->get(route('workspace.finance.invoices.show', $draft))
            ->assertOk()
            ->assertSee('يجب إصدار الفاتورة قبل إرسالها بالبريد')
            ->assertDontSee('إرسال عبر البريد');
    }

    /**
     * @param  array<int, array<string, mixed>>  $payloads
     */
    private function fakeSuccessfulMailer(array &$payloads = []): void
    {
        $mailer = Mockery::mock(CentralEmailService::class);
        $mailer->shouldReceive('send')->andReturnUsing(function (array $payload) use (&$payloads): EmailLog {
            $payloads[] = $payload;
            $to = $payload['to'] ?? [];
            $recipient = is_array($to) ? implode(', ', $to) : (string) $to;

            return EmailLog::query()->create([
                'workspace_id' => $payload['workspace_id'] ?? null,
                'template' => $payload['template'] ?? 'invoice_email',
                'recipient' => $recipient,
                'subject' => $payload['subject'] ?? null,
                'status' => 'sent',
                'provider' => 'test',
                'provider_message_id' => 'msg-'.uniqid(),
                'sent_at' => now(),
                'meta' => $payload['meta'] ?? null,
            ]);
        });
        $this->app->instance(CentralEmailService::class, $mailer);
    }

    private function forbidWhatsApp(): void
    {
        $whatsapp = Mockery::mock(WhatsAppOutboundService::class);
        $whatsapp->shouldNotReceive('sendText');
        $this->app->instance(WhatsAppOutboundService::class, $whatsapp);
    }

    /**
     * @return array{0: User, 1: Workspace, 2: FinanceInvoice, 3: Customer}
     */
    private function issuedInvoice(): array
    {
        [$user, $workspace] = $this->createWorkspaceOwner('company');
        app(FinanceBootstrapService::class)->ensureWorkspaceFinanceSetup($workspace);
        $customer = $this->makeCustomer($workspace, 'Send Invoice Customer');
        $payload = $this->invoicePayload($customer->id);
        $payload['invoice_status'] = 'issued';
        $invoice = app(InvoiceService::class)->create($workspace, $payload, (int) $user->id);

        return [$user, $workspace, $invoice, $customer];
    }

    /**
     * @return array<string, mixed>
     */
    private function invoicePayload(int $customerId): array
    {
        return [
            'type' => 'sales',
            'customer_id' => $customerId,
            'issue_date' => now()->toDateString(),
            'due_date' => now()->addDays(10)->toDateString(),
            'currency' => 'SAR',
            'invoice_status' => 'draft',
            'tax_profile_type' => 'standard',
            'tax_rate' => 15,
            'items' => [[
                'product_name' => 'خدمة فوترة',
                'quantity' => 1,
                'unit_price' => 100,
                'discount' => 0,
                'tax_rate' => 15,
                'tax_type' => 'standard',
            ]],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function financialSnapshot(FinanceInvoice $invoice): array
    {
        $fresh = $invoice->fresh();

        return [
            'total' => (string) $fresh->total,
            'subtotal' => (string) $fresh->subtotal,
            'tax_amount' => (string) $fresh->tax_amount,
            'amount_paid' => (string) $fresh->amount_paid,
            'amount_due' => (string) $fresh->amount_due,
            'invoice_status' => $fresh->resolvedInvoiceStatus(),
            'payment_status' => $fresh->payment_status,
            'status' => $fresh->status,
            'invoice_number' => $fresh->invoice_number,
        ];
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    private function makeCustomer(Workspace $workspace, string $name, array $attributes = []): Customer
    {
        return Customer::withoutGlobalScopes()->create(array_merge([
            'workspace_id' => $workspace->id,
            'name' => $name,
            'phone' => '05'.random_int(10000000, 99999999),
            'email' => strtolower(str_replace(' ', '.', $name)).'@example.com',
        ], $attributes));
    }

    /**
     * @param  array<int, string>  $permissions
     */
    private function attachStaff(Workspace $workspace, array $permissions): User
    {
        $user = User::factory()->create();
        $workspace->users()->attach($user->id, [
            'membership_role' => 'agent',
            'status' => 'active',
            'joined_at' => now(),
        ]);
        app(PermissionRegistrar::class)->setPermissionsTeamId($workspace->id);
        $user->givePermissionTo($permissions);

        return $user;
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
        foreach (['finance', 'products', 'orders', 'customers'] as $feature) {
            WorkspaceFeatureFlag::withoutGlobalScopes()->updateOrCreate(
                ['workspace_id' => $workspace->id, 'feature_key' => $feature],
                ['workspace_id' => $workspace->id, 'feature_key' => $feature, 'enabled' => true, 'source' => 'manual']
            );
        }
        $plan = Plan::query()
            ->where('workspace_type', $workspaceType)
            ->where('code', $workspaceType.'_pro')
            ->first()
            ?? Plan::query()
                ->where('workspace_type', $workspaceType)
                ->where('is_active', true)
                ->orderByDesc('price')
                ->first();
        if ($plan) {
            Subscription::withoutGlobalScopes()->create([
                'workspace_id' => $workspace->id,
                'plan_id' => $plan->id,
                'status' => 'active',
                'starts_at' => now(),
                'current_period_start' => now(),
                'current_period_end' => now()->addMonth(),
            ]);
        }

        return [$user, $workspace];
    }
}
