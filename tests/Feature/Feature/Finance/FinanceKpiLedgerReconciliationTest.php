<?php

namespace Tests\Feature\Feature\Finance;

use App\Enums\Finance\TaxProfileType;
use App\Models\Customer;
use App\Models\Finance\FinanceAccount;
use App\Models\Finance\FinanceInvoice;
use App\Models\Plan;
use App\Models\Subscription;
use App\Models\User;
use App\Models\Workspace;
use App\Services\Finance\AccountingService;
use App\Services\Finance\CreditNoteService;
use App\Services\Finance\ExpenseService;
use App\Services\Finance\FinanceAccountingKpiService;
use App\Services\Finance\FinanceAnalyticsService;
use App\Services\Finance\FinanceBootstrapService;
use App\Services\Finance\InvoicePaymentService;
use App\Services\Finance\InvoiceService;
use App\Services\Finance\PeriodComparisonService;
use App\Support\Money\Money;
use App\Support\Tenancy\WorkspaceContext;
use Database\Seeders\FoundationSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Laravel\Sanctum\Sanctum;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

class FinanceKpiLedgerReconciliationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Notification::fake();
        $this->seed(FoundationSeeder::class);
    }

    public function test_zero_workspace_accounting_kpis_are_zero(): void
    {
        [$owner, $workspace] = $this->createWorkspaceOwner('Empty KPI');
        $from = now()->startOfMonth()->toDateString();
        $to = now()->endOfMonth()->toDateString();

        $analytics = $this->analytics($workspace, $from, $to);
        $ledger = $this->ledger($workspace, $from, $to);

        $this->assertSame('0.00', $ledger['revenue']);
        $this->assertSame('0.00', $ledger['expenses']);
        $this->assertSame('0.00', $ledger['net_profit']);
        $this->assertAccountingHeroMatchesLedger($analytics, $ledger);
    }

    public function test_revenue_expenses_and_net_profit_equal_posted_ledger(): void
    {
        [$owner, $workspace] = $this->createWorkspaceOwner('Ledger A');
        $customer = $this->customer($workspace, 'A Buyer');
        $from = now()->startOfMonth()->toDateString();
        $to = now()->endOfMonth()->toDateString();

        $this->issueSales($workspace, $customer, (int) $owner->id, '10000.00');
        $this->postExpense($workspace, (int) $owner->id, '4000.00');

        $analytics = $this->analytics($workspace, $from, $to);
        $ledger = $this->ledger($workspace, $from, $to);

        $this->assertSame('10000.00', $ledger['revenue']);
        $this->assertSame('4000.00', $ledger['expenses']);
        $this->assertSame('6000.00', $ledger['net_profit']);
        $this->assertAccountingHeroMatchesLedger($analytics, $ledger);
        $this->assertSame('10000.00', $analytics['ledger_profit']['revenue']);
        $this->assertSame('6000.00', $analytics['ledger_profit']['net_profit']);
    }

    public function test_draft_invoice_does_not_change_accounting_kpis(): void
    {
        [$owner, $workspace] = $this->createWorkspaceOwner('Draft KPI');
        $customer = $this->customer($workspace, 'Draft Buyer');
        $from = now()->startOfMonth()->toDateString();
        $to = now()->endOfMonth()->toDateString();

        $this->issueSales($workspace, $customer, (int) $owner->id, '10000.00');
        $before = $this->ledger($workspace, $from, $to);

        $this->draftSales($workspace, $customer, (int) $owner->id, '5000.00');
        $after = $this->analytics($workspace, $from, $to);

        $this->assertSame($before['revenue'], $after['hero'][0]['value']);
        $this->assertSame($before['net_profit'], $after['hero'][1]['value']);
        $this->assertSame('10000.00', $after['hero'][0]['value']);
    }

    public function test_invoice_reversal_removes_revenue_from_accounting_kpis(): void
    {
        [$owner, $workspace] = $this->createWorkspaceOwner('Reverse KPI');
        $customer = $this->customer($workspace, 'Reverse Buyer');
        $from = now()->startOfMonth()->toDateString();
        $to = now()->endOfMonth()->toDateString();

        $invoice = $this->issueSales($workspace, $customer, (int) $owner->id, '10000.00');
        $this->assertSame('10000.00', $this->ledger($workspace, $from, $to)['revenue']);

        app(WorkspaceContext::class)->set($workspace);
        app(InvoiceService::class)->cancel($invoice, (int) $owner->id);

        $after = $this->analytics($workspace, $from, $to);
        $this->assertSame('0.00', $after['hero'][0]['value']);
        $this->assertSame('0.00', $after['ledger_profit']['revenue']);
        $this->assertSame('0.00', $after['hero'][1]['value']);
    }

    public function test_credit_note_reduces_ledger_revenue_not_invoice_row_total(): void
    {
        [$owner, $workspace] = $this->createWorkspaceOwner('Credit KPI');
        $customer = $this->customer($workspace, 'Credit Buyer');
        $from = now()->startOfMonth()->toDateString();
        $to = now()->endOfMonth()->toDateString();

        $invoice = $this->issueSales($workspace, $customer, (int) $owner->id, '10000.00');
        $this->issueCredit($workspace, $invoice, (int) $owner->id, '2000.00');

        $invoice->refresh();
        $this->assertSame('10000.00', Money::of($invoice->taxable_amount));

        $analytics = $this->analytics($workspace, $from, $to);
        $this->assertSame('8000.00', $analytics['hero'][0]['value']);
        $this->assertSame('8000.00', $analytics['ledger_profit']['revenue']);
        $this->assertSame('8000.00', $this->ledger($workspace, $from, $to)['revenue']);
    }

    public function test_date_boundaries_match_ledger_entry_date(): void
    {
        [$owner, $workspace] = $this->createWorkspaceOwner('Boundary KPI');
        $customer = $this->customer($workspace, 'Boundary Buyer');

        $this->issueSales($workspace, $customer, (int) $owner->id, '100.00', '2026-01-01');
        $this->issueSales($workspace, $customer, (int) $owner->id, '30.00', '2026-01-31');
        $this->issueSales($workspace, $customer, (int) $owner->id, '7.00', '2025-12-31');
        $this->issueSales($workspace, $customer, (int) $owner->id, '9.00', '2026-02-01');

        $january = $this->analytics($workspace, '2026-01-01', '2026-01-31');
        $this->assertSame('130.00', $january['hero'][0]['value']);
        $this->assertSame('130.00', $this->ledger($workspace, '2026-01-01', '2026-01-31')['revenue']);

        $before = $this->analytics($workspace, '2025-12-31', '2025-12-31');
        $this->assertSame('7.00', $before['hero'][0]['value']);

        $after = $this->analytics($workspace, '2026-02-01', '2026-02-01');
        $this->assertSame('9.00', $after['hero'][0]['value']);

        $year = $this->analytics($workspace, '2026-01-01', '2026-12-31');
        $this->assertSame('139.00', $year['hero'][0]['value']);
    }

    public function test_workspace_a_kpis_exclude_workspace_b(): void
    {
        [$ownerA, $workspaceA] = $this->createWorkspaceOwner('KPI A');
        [$ownerB, $workspaceB] = $this->createWorkspaceOwner('KPI B');
        $from = now()->startOfMonth()->toDateString();
        $to = now()->endOfMonth()->toDateString();

        $this->issueSales($workspaceA, $this->customer($workspaceA, 'A Cust'), (int) $ownerA->id, '10000.00');
        $this->postExpense($workspaceA, (int) $ownerA->id, '4000.00');
        $this->issueSales($workspaceB, $this->customer($workspaceB, 'B Cust'), (int) $ownerB->id, '333.00');
        $this->postExpense($workspaceB, (int) $ownerB->id, '111.00');

        $a = $this->analytics($workspaceA, $from, $to);
        $b = $this->analytics($workspaceB, $from, $to);
        $periodsA = app(PeriodComparisonService::class)->compare((int) $workspaceA->id);
        $periodsB = app(PeriodComparisonService::class)->compare((int) $workspaceB->id);

        $this->assertSame('10000.00', $a['hero'][0]['value']);
        $this->assertSame('4000.00', $a['secondary'][0]['value']);
        $this->assertSame('6000.00', $a['hero'][1]['value']);
        $this->assertSame('333.00', $b['hero'][0]['value']);
        $this->assertSame('111.00', $b['secondary'][0]['value']);
        $this->assertSame('222.00', $b['hero'][1]['value']);
        $this->assertSame('10000.00', $periodsA['this_month']['revenue']);
        $this->assertSame('333.00', $periodsB['this_month']['revenue']);
    }

    public function test_web_api_and_flutter_dashboard_return_the_same_accounting_kpis(): void
    {
        [$owner, $workspace] = $this->createWorkspaceOwner('Surface KPI');
        $customer = $this->customer($workspace, 'Surface Buyer');
        $from = now()->startOfMonth()->toDateString();
        $to = now()->endOfMonth()->toDateString();
        $this->issueSales($workspace, $customer, (int) $owner->id, '10000.00');
        $this->postExpense($workspace, (int) $owner->id, '4000.00');

        $service = $this->analytics($workspace, $from, $to);

        $this->actingAs($owner)->withSession(['current_workspace_id' => $workspace->id])
            ->get(route('workspace.finance.dashboard', ['from' => $from, 'to' => $to]))
            ->assertOk()
            ->assertSee('10,000.00')
            ->assertSee('6,000.00')
            ->assertSee('4,000.00');

        Sanctum::actingAs($owner);
        $api = $this->withHeaders(['X-Workspace-Id' => (string) $workspace->id])
            ->getJson('/api/finance/v1/dashboard?from='.$from.'&to='.$to)
            ->assertOk();

        $hero = $api->json('data.analytics.hero');
        $this->assertSame($service['hero'][0]['value'], $hero[0]['value']);
        $this->assertSame($service['hero'][1]['value'], $hero[1]['value']);
        $this->assertSame($service['secondary'][0]['value'], $api->json('data.analytics.secondary.0.value'));
        $this->assertSame($service['ledger_profit']['revenue'], $api->json('data.analytics.ledger_profit.revenue'));
        $this->assertSame($service['ledger_profit']['net_profit'], $api->json('data.analytics.ledger_profit.net_profit'));
        $this->assertSame($service['cash_flow']['closing_cash'], $api->json('data.analytics.cash_flow.closing_cash'));
        $this->assertSame($service['hero'][0]['value'], $api->json('data.cards.sales'));
        $this->assertSame($service['hero'][1]['value'], $api->json('data.cards.net_profit'));
        $this->assertSame($service['secondary'][0]['value'], $api->json('data.cards.expenses'));
    }

    public function test_legacy_invoice_status_cannot_distort_ledger_kpis(): void
    {
        [$owner, $workspace] = $this->createWorkspaceOwner('Legacy KPI');
        $customer = $this->customer($workspace, 'Legacy Buyer');
        $from = now()->startOfMonth()->toDateString();
        $to = now()->endOfMonth()->toDateString();

        $invoice = $this->issueSales($workspace, $customer, (int) $owner->id, '10000.00');
        $before = $this->analytics($workspace, $from, $to)['hero'][0]['value'];

        FinanceInvoice::withoutGlobalScopes()->whereKey($invoice->id)->update([
            'invoice_status' => 'draft',
            'status' => 'draft',
            'total' => '99999.00',
            'taxable_amount' => '99999.00',
        ]);

        $after = $this->analytics($workspace, $from, $to);
        $this->assertSame($before, $after['hero'][0]['value']);
        $this->assertSame('10000.00', $after['hero'][0]['value']);
    }

    public function test_payment_and_journal_adjustment_move_receivables_cash_and_profit_with_ledger(): void
    {
        [$owner, $workspace] = $this->createWorkspaceOwner('Cash KPI');
        $customer = $this->customer($workspace, 'Cash Buyer');
        $from = now()->startOfMonth()->toDateString();
        $to = now()->endOfMonth()->toDateString();

        $invoice = $this->issueSales($workspace, $customer, (int) $owner->id, '10000.00');
        $this->assertSame('10000.00', $this->ledger($workspace, $from, $to)['receivables']);

        app(WorkspaceContext::class)->set($workspace);
        app(InvoicePaymentService::class)->recordPayment($invoice, [
            'amount' => 2500,
            'method' => 'cash',
            'payment_date' => now()->toDateString(),
            'reference' => 'P1-A-PAY',
        ], (int) $owner->id);

        $afterPay = $this->ledger($workspace, $from, $to);
        $this->assertSame('7500.00', $afterPay['receivables']);
        $this->assertSame('10000.00', $afterPay['revenue']);

        $cash = FinanceAccount::withoutGlobalScopes()->where('workspace_id', $workspace->id)->where('code', '1000')->firstOrFail();
        $expense = FinanceAccount::withoutGlobalScopes()->where('workspace_id', $workspace->id)->where('code', '5900')->firstOrFail();
        app(AccountingService::class)->createEntry(
            workspaceId: (int) $workspace->id,
            entryDate: now()->toDateString(),
            type: 'manual',
            lines: [
                ['account_id' => $expense->id, 'debit' => '100.00', 'credit' => 0, 'description' => 'P1-A adj'],
                ['account_id' => $cash->id, 'debit' => 0, 'credit' => '100.00', 'description' => 'P1-A adj'],
            ],
            description: 'P1-A journal adjustment',
            postedBy: (int) $owner->id,
        );

        $afterAdj = $this->analytics($workspace, $from, $to);
        $ledger = $this->ledger($workspace, $from, $to);
        $this->assertSame('100.00', $ledger['expenses']);
        $this->assertSame('9900.00', $ledger['net_profit']);
        $this->assertAccountingHeroMatchesLedger($afterAdj, $ledger);
        $this->assertSame($ledger['closing_cash'], $afterAdj['secondary'][1]['value']);
    }

    public function test_output_vat_card_stays_operational_and_is_not_treated_as_revenue(): void
    {
        [$owner, $workspace] = $this->createWorkspaceOwner('Tax KPI');
        $customer = $this->customer($workspace, 'Tax Buyer');
        $from = now()->startOfMonth()->toDateString();
        $to = now()->endOfMonth()->toDateString();

        $this->issueSales($workspace, $customer, (int) $owner->id, '100.00', now()->toDateString(), '15');

        $ledger = $this->ledger($workspace, $from, $to);
        $this->assertSame('100.00', $ledger['revenue']);

        Sanctum::actingAs($owner);
        $cards = $this->withHeaders(['X-Workspace-Id' => (string) $workspace->id])
            ->getJson('/api/finance/v1/dashboard?from='.$from.'&to='.$to)
            ->assertOk()
            ->json('data.cards');

        $this->assertSame('100.00', $cards['sales']);
        $this->assertSame('15.00', $cards['output_vat']);
        $this->assertNotEquals($cards['output_vat'], $cards['sales']);
    }

    public function test_money_edges_zero_one_minor_and_negative_reversal(): void
    {
        [$owner, $workspace] = $this->createWorkspaceOwner('Money KPI');
        $customer = $this->customer($workspace, 'Minor Buyer');
        $from = now()->startOfMonth()->toDateString();
        $to = now()->endOfMonth()->toDateString();

        $penny = $this->issueSales($workspace, $customer, (int) $owner->id, '0.01');
        $this->assertSame('0.01', $this->analytics($workspace, $from, $to)['hero'][0]['value']);

        app(WorkspaceContext::class)->set($workspace);
        app(InvoiceService::class)->cancel($penny, (int) $owner->id);
        $this->assertSame('0.00', $this->analytics($workspace, $from, $to)['hero'][0]['value']);
        $this->assertSame('0.00', $this->analytics($workspace, $from, $to)['hero'][1]['value']);
    }

    /**
     * @param  array<string, mixed>  $analytics
     * @param  array<string, string>  $ledger
     */
    private function assertAccountingHeroMatchesLedger(array $analytics, array $ledger): void
    {
        $this->assertSame($ledger['revenue'], $analytics['hero'][0]['value']);
        $this->assertSame($ledger['net_profit'], $analytics['hero'][1]['value']);
        $this->assertSame($ledger['receivables'], $analytics['hero'][2]['value']);
        $this->assertSame($ledger['payables'], $analytics['hero'][3]['value']);
        $this->assertSame($ledger['expenses'], $analytics['secondary'][0]['value']);
        $this->assertSame($ledger['closing_cash'], $analytics['secondary'][1]['value']);
    }

    /**
     * @return array<string, mixed>
     */
    private function analytics(Workspace $workspace, string $from, string $to): array
    {
        return app(FinanceAnalyticsService::class)->dashboard((int) $workspace->id, [
            'from' => $from,
            'to' => $to,
        ]);
    }

    /**
     * @return array<string, string>
     */
    private function ledger(Workspace $workspace, string $from, string $to): array
    {
        return app(FinanceAccountingKpiService::class)->period((int) $workspace->id, $from, $to);
    }

    /**
     * @return array{0: User, 1: Workspace}
     */
    private function createWorkspaceOwner(string $name): array
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

        foreach (['finance', 'products', 'orders', 'customers', 'payments'] as $feature) {
            $this->enableWorkspaceFeature($workspace, $feature);
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

    private function customer(Workspace $workspace, string $name): Customer
    {
        app(WorkspaceContext::class)->set($workspace);

        return Customer::withoutGlobalScopes()->create([
            'workspace_id' => $workspace->id,
            'name' => $name,
            'phone' => '05'.random_int(10000000, 99999999),
            'email' => strtolower(str_replace(' ', '.', $name)).uniqid('', true).'@example.com',
        ]);
    }

    private function issueSales(
        Workspace $workspace,
        Customer $customer,
        int $actorId,
        string $amount,
        ?string $issueDate = null,
        string $taxRate = '0',
    ): FinanceInvoice {
        app(WorkspaceContext::class)->set($workspace);

        return app(InvoiceService::class)->create($workspace, $this->invoiceBody($customer->id, $amount, $issueDate, $taxRate, 'issued'), $actorId);
    }

    private function draftSales(Workspace $workspace, Customer $customer, int $actorId, string $amount): FinanceInvoice
    {
        app(WorkspaceContext::class)->set($workspace);

        return app(InvoiceService::class)->create($workspace, $this->invoiceBody($customer->id, $amount, null, '0', 'draft'), $actorId);
    }

    private function postExpense(Workspace $workspace, int $actorId, string $amount): void
    {
        app(WorkspaceContext::class)->set($workspace);
        app(ExpenseService::class)->create($workspace, [
            'expense_date' => now()->toDateString(),
            'description' => 'P1-A expense',
            'amount' => $amount,
            'tax_rate' => 0,
            'tax_profile_type' => TaxProfileType::ZeroRated->value,
            'status' => 'approved',
            'payment_method' => 'cash',
        ], $actorId);
    }

    private function issueCredit(Workspace $workspace, FinanceInvoice $invoice, int $actorId, string $amount): void
    {
        app(WorkspaceContext::class)->set($workspace);
        app(CreditNoteService::class)->create($workspace, $invoice, [
            'type' => 'credit',
            'status' => 'issued',
            'issue_date' => now()->toDateString(),
            'reason' => 'P1-A credit',
            'tax_profile_type' => TaxProfileType::ZeroRated->value,
            'tax_rate' => 0,
            'items' => [[
                'product_name' => 'Credit line',
                'quantity' => 1,
                'unit_price' => $amount,
                'discount' => 0,
                'tax_rate' => 0,
                'tax_type' => TaxProfileType::ZeroRated->value,
            ]],
        ], $actorId);
    }

    /**
     * @return array<string, mixed>
     */
    private function invoiceBody(int $customerId, string $amount, ?string $issueDate, string $taxRate, string $status): array
    {
        $zero = Money::cmp($taxRate, '0') === 0;

        return [
            'type' => 'sales',
            'customer_id' => $customerId,
            'issue_date' => $issueDate ?? now()->toDateString(),
            'due_date' => now()->addDays(10)->toDateString(),
            'currency' => 'SAR',
            'invoice_status' => $status,
            'tax_profile_type' => $zero ? TaxProfileType::ZeroRated->value : TaxProfileType::Standard->value,
            'tax_rate' => $taxRate,
            'items' => [[
                'product_name' => 'P1-A line',
                'quantity' => 1,
                'unit_price' => $amount,
                'discount' => 0,
                'tax_rate' => $taxRate,
                'tax_type' => $zero ? TaxProfileType::ZeroRated->value : TaxProfileType::Standard->value,
            ]],
        ];
    }
}
