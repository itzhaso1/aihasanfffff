<?php

namespace Tests\Feature\Feature\Finance;

use App\Models\Crm\CrmLead;
use App\Models\Customer;
use App\Models\Finance\FinanceAccount;
use App\Models\Finance\FinanceAccountingPeriod;
use App\Models\Finance\FinanceBankStatement;
use App\Models\Finance\FinanceFiscalYear;
use App\Models\Finance\FinanceInvoice;
use App\Models\Finance\FinanceJournalEntry;
use App\Models\Finance\FinancePurchaseOrder;
use App\Models\Finance\FinanceSupplier;
use App\Models\Finance\FinanceTreasuryAccount;
use App\Models\Finance\FinanceTreasuryTransfer;
use App\Models\Product;
use App\Models\Projects\FinanceProject;
use App\Models\User;
use App\Models\Workspace;
use App\Services\Finance\AccountingService;
use App\Services\Finance\LedgerReportService;
use App\Support\Money\Money;
use App\Support\Tenancy\WorkspaceContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use RuntimeException;
use Tests\TestCase;

class PlatformCoreTest extends TestCase
{
    use RefreshDatabase;

    public function test_sales_invoice_with_tracked_product_posts_cogs_and_reduces_stock(): void
    {
        [$user, $workspace] = $this->createWorkspaceOwner('company');
        $customer = $this->customer($workspace);
        $product = $this->trackedProduct($workspace, stock: 10, cost: 40);

        $this->actingAs($user)
            ->withSession(['current_workspace_id' => $workspace->id])
            ->post(route('workspace.finance.invoices.store'), $this->salesPayload($customer->id, $product))
            ->assertRedirect();

        $invoice = FinanceInvoice::withoutGlobalScopes()->firstOrFail();
        $entry = FinanceJournalEntry::withoutGlobalScopes()
            ->where('reference_type', FinanceInvoice::class)
            ->where('reference_id', $invoice->id)
            ->where('type', 'sales_invoice')
            ->firstOrFail();
        app(WorkspaceContext::class)->runFor($workspace, fn () => $entry->load('lines.account'));

        $this->assertEquals(
            (float) $entry->lines->sum('debit'),
            (float) $entry->lines->sum('credit')
        );
        $this->assertNotNull($entry->lines->first(fn ($line) => $line->account?->code === '5300'));
        $this->assertNotNull($entry->lines->first(fn ($line) => $line->account?->code === '1300'));
        $this->assertSame('80.00', Money::of($entry->lines->first(fn ($line) => $line->account?->code === '5300')->debit));
        $this->assertSame(8, $product->fresh()->stock);
    }

    public function test_purchase_invoice_with_tracked_product_debits_inventory_not_expense(): void
    {
        [$user, $workspace] = $this->createWorkspaceOwner('company');
        $supplier = $this->supplier($workspace);
        $product = $this->trackedProduct($workspace, stock: 1, cost: 40);

        $this->actingAs($user)
            ->withSession(['current_workspace_id' => $workspace->id])
            ->post(route('workspace.finance.invoices.store'), [
                'type' => 'purchase',
                'supplier_id' => $supplier->id,
                'issue_date' => now()->toDateString(),
                'currency' => 'SAR',
                'invoice_status' => 'issued',
                'tax_profile_type' => 'standard',
                'tax_rate' => 15,
                'items_json' => json_encode([[
                    'product_id' => $product->id,
                    'product_name' => $product->name,
                    'quantity' => 2,
                    'unit_price' => 50,
                    'discount' => 0,
                    'tax_rate' => 15,
                    'tax_type' => 'standard',
                ]]),
            ])
            ->assertRedirect();

        $invoice = FinanceInvoice::withoutGlobalScopes()->firstOrFail();
        $entry = FinanceJournalEntry::withoutGlobalScopes()
            ->where('reference_id', $invoice->id)
            ->where('type', 'purchase_invoice')
            ->firstOrFail();
        app(WorkspaceContext::class)->runFor($workspace, fn () => $entry->load('lines.account'));

        $this->assertNull($entry->lines->first(fn ($line) => $line->account?->code === '5900' && (float) $line->debit > 0));
        $this->assertNotNull($entry->lines->first(fn ($line) => $line->account?->code === '1300'));
        $this->assertSame(3, $product->fresh()->stock);
        $this->assertEquals((float) $entry->lines->sum('debit'), (float) $entry->lines->sum('credit'));
    }

    public function test_posted_journal_cannot_be_silently_mutated(): void
    {
        [$user, $workspace] = $this->createWorkspaceOwner('company');
        $this->actingAs($user)->withSession(['current_workspace_id' => $workspace->id])
            ->get(route('workspace.finance.dashboard'))
            ->assertOk();

        $cash = FinanceAccount::withoutGlobalScopes()->where('workspace_id', $workspace->id)->where('code', '1000')->firstOrFail();
        $bank = FinanceAccount::withoutGlobalScopes()->where('workspace_id', $workspace->id)->where('code', '1100')->firstOrFail();

        app(WorkspaceContext::class)->set($workspace);
        $entry = app(AccountingService::class)->createEntry(
            workspaceId: $workspace->id,
            entryDate: now()->toDateString(),
            type: 'adjustment',
            lines: [
                ['account_id' => $cash->id, 'debit' => 10, 'credit' => 0, 'description' => 'in'],
                ['account_id' => $bank->id, 'debit' => 0, 'credit' => 10, 'description' => 'out'],
            ],
        );

        $this->assertSame('posted', $entry->status);
        $this->expectException(RuntimeException::class);
        $entry->update(['description' => 'tampered']);
    }

    public function test_ledger_trial_balance_and_pnl_are_balanced_after_sale(): void
    {
        [$user, $workspace] = $this->createWorkspaceOwner('company');
        $customer = $this->customer($workspace);

        $this->actingAs($user)
            ->withSession(['current_workspace_id' => $workspace->id])
            ->post(route('workspace.finance.invoices.store'), [
                'type' => 'sales',
                'customer_id' => $customer->id,
                'issue_date' => now()->toDateString(),
                'currency' => 'SAR',
                'invoice_status' => 'issued',
                'tax_profile_type' => 'standard',
                'tax_rate' => 15,
                'items_json' => json_encode([[
                    'product_name' => 'Service',
                    'quantity' => 1,
                    'unit_price' => 100,
                    'discount' => 0,
                    'tax_rate' => 15,
                    'tax_type' => 'standard',
                ]]),
            ])
            ->assertRedirect();

        $this->actingAs($user)
            ->withSession(['current_workspace_id' => $workspace->id])
            ->get(route('workspace.finance.reports.show', ['report' => 'trial-balance']))
            ->assertOk()
            ->assertSee('مدين');

        $trial = app(LedgerReportService::class)
            ->trialBalance($workspace->id, now()->toDateString());
        $this->assertTrue($trial['balanced']);
        $this->assertSame($trial['total_debit'], $trial['total_credit']);

        $pnl = app(LedgerReportService::class)
            ->profitAndLoss($workspace->id, now()->toDateString(), now()->toDateString());
        $this->assertSame('100.00', $pnl['revenue']);
    }

    public function test_treasury_transfer_is_balanced_idempotent_and_isolated(): void
    {
        [$user, $workspace] = $this->createWorkspaceOwner('company');
        [$otherUser, $otherWorkspace] = $this->createWorkspaceOwner('company');

        $this->actingAs($user)->withSession(['current_workspace_id' => $workspace->id])
            ->get(route('workspace.finance.dashboard'))->assertOk();
        $this->actingAs($otherUser)->withSession(['current_workspace_id' => $otherWorkspace->id])
            ->get(route('workspace.finance.dashboard'))->assertOk();

        $from = FinanceTreasuryAccount::withoutGlobalScopes()->where('workspace_id', $workspace->id)->where('type', 'cash')->firstOrFail();
        $to = FinanceTreasuryAccount::withoutGlobalScopes()->where('workspace_id', $workspace->id)->where('type', 'bank')->firstOrFail();
        $from->update(['current_balance' => 500]);

        $this->actingAs($user)->withSession(['current_workspace_id' => $workspace->id])
            ->post(route('workspace.finance.treasury.transfers.store'), [
                'from_treasury_account_id' => $from->id,
                'to_treasury_account_id' => $to->id,
                'amount' => 80,
                'transfer_date' => now()->toDateString(),
                'reference' => 'TR-1',
            ])->assertRedirect();

        $this->actingAs($user)->withSession(['current_workspace_id' => $workspace->id])
            ->post(route('workspace.finance.treasury.transfers.store'), [
                'from_treasury_account_id' => $from->id,
                'to_treasury_account_id' => $to->id,
                'amount' => 80,
                'transfer_date' => now()->toDateString(),
                'reference' => 'TR-1',
            ])->assertRedirect();

        $this->assertSame(1, FinanceTreasuryTransfer::withoutGlobalScopes()->where('workspace_id', $workspace->id)->count());
        $this->assertSame('420.00', Money::of($from->fresh()->current_balance));
        $this->assertSame('80.00', Money::of($to->fresh()->current_balance));

        $entry = FinanceJournalEntry::withoutGlobalScopes()->where('type', 'treasury_transfer')->firstOrFail();
        app(WorkspaceContext::class)->runFor($workspace, fn () => $entry->load('lines'));
        $this->assertEquals((float) $entry->lines->sum('debit'), (float) $entry->lines->sum('credit'));

        $this->actingAs($otherUser)->withSession(['current_workspace_id' => $otherWorkspace->id])
            ->get(route('workspace.finance.treasury.index'))
            ->assertOk()
            ->assertDontSee('TR-1');
    }

    public function test_copilot_is_grounded_and_workspace_isolated(): void
    {
        [$user, $workspace] = $this->createWorkspaceOwner('company');
        [$otherUser, $otherWorkspace] = $this->createWorkspaceOwner('company');
        $customer = $this->customer($workspace, 'Acme Giant');

        $this->actingAs($user)->withSession(['current_workspace_id' => $workspace->id])
            ->post(route('workspace.finance.invoices.store'), [
                'type' => 'sales',
                'customer_id' => $customer->id,
                'issue_date' => now()->toDateString(),
                'currency' => 'SAR',
                'invoice_status' => 'issued',
                'tax_profile_type' => 'standard',
                'tax_rate' => 15,
                'items_json' => json_encode([[
                    'product_name' => 'Consulting',
                    'quantity' => 1,
                    'unit_price' => 200,
                    'discount' => 0,
                    'tax_rate' => 15,
                    'tax_type' => 'standard',
                ]]),
            ])->assertRedirect();

        $this->actingAs($user)->withSession(['current_workspace_id' => $workspace->id])
            ->post(route('workspace.finance.copilot.ask'), ['question' => 'كم مبيعات هذا الشهر؟'])
            ->assertOk()
            ->assertSee('230.00');

        $this->actingAs($otherUser)->withSession(['current_workspace_id' => $otherWorkspace->id])
            ->post(route('workspace.finance.copilot.ask'), ['question' => 'كم مبيعات هذا الشهر؟'])
            ->assertOk()
            ->assertSee('0.00')
            ->assertDontSee('230.00');
    }

    public function test_search_and_lead_conversion_stay_inside_workspace(): void
    {
        [$user, $workspace] = $this->createWorkspaceOwner('company');
        [$otherUser, $otherWorkspace] = $this->createWorkspaceOwner('company');

        $this->actingAs($user)->withSession(['current_workspace_id' => $workspace->id])
            ->post(route('workspace.finance.leads.store'), ['name' => 'Secret Lead'])
            ->assertRedirect();

        $this->actingAs($otherUser)->withSession(['current_workspace_id' => $otherWorkspace->id])
            ->get(route('workspace.finance.search', ['q' => 'Secret']))
            ->assertOk()
            ->assertDontSee('Secret Lead');

        $lead = CrmLead::withoutGlobalScopes()->where('workspace_id', $workspace->id)->firstOrFail();
        $this->actingAs($otherUser)->withSession(['current_workspace_id' => $otherWorkspace->id])
            ->post(route('workspace.finance.leads.convert', $lead))
            ->assertNotFound();

        $this->actingAs($user)->withSession(['current_workspace_id' => $workspace->id])
            ->post(route('workspace.finance.leads.convert', $lead))
            ->assertRedirect();

        $this->assertSame('converted', $lead->fresh()->status);
        $this->assertNotNull(Customer::withoutGlobalScopes()->where('workspace_id', $workspace->id)->where('name', 'Secret Lead')->first());
    }

    public function test_purchase_order_to_bill_posts_balanced_supplier_invoice(): void
    {
        [$user, $workspace] = $this->createWorkspaceOwner('company');
        $supplier = $this->supplier($workspace);

        $this->actingAs($user)->withSession(['current_workspace_id' => $workspace->id])
            ->post(route('workspace.finance.purchase-orders.store'), [
                'supplier_id' => $supplier->id,
                'order_date' => now()->toDateString(),
                'items_json' => json_encode([[
                    'product_name' => 'Office chairs',
                    'quantity' => 2,
                    'unit_price' => 100,
                    'tax_rate' => 15,
                ]]),
            ])->assertRedirect();

        $order = FinancePurchaseOrder::withoutGlobalScopes()->firstOrFail();
        $this->actingAs($user)->withSession(['current_workspace_id' => $workspace->id])
            ->post(route('workspace.finance.purchase-orders.submit', $order))
            ->assertRedirect();
        $this->actingAs($user)->withSession(['current_workspace_id' => $workspace->id])
            ->post(route('workspace.finance.purchase-orders.bill', $order))
            ->assertRedirect();

        $invoice = FinanceInvoice::withoutGlobalScopes()->where('type', 'purchase')->firstOrFail();
        $this->assertSame('230.00', Money::of($invoice->total));
        $entry = FinanceJournalEntry::withoutGlobalScopes()
            ->where('reference_id', $invoice->id)
            ->where('type', 'purchase_invoice')
            ->firstOrFail();
        app(WorkspaceContext::class)->runFor($workspace, fn () => $entry->load('lines'));
        $this->assertEquals((float) $entry->lines->sum('debit'), (float) $entry->lines->sum('credit'));
    }

    public function test_insufficient_stock_blocks_tracked_sale(): void
    {
        [$user, $workspace] = $this->createWorkspaceOwner('company');
        $customer = $this->customer($workspace);
        $product = $this->trackedProduct($workspace, stock: 1, cost: 10);

        $this->actingAs($user)->withSession(['current_workspace_id' => $workspace->id])
            ->post(route('workspace.finance.invoices.store'), $this->salesPayload($customer->id, $product))
            ->assertRedirect();

        $this->assertSame(0, FinanceInvoice::withoutGlobalScopes()->count());
        $this->assertSame(1, $product->fresh()->stock);
    }

    public function test_ai_invoice_intent_does_not_create_records(): void
    {
        [$user, $workspace] = $this->createWorkspaceOwner('company');
        $this->actingAs($user)->withSession(['current_workspace_id' => $workspace->id])
            ->post(route('workspace.finance.copilot.ask'), ['question' => 'أنشئ فاتورة للعميل أحمد بقيمة 500'])
            ->assertOk()
            ->assertSee('تأكيد');

        $this->assertSame(0, FinanceInvoice::withoutGlobalScopes()->count());
    }

    public function test_bank_statement_cannot_be_viewed_across_workspaces(): void
    {
        [$user, $workspace] = $this->createWorkspaceOwner('company');
        [$otherUser, $otherWorkspace] = $this->createWorkspaceOwner('company');

        $this->actingAs($user)->withSession(['current_workspace_id' => $workspace->id])
            ->get(route('workspace.finance.dashboard'))
            ->assertOk();
        $this->actingAs($otherUser)->withSession(['current_workspace_id' => $otherWorkspace->id])
            ->get(route('workspace.finance.dashboard'))
            ->assertOk();

        $treasury = FinanceTreasuryAccount::withoutGlobalScopes()
            ->where('workspace_id', $otherWorkspace->id)
            ->where('type', 'bank')
            ->firstOrFail();

        $foreign = FinanceBankStatement::withoutGlobalScopes()->create([
            'workspace_id' => $otherWorkspace->id,
            'treasury_account_id' => $treasury->id,
            'statement_date' => now()->toDateString(),
            'opening_balance' => 0,
            'closing_balance' => 100,
            'status' => 'open',
        ]);

        $this->actingAs($user)->withSession(['current_workspace_id' => $workspace->id])
            ->get(route('workspace.finance.treasury.statements.show', $foreign))
            ->assertNotFound();
    }

    public function test_project_cannot_be_listed_across_workspaces(): void
    {
        [$user, $workspace] = $this->createWorkspaceOwner('company');
        [$otherUser, $otherWorkspace] = $this->createWorkspaceOwner('company');

        FinanceProject::withoutGlobalScopes()->create([
            'workspace_id' => $otherWorkspace->id,
            'name' => 'Secret Project Alpha',
            'status' => 'active',
        ]);

        $this->actingAs($user)->withSession(['current_workspace_id' => $workspace->id])
            ->get(route('workspace.finance.projects.index'))
            ->assertOk()
            ->assertDontSee('Secret Project Alpha');
    }

    public function test_closed_period_blocks_new_journals(): void
    {
        [$user, $workspace] = $this->createWorkspaceOwner('company');
        $this->actingAs($user)->withSession(['current_workspace_id' => $workspace->id])
            ->get(route('workspace.finance.dashboard'))
            ->assertOk();

        $year = FinanceFiscalYear::withoutGlobalScopes()->create([
            'workspace_id' => $workspace->id,
            'name' => 'FY-CLOSED-JOURNAL',
            'start_date' => now()->startOfYear()->toDateString(),
            'end_date' => now()->endOfYear()->toDateString(),
            'status' => 'open',
        ]);
        FinanceAccountingPeriod::withoutGlobalScopes()->create([
            'workspace_id' => $workspace->id,
            'fiscal_year_id' => $year->id,
            'name' => now()->format('Y-m'),
            'start_date' => now()->startOfMonth()->toDateString(),
            'end_date' => now()->endOfMonth()->toDateString(),
            'status' => 'closed',
        ]);

        $cash = FinanceAccount::withoutGlobalScopes()
            ->where('workspace_id', $workspace->id)
            ->where('code', '1000')
            ->firstOrFail();
        $revenue = FinanceAccount::withoutGlobalScopes()
            ->where('workspace_id', $workspace->id)
            ->where('code', '4000')
            ->firstOrFail();

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('فترة محاسبية مغلقة');

        app(AccountingService::class)->createEntry(
            workspaceId: $workspace->id,
            entryDate: now()->toDateString(),
            type: 'adjustment',
            lines: [
                ['account_id' => $cash->id, 'debit' => 10, 'credit' => 0, 'description' => 'in'],
                ['account_id' => $revenue->id, 'debit' => 0, 'credit' => 10, 'description' => 'out'],
            ],
        );
    }

    public function test_purchase_order_cannot_be_billed_from_another_workspace(): void
    {
        [$user, $workspace] = $this->createWorkspaceOwner('company');
        [$otherUser, $otherWorkspace] = $this->createWorkspaceOwner('company');
        $supplier = $this->supplier($otherWorkspace);

        $order = FinancePurchaseOrder::withoutGlobalScopes()->create([
            'workspace_id' => $otherWorkspace->id,
            'supplier_id' => $supplier->id,
            'po_number' => 'PO-FOREIGN',
            'status' => 'received',
            'order_date' => now()->toDateString(),
            'currency' => 'SAR',
            'subtotal' => 10,
            'tax_amount' => 0,
            'total' => 10,
        ]);

        $this->actingAs($user)->withSession(['current_workspace_id' => $workspace->id])
            ->post(route('workspace.finance.purchase-orders.bill', $order))
            ->assertNotFound();
    }

    public function test_platform_core_index_names_fit_mysql_and_migration_is_rerunnable(): void
    {
        $tables = [
            'finance_treasury_transfers',
            'finance_bank_statements',
            'finance_bank_statement_lines',
            'finance_purchase_orders',
            'finance_purchase_order_items',
            'crm_leads',
            'finance_projects',
        ];

        foreach ($tables as $table) {
            $this->assertTrue(Schema::hasTable($table), $table.' should exist');
            foreach (Schema::getIndexes($table) as $index) {
                $name = (string) ($index['name'] ?? '');
                $this->assertLessThanOrEqual(64, strlen($name), $table.'.'.$name);
            }
        }

        $this->assertLessThanOrEqual(64, strlen('fin_po_items_ws_po_idx'));
        $this->assertGreaterThan(64, strlen('finance_purchase_order_items_workspace_id_purchase_order_id_index'));

        $migration = require database_path('migrations/2026_09_02_220000_add_platform_financial_core_tables.php');
        $migration->up();

        $this->assertTrue(Schema::hasTable('finance_purchase_order_items'));
        $this->assertTrue(collect(Schema::getIndexes('finance_purchase_order_items'))
            ->contains(fn (array $index): bool => ($index['name'] ?? '') === 'fin_po_items_ws_po_idx'
                || array_values($index['columns'] ?? []) === ['workspace_id', 'purchase_order_id']));
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
            $this->enableWorkspaceFeature($workspace, $feature);
        }

        return [$user, $workspace];
    }

    private function customer(Workspace $workspace, string $name = 'Customer A'): Customer
    {
        return Customer::withoutGlobalScopes()->create([
            'workspace_id' => $workspace->id,
            'name' => $name,
            'phone' => '0790000000',
            'email' => strtolower(str_replace(' ', '', $name)).'@example.com',
        ]);
    }

    private function supplier(Workspace $workspace): FinanceSupplier
    {
        return FinanceSupplier::withoutGlobalScopes()->create([
            'workspace_id' => $workspace->id,
            'name' => 'Supplier A',
            'status' => 'active',
        ]);
    }

    private function trackedProduct(Workspace $workspace, int $stock, int|float $cost): Product
    {
        return Product::withoutGlobalScopes()->create([
            'workspace_id' => $workspace->id,
            'name' => 'Tracked Widget',
            'slug' => 'tracked-widget-'.$workspace->id,
            'sku' => 'TW-'.$workspace->id,
            'price' => 100,
            'cost_price' => $cost,
            'currency' => 'SAR',
            'stock' => $stock,
            'inventory_tracking' => true,
            'status' => 'active',
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    private function salesPayload(int $customerId, Product $product): array
    {
        return [
            'type' => 'sales',
            'customer_id' => $customerId,
            'issue_date' => now()->toDateString(),
            'currency' => 'SAR',
            'invoice_status' => 'issued',
            'tax_profile_type' => 'standard',
            'tax_rate' => 15,
            'items_json' => json_encode([[
                'product_id' => $product->id,
                'product_name' => $product->name,
                'quantity' => 2,
                'unit_price' => 100,
                'discount' => 0,
                'tax_rate' => 15,
                'tax_type' => 'standard',
            ]]),
        ];
    }
}
