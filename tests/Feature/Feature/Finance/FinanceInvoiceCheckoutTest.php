<?php

namespace Tests\Feature\Feature\Finance;

use App\Models\Customer;
use App\Models\EmailLog;
use App\Models\Finance\FinanceInvoice;
use App\Models\Finance\FinanceInvoicePayment;
use App\Models\Finance\FinanceJournalEntry;
use App\Models\Finance\FinanceReceipt;
use App\Models\MerchantProfile;
use App\Models\Order;
use App\Models\Payment;
use App\Models\Plan;
use App\Models\Product;
use App\Models\Subscription;
use App\Models\User;
use App\Models\Workspace;
use App\Models\WorkspaceFeatureFlag;
use App\Services\Email\CentralEmailService;
use App\Services\Finance\FinanceBootstrapService;
use App\Services\Finance\InvoicePaymentService;
use App\Services\Finance\InvoiceService;
use App\Services\Payment\PaymentService;
use App\Services\WhatsApp\WhatsAppOutboundService;
use Database\Seeders\FoundationSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Mockery;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

class FinanceInvoiceCheckoutTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Notification::fake();
        $this->seed(FoundationSeeder::class);
        config()->set('payment.providers.local.webhook_secret', 'finance_checkout_secret');
        config()->set('payment.providers.local.webhook_tolerance_seconds', 300);
    }

    public function test_eligible_invoice_can_generate_payment_link_without_marking_paid_or_creating_order(): void
    {
        [$user, $workspace, $invoice] = $this->issuedSalesInvoice();
        $this->enableMerchantPayments($workspace);

        $this->actingAs($user)
            ->withSession(['current_workspace_id' => $workspace->id])
            ->get(route('workspace.finance.invoices.show', $invoice))
            ->assertOk()
            ->assertSee('الدفع الإلكتروني')
            ->assertSee('إنشاء رابط الدفع الإلكتروني');

        $this->assertSame(0, Payment::withoutGlobalScopes()->count());
        $this->assertSame(0, Order::query()->count());

        $this->actingAs($user)
            ->withSession(['current_workspace_id' => $workspace->id])
            ->post(route('workspace.finance.invoices.checkout', $invoice))
            ->assertRedirect(route('workspace.finance.invoices.show', $invoice))
            ->assertSessionHas('success');

        $payment = Payment::withoutGlobalScopes()->firstOrFail();
        $this->assertNull($payment->order_id);
        $this->assertSame(Payment::BILLABLE_FINANCE_INVOICE, $payment->billable_type);
        $this->assertSame($invoice->id, (int) $payment->billable_id);
        $this->assertSame($workspace->id, (int) $payment->workspace_id);
        $this->assertSame(round((float) $invoice->amount_due, 2), (float) $payment->amount);
        $this->assertSame('SAR', strtoupper((string) $payment->currency));
        $this->assertSame(PaymentService::financeCheckoutReference((int) $workspace->id, (int) $invoice->id), $payment->checkout_reference);
        $this->assertSame('pending', $payment->status);
        $this->assertNotEmpty($payment->payment_link);
        $this->assertSame(0, Order::query()->count());
        $this->assertSame('unpaid', $invoice->fresh()->payment_status);
        $this->assertGreaterThan(0, (float) $invoice->fresh()->amount_due);
        $this->assertSame(0, FinanceInvoicePayment::withoutGlobalScopes()->count());

        $show = $this->actingAs($user)
            ->withSession(['current_workspace_id' => $workspace->id])
            ->get(route('workspace.finance.invoices.show', $invoice))
            ->assertOk()
            ->assertSee('نسخ')
            ->assertSee('فتح الرابط', false)
            ->getContent();

        $this->assertStringContainsString($payment->payment_link, $show);
        $this->assertStringContainsString('الدفع الإلكتروني', $show);
    }

    public function test_generating_checkout_twice_reuses_pending_payment(): void
    {
        [$user, $workspace, $invoice] = $this->issuedSalesInvoice();
        $this->enableMerchantPayments($workspace);

        $this->actingAs($user)
            ->withSession(['current_workspace_id' => $workspace->id])
            ->post(route('workspace.finance.invoices.checkout', $invoice))
            ->assertRedirect();

        $this->actingAs($user)
            ->withSession(['current_workspace_id' => $workspace->id])
            ->post(route('workspace.finance.invoices.checkout', $invoice))
            ->assertRedirect();

        $this->assertSame(1, Payment::withoutGlobalScopes()->count());
    }

    public function test_verified_webhook_posts_finance_payment_gl_and_receipt_once(): void
    {
        [$user, $workspace, $invoice] = $this->issuedSalesInvoice();
        $this->enableMerchantPayments($workspace);

        $this->actingAs($user)
            ->withSession(['current_workspace_id' => $workspace->id])
            ->post(route('workspace.finance.invoices.checkout', $invoice));

        $payment = Payment::withoutGlobalScopes()->firstOrFail();
        $due = round((float) $invoice->amount_due, 2);
        $glBefore = FinanceJournalEntry::withoutGlobalScopes()->where('type', 'invoice_payment')->count();

        $this->postLocalPaidWebhook($payment->checkout_reference, 'evt_fin_1', $due, 'SAR');

        $invoice->refresh();
        $payment->refresh();
        $financePayment = FinanceInvoicePayment::withoutGlobalScopes()->firstOrFail();
        $receipt = FinanceReceipt::withoutGlobalScopes()->firstOrFail();

        $this->assertSame('paid', $payment->status);
        $this->assertSame($due, (float) $invoice->amount_paid);
        $this->assertEqualsWithDelta(0, (float) $invoice->amount_due, 0.009);
        $this->assertSame('paid', $invoice->payment_status);
        $this->assertSame($due, (float) $financePayment->amount);
        $this->assertSame('checkout:'.$payment->id, $financePayment->reference);
        $this->assertSame($financePayment->id, $receipt->payment_id);
        $this->assertSame(1, FinanceInvoicePayment::withoutGlobalScopes()->count());
        $this->assertSame(1, FinanceReceipt::withoutGlobalScopes()->count());
        $this->assertSame(
            $glBefore + 1,
            FinanceJournalEntry::withoutGlobalScopes()->where('type', 'invoice_payment')->count()
        );
        $this->assertSame(0, Order::query()->count());
    }

    public function test_duplicate_webhook_is_idempotent(): void
    {
        [$user, $workspace, $invoice] = $this->issuedSalesInvoice();
        $this->enableMerchantPayments($workspace);

        $this->actingAs($user)
            ->withSession(['current_workspace_id' => $workspace->id])
            ->post(route('workspace.finance.invoices.checkout', $invoice));

        $payment = Payment::withoutGlobalScopes()->firstOrFail();
        $due = round((float) $invoice->amount_due, 2);

        $this->postLocalPaidWebhook($payment->checkout_reference, 'evt_fin_dup', $due, 'SAR');
        $this->postLocalPaidWebhook($payment->checkout_reference, 'evt_fin_dup', $due, 'SAR');

        $this->assertSame(1, FinanceInvoicePayment::withoutGlobalScopes()->count());
        $this->assertSame(1, FinanceReceipt::withoutGlobalScopes()->count());
        $this->assertSame(1, FinanceJournalEntry::withoutGlobalScopes()->where('type', 'invoice_payment')->count());
        $this->assertSame(1, Payment::withoutGlobalScopes()->where('status', 'paid')->count());
    }

    public function test_invalid_webhook_cannot_settle_invoice(): void
    {
        [$user, $workspace, $invoice] = $this->issuedSalesInvoice();
        $this->enableMerchantPayments($workspace);

        $this->actingAs($user)
            ->withSession(['current_workspace_id' => $workspace->id])
            ->post(route('workspace.finance.invoices.checkout', $invoice));

        $payment = Payment::withoutGlobalScopes()->firstOrFail();
        $payload = [
            'event_id' => 'evt_bad_sig',
            'status' => 'paid',
            'reference' => $payment->checkout_reference,
            'amount' => (float) $payment->amount,
            'currency' => 'SAR',
        ];
        $rawBody = json_encode($payload, JSON_UNESCAPED_SLASHES);
        $timestamp = time();

        app(PaymentService::class)->processWebhook('local', [
            'x-webhook-timestamp' => (string) $timestamp,
            'x-webhook-signature' => 'not-valid',
        ], $payload, $rawBody);

        $this->assertSame('pending', $payment->fresh()->status);
        $this->assertSame('unpaid', $invoice->fresh()->payment_status);
        $this->assertSame(0, FinanceInvoicePayment::withoutGlobalScopes()->count());
    }

    public function test_cancelled_invoice_cannot_be_settled_by_webhook(): void
    {
        [$user, $workspace, $invoice] = $this->issuedSalesInvoice();
        $this->enableMerchantPayments($workspace);

        $this->actingAs($user)
            ->withSession(['current_workspace_id' => $workspace->id])
            ->post(route('workspace.finance.invoices.checkout', $invoice));

        $payment = Payment::withoutGlobalScopes()->firstOrFail();

        $this->actingAs($user)
            ->withSession(['current_workspace_id' => $workspace->id])
            ->post(route('workspace.finance.invoices.cancel', $invoice))
            ->assertRedirect();

        $this->assertTrue($invoice->fresh()->isCancelled());

        $this->postLocalPaidWebhook($payment->checkout_reference, 'evt_cancelled', (float) $payment->amount, 'SAR');

        $this->assertSame('pending', $payment->fresh()->status);
        $this->assertSame(0, FinanceInvoicePayment::withoutGlobalScopes()->count());
        $this->assertSame('cancelled', $invoice->fresh()->invoice_status);
    }

    public function test_wrong_amount_cannot_settle_invoice(): void
    {
        [$user, $workspace, $invoice] = $this->issuedSalesInvoice();
        $this->enableMerchantPayments($workspace);

        $this->actingAs($user)
            ->withSession(['current_workspace_id' => $workspace->id])
            ->post(route('workspace.finance.invoices.checkout', $invoice));

        $payment = Payment::withoutGlobalScopes()->firstOrFail();
        $this->postLocalPaidWebhook($payment->checkout_reference, 'evt_wrong_amt', 9999.00, 'SAR');

        $this->assertSame('pending', $payment->fresh()->status);
        $this->assertSame('unpaid', $invoice->fresh()->payment_status);
        $this->assertSame(0, FinanceInvoicePayment::withoutGlobalScopes()->count());
    }

    public function test_wrong_reference_cannot_settle_invoice(): void
    {
        [$user, $workspace, $invoice] = $this->issuedSalesInvoice();
        $this->enableMerchantPayments($workspace);

        $this->actingAs($user)
            ->withSession(['current_workspace_id' => $workspace->id])
            ->post(route('workspace.finance.invoices.checkout', $invoice));

        $this->postLocalPaidWebhook('fininv:0:0', 'evt_wrong_ref', 115, 'SAR');

        $this->assertSame('pending', Payment::withoutGlobalScopes()->firstOrFail()->status);
        $this->assertSame('unpaid', $invoice->fresh()->payment_status);
        $this->assertSame(0, FinanceInvoicePayment::withoutGlobalScopes()->count());
    }

    public function test_checkout_is_workspace_isolated(): void
    {
        [$userA, $workspaceA, $invoiceA] = $this->issuedSalesInvoice('Alpha Customer');
        $this->enableMerchantPayments($workspaceA);
        [$userB, $workspaceB] = $this->createWorkspaceOwner('company');

        $this->actingAs($userB)
            ->withSession(['current_workspace_id' => $workspaceB->id])
            ->post(route('workspace.finance.invoices.checkout', $invoiceA))
            ->assertNotFound();

        $this->actingAs($userB)
            ->withSession(['current_workspace_id' => $workspaceB->id])
            ->get(route('workspace.finance.invoices.show', $invoiceA))
            ->assertNotFound();

        $this->assertSame(0, Payment::withoutGlobalScopes()->count());
        $this->assertSame($workspaceA->id, (int) $invoiceA->fresh()->workspace_id);
    }

    public function test_agent_without_payments_manage_cannot_generate_checkout(): void
    {
        [$owner, $workspace, $invoice] = $this->issuedSalesInvoice();
        $this->enableMerchantPayments($workspace);
        $agent = $this->attachStaff($workspace, ['invoices.view']);

        $this->actingAs($agent)
            ->withSession(['current_workspace_id' => $workspace->id])
            ->post(route('workspace.finance.invoices.checkout', $invoice))
            ->assertForbidden();

        $this->assertSame(0, Payment::withoutGlobalScopes()->count());
        $this->assertSame($owner->id, (int) $workspace->owner_user_id);
    }

    public function test_invoice_email_includes_existing_payment_link(): void
    {
        [$user, $workspace, $invoice, $customer] = $this->issuedSalesInvoice('Email Link Customer', true);
        $this->enableMerchantPayments($workspace);
        $payloads = [];
        $this->fakeSuccessfulMailer($payloads);
        $this->forbidWhatsApp();

        $this->actingAs($user)
            ->withSession(['current_workspace_id' => $workspace->id])
            ->post(route('workspace.finance.invoices.checkout', $invoice));

        $payment = Payment::withoutGlobalScopes()->firstOrFail();

        $this->actingAs($user)
            ->withSession(['current_workspace_id' => $workspace->id])
            ->post(route('workspace.finance.invoices.send', $invoice), [
                'email' => $customer->email,
                'attach_pdf' => '0',
            ])
            ->assertRedirect();

        $this->assertCount(1, $payloads);
        $lines = implode("\n", $payloads[0]['data']['lines'] ?? []);
        $this->assertStringContainsString($payment->payment_link, $lines);
        $this->assertSame($payment->payment_link, $payloads[0]['data']['action_url'] ?? null);
        $this->assertSame('unpaid', $invoice->fresh()->payment_status);
    }

    public function test_manual_payment_still_works_alongside_checkout(): void
    {
        [$user, $workspace, $invoice] = $this->issuedSalesInvoice();
        $this->enableMerchantPayments($workspace);

        $this->actingAs($user)
            ->withSession(['current_workspace_id' => $workspace->id])
            ->post(route('workspace.finance.invoices.payments.store', $invoice), [
                'amount' => 50,
                'method' => 'cash',
                'payment_date' => now()->toDateString(),
                'reference' => 'MANUAL-1',
            ])
            ->assertRedirect();

        $invoice->refresh();
        $this->assertSame(50.0, (float) $invoice->amount_paid);
        $this->assertSame(1, FinanceReceipt::withoutGlobalScopes()->count());

        app(InvoicePaymentService::class)->reversePayment(
            FinanceInvoicePayment::withoutGlobalScopes()->firstOrFail(),
            (int) $user->id
        );

        $this->assertSame('voided', FinanceReceipt::withoutGlobalScopes()->firstOrFail()->status);
        $this->assertGreaterThan(0, (float) $invoice->fresh()->amount_due);
    }

    public function test_order_payment_link_and_webhook_still_work(): void
    {
        [$user, $workspace] = $this->createWorkspaceOwner('company');
        $this->enableMerchantPayments($workspace);

        $product = Product::withoutGlobalScopes()->create([
            'workspace_id' => $workspace->id,
            'name' => 'Checkout Shoe',
            'slug' => 'checkout-shoe',
            'sku' => 'SKU-CHK-1',
            'price' => 80,
            'currency' => 'USD',
            'stock' => 5,
            'status' => 'active',
        ]);

        $token = $user->createToken('api')->plainTextToken;
        $orderResponse = $this->withToken($token)
            ->withHeader('X-Workspace-Id', (string) $workspace->id)
            ->postJson('/api/orders', [
                'currency' => 'USD',
                'items' => [['product_id' => $product->id, 'quantity' => 1]],
            ])
            ->assertCreated();

        $orderId = $orderResponse->json('data.id');
        $orderNumber = $orderResponse->json('data.order_number');

        $this->withToken($token)
            ->withHeader('X-Workspace-Id', (string) $workspace->id)
            ->postJson('/api/payments', ['order_id' => $orderId])
            ->assertCreated();

        $orderPayment = Payment::withoutGlobalScopes()->where('order_id', $orderId)->firstOrFail();
        $this->assertSame($orderNumber, $orderPayment->checkout_reference);
        $this->assertNull($orderPayment->billable_type);

        $this->postLocalPaidWebhook($orderNumber, 'evt_order_still_works', 80, 'USD');

        $this->assertDatabaseHas('orders', [
            'id' => $orderId,
            'payment_status' => 'paid',
        ]);
        $this->assertSame('paid', $orderPayment->fresh()->status);
        $this->assertSame(0, FinanceInvoicePayment::withoutGlobalScopes()->count());
    }

    public function test_draft_invoice_cannot_generate_checkout(): void
    {
        [$user, $workspace] = $this->createWorkspaceOwner('company');
        $this->enableMerchantPayments($workspace);
        app(FinanceBootstrapService::class)->ensureWorkspaceFinanceSetup($workspace);
        $customer = $this->makeCustomer($workspace, 'Draft Customer');
        $invoice = app(InvoiceService::class)->create($workspace, $this->invoicePayload($customer->id), (int) $user->id);

        $this->actingAs($user)
            ->withSession(['current_workspace_id' => $workspace->id])
            ->post(route('workspace.finance.invoices.checkout', $invoice))
            ->assertRedirect()
            ->assertSessionHas('error');

        $this->assertSame(0, Payment::withoutGlobalScopes()->count());
        $this->assertTrue($invoice->fresh()->isDraft());
    }

    /**
     * @return array{0: User, 1: Workspace, 2: FinanceInvoice}|array{0: User, 1: Workspace, 2: FinanceInvoice, 3: Customer}
     */
    private function issuedSalesInvoice(string $customerName = 'Billing Customer', bool $withCustomer = false): array
    {
        [$user, $workspace] = $this->createWorkspaceOwner('company');
        app(FinanceBootstrapService::class)->ensureWorkspaceFinanceSetup($workspace);
        $customer = $this->makeCustomer($workspace, $customerName);
        $payload = $this->invoicePayload($customer->id);
        $payload['invoice_status'] = 'issued';
        $invoice = app(InvoiceService::class)->create($workspace, $payload, (int) $user->id);

        return $withCustomer
            ? [$user, $workspace, $invoice, $customer]
            : [$user, $workspace, $invoice];
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

    private function enableMerchantPayments(Workspace $workspace): void
    {
        WorkspaceFeatureFlag::withoutGlobalScopes()->updateOrCreate(
            ['workspace_id' => $workspace->id, 'feature_key' => 'payments'],
            ['workspace_id' => $workspace->id, 'feature_key' => 'payments', 'enabled' => true, 'source' => 'manual']
        );
        WorkspaceFeatureFlag::withoutGlobalScopes()->updateOrCreate(
            ['workspace_id' => $workspace->id, 'feature_key' => 'payment_gateway'],
            ['workspace_id' => $workspace->id, 'feature_key' => 'payment_gateway', 'enabled' => true, 'source' => 'manual']
        );

        $profile = MerchantProfile::withoutGlobalScopes()->firstOrCreate(
            ['workspace_id' => $workspace->id],
            ['workspace_id' => $workspace->id]
        );
        $profile->forceFill([
            'verification_status' => MerchantProfile::VERIFICATION_APPROVED,
            'provider_onboarding_status' => MerchantProfile::PROVIDER_ACTIVE,
            'approved_at' => now(),
        ])->save();
    }

    private function postLocalPaidWebhook(string $reference, string $eventId, float $amount, string $currency): void
    {
        $payload = [
            'event_id' => $eventId,
            'status' => 'paid',
            'reference' => $reference,
            'amount' => $amount,
            'currency' => $currency,
        ];
        $rawBody = json_encode($payload, JSON_UNESCAPED_SLASHES);
        $timestamp = time();
        $signature = hash_hmac('sha256', $timestamp.'.'.$rawBody, 'finance_checkout_secret');

        app(PaymentService::class)->processWebhook('local', [
            'x-webhook-timestamp' => (string) $timestamp,
            'x-webhook-signature' => $signature,
        ], $payload, $rawBody);
    }

    /**
     * @param  array<int, array<string, mixed>>  $payloads
     */
    private function fakeSuccessfulMailer(array &$payloads): void
    {
        $mailer = Mockery::mock(CentralEmailService::class);
        $mailer->shouldReceive('send')->andReturnUsing(function (array $payload) use (&$payloads) {
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
     * @param  array<string, mixed>  $attributes
     */
    private function makeCustomer(Workspace $workspace, string $name, array $attributes = []): Customer
    {
        return Customer::withoutGlobalScopes()->create(array_merge([
            'workspace_id' => $workspace->id,
            'name' => $name,
            'phone' => '05'.random_int(10000000, 99999999),
            'email' => strtolower(str_replace(' ', '.', $name)).uniqid().'@example.com',
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
        foreach (['finance', 'products', 'orders', 'customers', 'payments'] as $feature) {
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
