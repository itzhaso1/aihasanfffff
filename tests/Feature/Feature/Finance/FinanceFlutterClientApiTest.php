<?php

namespace Tests\Feature\Feature\Finance;

use App\Enums\Finance\TaxProfileType;
use App\Models\Customer;
use App\Models\Finance\FinanceInvoice;
use App\Models\Finance\FinanceInvoicePayment;
use App\Models\Finance\FinanceReceipt;
use App\Models\MerchantProfile;
use App\Models\Order;
use App\Models\Payment;
use App\Models\Plan;
use App\Models\Subscription;
use App\Models\User;
use App\Models\Workspace;
use App\Models\WorkspaceFeatureFlag;
use App\Services\Finance\FinanceBootstrapService;
use App\Services\Finance\InvoiceService;
use App\Support\Money\Money;
use App\Support\Tenancy\WorkspaceContext;
use Database\Seeders\FoundationSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Laravel\Sanctum\Sanctum;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

class FinanceFlutterClientApiTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Notification::fake();
        $this->seed(FoundationSeeder::class);
        config()->set('payment.providers.local.webhook_secret', 'finance_checkout_secret');
    }

    public function test_login_me_and_workspace_switch_return_permission_map(): void
    {
        [$owner, $workspace] = $this->createWorkspaceOwner();
        $owner->forceFill(['password' => 'password'])->save();

        $login = $this->postJson('/api/finance/v1/auth/login', [
            'email' => $owner->email,
            'password' => 'password',
            'device_type' => 'finance',
        ])->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.user.email', $owner->email)
            ->assertJsonPath('data.workspace.id', $workspace->id)
            ->assertJsonPath('data.finance_enabled', true);

        $this->assertNotEmpty($login->json('data.token'));
        $this->assertTrue((bool) ($login->json('data.permissions')['invoices.view'] ?? false));

        $me = $this->withHeaders($this->workspaceHeader($workspace) + [
            'Authorization' => 'Bearer '.$login->json('data.token'),
        ])->getJson('/api/finance/v1/auth/me')
            ->assertOk();
        $this->assertTrue((bool) ($me->json('data.permissions')['quotes.create'] ?? false));
    }

    public function test_agent_without_finance_permissions_is_forbidden(): void
    {
        [$owner, $workspace] = $this->createWorkspaceOwner();
        $customer = $this->makeBuyer($workspace, 'Forbidden Buyer');
        $this->issueSales($workspace, $customer, (int) $owner->id);

        $agent = User::factory()->create();
        $workspace->users()->attach($agent->id, [
            'membership_role' => 'agent',
            'status' => 'active',
            'joined_at' => now(),
        ]);
        Sanctum::actingAs($agent);

        $this->withHeaders($this->workspaceHeader($workspace))
            ->getJson('/api/finance/v1/sales-invoices')
            ->assertStatus(403)
            ->assertJsonPath('code', 'forbidden');

        $this->withHeaders($this->workspaceHeader($workspace))
            ->getJson('/api/finance/v1/dashboard')
            ->assertStatus(403);
    }

    public function test_wrong_workspace_cannot_read_sales_invoice(): void
    {
        [$ownerA, $workspaceA] = $this->createWorkspaceOwner('Workspace A');
        $customer = $this->makeBuyer($workspaceA, 'A Buyer');
        $invoice = $this->issueSales($workspaceA, $customer, (int) $ownerA->id);

        [$ownerB, $workspaceB] = $this->createWorkspaceOwner('Workspace B');

        Sanctum::actingAs($ownerB);
        $this->withHeaders($this->workspaceHeader($workspaceB))
            ->getJson('/api/finance/v1/sales-invoices/'.$invoice->id)
            ->assertStatus(404)
            ->assertJsonPath('code', 'not_found');
    }

    public function test_dashboard_and_customer_outstanding_come_from_server(): void
    {
        [$owner, $workspace] = $this->createWorkspaceOwner();
        $customer = $this->makeBuyer($workspace, 'Balance Buyer');
        $invoice = $this->issueSales($workspace, $customer, (int) $owner->id);
        Sanctum::actingAs($owner);
        $headers = $this->workspaceHeader($workspace);

        $this->withHeaders($headers)
            ->getJson('/api/finance/v1/dashboard')
            ->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonStructure(['data' => ['cards' => [
                'outstanding_customer_balance',
                'invoices_due',
                'overdue_invoices',
                'paid_this_period',
                'sales',
                'expenses',
                'receivables',
                'payables',
            ]]]);

        $this->withHeaders($headers)
            ->getJson('/api/finance/v1/customers/'.$customer->id)
            ->assertOk()
            ->assertJsonPath('data.outstanding_balance', Money::of($invoice->fresh()->amount_due))
            ->assertJsonMissingPath('data.balance');
    }

    public function test_quote_lifecycle_convert_creates_draft_invoice(): void
    {
        [$owner, $workspace] = $this->createWorkspaceOwner();
        $customer = $this->makeBuyer($workspace, 'Quote Buyer');
        Sanctum::actingAs($owner);
        $headers = $this->workspaceHeader($workspace);

        $create = $this->withHeaders($headers)
            ->postJson('/api/finance/v1/quotes', [
                'customer_id' => $customer->id,
                'issue_date' => now()->toDateString(),
                'expiry_date' => now()->addDays(14)->toDateString(),
                'items' => [[
                    'product_name' => 'خدمة عرض',
                    'quantity' => 1,
                    'unit_price' => 100,
                    'discount' => 0,
                    'tax_rate' => 15,
                    'tax_type' => TaxProfileType::Standard->value,
                ]],
            ])
            ->assertCreated()
            ->assertJsonPath('data.document_status', 'draft')
            ->assertJsonPath('data.outcome', 'pending');

        $quoteId = $create->json('data.id');

        $this->withHeaders($headers)
            ->postJson('/api/finance/v1/quotes/'.$quoteId.'/issue')
            ->assertOk()
            ->assertJsonPath('data.document_status', 'issued');

        $this->withHeaders($headers)
            ->postJson('/api/finance/v1/quotes/'.$quoteId.'/accept')
            ->assertOk()
            ->assertJsonPath('data.outcome', 'accepted');

        $converted = $this->withHeaders($headers)
            ->postJson('/api/finance/v1/quotes/'.$quoteId.'/convert')
            ->assertOk()
            ->assertJsonPath('data.outcome', 'converted');

        $invoiceId = $converted->json('data.converted_invoice_id');
        $this->assertNotEmpty($invoiceId);
        $invoice = FinanceInvoice::withoutGlobalScopes()->findOrFail($invoiceId);
        $this->assertSame('draft', $invoice->invoice_status);
    }

    public function test_sales_invoice_validation_issue_payment_receipt_and_reverse(): void
    {
        [$owner, $workspace] = $this->createWorkspaceOwner();
        $customer = $this->makeBuyer($workspace, 'Invoice Buyer');
        Sanctum::actingAs($owner);
        $headers = $this->workspaceHeader($workspace);

        $this->withHeaders($headers)
            ->postJson('/api/finance/v1/sales-invoices', [
                'customer_id' => $customer->id,
                'issue_date' => now()->toDateString(),
            ])
            ->assertStatus(422)
            ->assertJsonPath('code', 'validation_failed');

        $created = $this->withHeaders($headers)
            ->postJson('/api/finance/v1/sales-invoices', $this->invoiceBody($customer->id))
            ->assertCreated()
            ->assertJsonPath('data.document_status', 'draft');

        $invoiceId = $created->json('data.id');

        $this->withHeaders($headers)
            ->postJson('/api/finance/v1/sales-invoices/'.$invoiceId.'/issue')
            ->assertOk()
            ->assertJsonPath('data.document_status', 'issued')
            ->assertJsonPath('data.payment_status', 'unpaid');

        $paid = $this->withHeaders($headers)
            ->postJson('/api/finance/v1/sales-invoices/'.$invoiceId.'/payments', [
                'payment_date' => now()->toDateString(),
                'amount' => 115,
                'method' => 'cash',
                'reference' => 'CASH-1',
            ])
            ->assertOk()
            ->assertJsonPath('data.invoice.payment_status', 'paid')
            ->assertJsonPath('data.payment.status', 'posted');

        $this->assertSame(1, FinanceReceipt::withoutGlobalScopes()->count());
        $this->assertSame('posted', FinanceReceipt::withoutGlobalScopes()->first()->status);

        $paymentId = $paid->json('data.payment.id');
        $this->withHeaders($headers)
            ->postJson('/api/finance/v1/sales-invoices/'.$invoiceId.'/payments/'.$paymentId.'/reverse', [
                'reversal_reason' => 'خطأ إدخال',
            ])
            ->assertOk()
            ->assertJsonPath('data.payment.status', 'reversed')
            ->assertJsonPath('data.invoice.payment_status', 'unpaid');

        $this->assertSame('voided', FinanceReceipt::withoutGlobalScopes()->first()->fresh()->status);
    }

    public function test_checkout_returns_url_without_marking_paid(): void
    {
        [$owner, $workspace] = $this->createWorkspaceOwner();
        $customer = $this->makeBuyer($workspace, 'Checkout Buyer');
        $invoice = $this->issueSales($workspace, $customer, (int) $owner->id);
        $this->enableMerchantPayments($workspace);
        Sanctum::actingAs($owner);

        $response = $this->withHeaders($this->workspaceHeader($workspace))
            ->postJson('/api/finance/v1/sales-invoices/'.$invoice->id.'/checkout')
            ->assertOk()
            ->assertJsonPath('data.checkout.supported', true)
            ->assertJsonPath('data.invoice.payment_status', 'unpaid');

        $this->assertNotEmpty($response->json('data.checkout.checkout_url'));
        $this->assertSame(0, Order::query()->count());
        $this->assertSame('pending', Payment::withoutGlobalScopes()->first()->status);
        $this->assertSame(0, FinanceInvoicePayment::withoutGlobalScopes()->count());
        $this->assertGreaterThan(0, (float) $invoice->fresh()->amount_due);
    }

    public function test_statement_and_reports_are_server_generated(): void
    {
        [$owner, $workspace] = $this->createWorkspaceOwner();
        $customer = $this->makeBuyer($workspace, 'Statement Buyer');
        $this->issueSales($workspace, $customer, (int) $owner->id);
        Sanctum::actingAs($owner);
        $headers = $this->workspaceHeader($workspace);

        $this->withHeaders($headers)
            ->getJson('/api/finance/v1/statements?customer_id='.$customer->id.'&from='.now()->startOfMonth()->toDateString().'&to='.now()->endOfMonth()->toDateString())
            ->assertOk()
            ->assertJsonStructure(['data' => ['opening_balance', 'closing_balance', 'lines']]);

        $this->withHeaders($headers)
            ->getJson('/api/finance/v1/reports/profit-loss')
            ->assertOk()
            ->assertJsonPath('data.report', 'profit-loss');
    }

    public function test_existing_einvoice_list_contract_is_unchanged(): void
    {
        [$owner, $workspace] = $this->createWorkspaceOwner();
        $customer = $this->makeBuyer($workspace, 'Contract Buyer');
        $invoice = $this->issueSales($workspace, $customer, (int) $owner->id);
        Sanctum::actingAs($owner);

        $this->withHeaders($this->workspaceHeader($workspace))
            ->getJson('/api/finance/v1/invoices')
            ->assertOk()
            ->assertJsonPath('data.0.id', $invoice->id)
            ->assertJsonPath('data.0.document_number', $invoice->invoice_number)
            ->assertJsonPath('data.0.business_status', 'issued');
    }

    public function test_unauthenticated_finance_client_is_rejected(): void
    {
        $this->getJson('/api/finance/v1/dashboard')->assertStatus(401);
        $this->getJson('/api/finance/v1/sales-invoices')->assertStatus(401);
    }

    public function test_checkout_availability_does_not_create_a_payment(): void
    {
        [$owner, $workspace] = $this->createWorkspaceOwner();
        $customer = $this->makeBuyer($workspace, 'Availability Buyer');
        $invoice = $this->issueSales($workspace, $customer, (int) $owner->id);
        $this->enableMerchantPayments($workspace);
        Sanctum::actingAs($owner);

        $this->withHeaders($this->workspaceHeader($workspace))
            ->getJson('/api/finance/v1/sales-invoices/'.$invoice->id.'/checkout')
            ->assertOk()
            ->assertJsonPath('data.supported', true);

        $this->assertSame(0, Payment::withoutGlobalScopes()->count());
        $this->assertSame('unpaid', $invoice->fresh()->payment_status);
    }

    public function test_quote_convert_is_rejected_until_accepted(): void
    {
        [$owner, $workspace] = $this->createWorkspaceOwner();
        $customer = $this->makeBuyer($workspace, 'Blocked Convert Buyer');
        Sanctum::actingAs($owner);
        $headers = $this->workspaceHeader($workspace);

        $quoteId = $this->withHeaders($headers)
            ->postJson('/api/finance/v1/quotes', [
                'customer_id' => $customer->id,
                'issue_date' => now()->toDateString(),
                'items' => [[
                    'product_name' => 'خدمة',
                    'quantity' => 1,
                    'unit_price' => 10,
                    'discount' => 0,
                    'tax_rate' => 15,
                    'tax_type' => TaxProfileType::Standard->value,
                ]],
            ])
            ->assertCreated()
            ->json('data.id');

        $this->withHeaders($headers)
            ->postJson('/api/finance/v1/quotes/'.$quoteId.'/convert')
            ->assertStatus(422)
            ->assertJsonPath('code', 'validation_failed');
    }

    /**
     * @return array{0: User, 1: Workspace}
     */
    private function createWorkspaceOwner(string $name = 'Finance Flutter Workspace'): array
    {
        $user = User::factory()->create();
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
        app(WorkspaceContext::class)->set($workspace);

        foreach (['finance', 'pos', 'products', 'orders', 'customers', 'payments', 'payment_gateway'] as $feature) {
            WorkspaceFeatureFlag::withoutGlobalScopes()->updateOrCreate(
                ['workspace_id' => $workspace->id, 'feature_key' => $feature],
                ['workspace_id' => $workspace->id, 'feature_key' => $feature, 'enabled' => true, 'source' => 'manual']
            );
        }

        $plan = Plan::query()->where('workspace_type', 'company')->where('is_active', true)->orderByDesc('price')->first();
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

        app(FinanceBootstrapService::class)->ensureWorkspaceFinanceSetup($workspace);
        app(PermissionRegistrar::class)->setPermissionsTeamId($workspace->id);

        return [$user, $workspace->fresh()];
    }

    private function makeBuyer(Workspace $workspace, string $name): Customer
    {
        return Customer::withoutGlobalScopes()->create([
            'workspace_id' => $workspace->id,
            'name' => $name,
            'phone' => '05'.random_int(10000000, 99999999),
            'email' => strtolower(str_replace(' ', '.', $name)).uniqid().'@example.com',
            'vat_number' => '300111111111113',
        ]);
    }

    private function issueSales(Workspace $workspace, Customer $customer, int $actorId): FinanceInvoice
    {
        app(WorkspaceContext::class)->set($workspace);

        return app(InvoiceService::class)->create($workspace, array_merge($this->invoiceBody($customer->id), [
            'invoice_status' => 'issued',
        ]), $actorId);
    }

    /**
     * @return array<string, mixed>
     */
    private function invoiceBody(int $customerId): array
    {
        return [
            'type' => 'sales',
            'customer_id' => $customerId,
            'issue_date' => now()->toDateString(),
            'due_date' => now()->addDays(10)->toDateString(),
            'currency' => 'SAR',
            'invoice_status' => 'draft',
            'tax_profile_type' => TaxProfileType::Standard->value,
            'tax_rate' => 15,
            'items' => [[
                'product_name' => 'خدمة فوترة',
                'quantity' => 1,
                'unit_price' => 100,
                'discount' => 0,
                'tax_rate' => 15,
                'tax_type' => TaxProfileType::Standard->value,
            ]],
        ];
    }

    private function enableMerchantPayments(Workspace $workspace): void
    {
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

    /**
     * @return array<string, string>
     */
    private function workspaceHeader(Workspace $workspace): array
    {
        return ['X-Workspace-Id' => (string) $workspace->id];
    }
}
