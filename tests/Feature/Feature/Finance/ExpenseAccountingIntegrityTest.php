<?php

namespace Tests\Feature\Feature\Finance;

use App\Models\Customer;
use App\Models\Finance\FinanceExpense;
use App\Models\Finance\FinanceInvoice;
use App\Models\Finance\FinanceJournalEntry;
use App\Models\Finance\FinanceTreasuryAccount;
use App\Models\User;
use App\Models\Workspace;
use App\Models\WorkspaceFeatureFlag;
use App\Services\Finance\BillingScheduleService;
use App\Services\Finance\ExpenseService;
use App\Services\Finance\FinanceBootstrapService;
use App\Services\Finance\InvoiceService;
use App\Services\Finance\InvoiceStateService;
use App\Support\Tenancy\WorkspaceContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class ExpenseAccountingIntegrityTest extends TestCase
{
    use RefreshDatabase;

    public function test_draft_expense_delete_removes_row_without_journal(): void
    {
        [$user, $workspace] = $this->makeFinanceWorkspace();
        $expense = $this->makeExpense($workspace, $user, 'draft');

        $this->assertSame(0, FinanceJournalEntry::withoutGlobalScopes()->count());

        app(ExpenseService::class)->delete($expense, $user->id);

        $this->assertSoftDeleted('finance_expenses', ['id' => $expense->id]);
    }

    public function test_paid_expense_delete_reverses_journal_and_restores_treasury(): void
    {
        [$user, $workspace] = $this->makeFinanceWorkspace();
        $treasury = FinanceTreasuryAccount::withoutGlobalScopes()
            ->where('workspace_id', $workspace->id)
            ->where('type', 'cash')
            ->firstOrFail();
        $opening = (float) $treasury->current_balance;

        $expense = $this->makeExpense($workspace, $user, 'paid');
        $treasury->refresh();
        $this->assertEqualsWithDelta($opening - (float) $expense->total, (float) $treasury->current_balance, 0.01);

        $entry = FinanceJournalEntry::withoutGlobalScopes()
            ->where('reference_type', FinanceExpense::class)
            ->where('reference_id', $expense->id)
            ->where('type', 'expense')
            ->firstOrFail();

        app(ExpenseService::class)->delete($expense, $user->id);

        $expense->refresh();
        $this->assertSame('cancelled', $expense->status);

        $reversal = FinanceJournalEntry::withoutGlobalScopes()
            ->where('reverses_entry_id', $entry->id)
            ->first();
        $this->assertNotNull($reversal);

        $treasury->refresh();
        $this->assertEqualsWithDelta($opening, (float) $treasury->current_balance, 0.01);

        app(ExpenseService::class)->delete($expense->fresh(), $user->id);
        $this->assertSame(1, FinanceJournalEntry::withoutGlobalScopes()->where('reverses_entry_id', $entry->id)->count());
    }

    public function test_billing_schedule_double_generate_does_not_duplicate_occurrence(): void
    {
        [$user, $workspace] = $this->makeFinanceWorkspace();
        $customer = Customer::withoutGlobalScopes()->create([
            'workspace_id' => $workspace->id,
            'name' => 'Schedule Customer',
            'phone' => '0501111222',
        ]);

        $service = app(BillingScheduleService::class);
        $schedule = $service->create($workspace, [
            'customer_id' => $customer->id,
            'title' => 'اشتراك شهري',
            'frequency' => 'monthly',
            'amount' => 100,
            'start_date' => now()->toDateString(),
            'status' => 'active',
            'auto_issue' => false,
        ], $user->id);

        $first = $service->generateOne($schedule, now());
        $this->assertNotNull($first);

        $schedule->refresh()->update([
            'generated_count' => 0,
            'next_run_on' => now()->toDateString(),
            'status' => 'active',
        ]);

        $second = $service->generateOne($schedule->fresh(), now());
        $this->assertNotNull($second);
        $this->assertSame($first->id, $second->id);
        $this->assertSame(1, FinanceInvoice::withoutGlobalScopes()->where('billing_schedule_id', $schedule->id)->count());
    }

    public function test_refresh_issued_statuses_does_not_query_payments_per_invoice(): void
    {
        [$user, $workspace] = $this->makeFinanceWorkspace();
        $customer = Customer::withoutGlobalScopes()->create([
            'workspace_id' => $workspace->id,
            'name' => 'Batch Customer',
            'phone' => '0503333444',
        ]);

        $invoiceService = app(InvoiceService::class);
        for ($i = 0; $i < 4; $i++) {
            $invoiceService->create($workspace, [
                'type' => 'sales',
                'customer_id' => $customer->id,
                'issue_date' => now()->toDateString(),
                'invoice_status' => 'issued',
                'items' => [[
                    'product_name' => 'Item '.$i,
                    'quantity' => 1,
                    'unit_price' => 50,
                    'discount' => 0,
                    'tax_rate' => 15,
                    'tax_type' => 'standard',
                ]],
            ], $user->id);
        }

        DB::flushQueryLog();
        DB::enableQueryLog();
        app(InvoiceStateService::class)->refreshIssuedStatuses($workspace->id);
        $queries = DB::getQueryLog();
        DB::disableQueryLog();

        $paymentSelects = collect($queries)->filter(function (array $query): bool {
            $sql = strtolower($query['query'] ?? '');

            return str_contains($sql, 'finance_invoice_payments') && str_contains($sql, 'select');
        });

        $this->assertLessThanOrEqual(4, $paymentSelects->count());
        $this->assertLessThan(25, count($queries));
    }

    /**
     * @return array{0: User, 1: Workspace}
     */
    private function makeFinanceWorkspace(): array
    {
        $user = User::factory()->create();
        $workspace = Workspace::factory()->create([
            'owner_user_id' => $user->id,
            'type' => 'company',
        ]);
        $workspace->users()->attach($user->id, [
            'membership_role' => 'owner',
            'status' => 'active',
            'joined_at' => now(),
        ]);
        foreach (['finance'] as $feature) {
            WorkspaceFeatureFlag::withoutGlobalScopes()->updateOrCreate(
                ['workspace_id' => $workspace->id, 'feature_key' => $feature],
                ['workspace_id' => $workspace->id, 'feature_key' => $feature, 'enabled' => true, 'source' => 'manual']
            );
        }

        app(WorkspaceContext::class)->set($workspace);
        app(FinanceBootstrapService::class)->ensureWorkspaceFinanceSetup($workspace);

        return [$user, $workspace];
    }

    private function makeExpense(Workspace $workspace, User $user, string $status): FinanceExpense
    {
        $treasury = FinanceTreasuryAccount::withoutGlobalScopes()
            ->where('workspace_id', $workspace->id)
            ->where('type', 'cash')
            ->first();

        return app(ExpenseService::class)->create($workspace, [
            'amount' => 100,
            'expense_date' => now()->toDateString(),
            'description' => 'Test expense',
            'status' => $status,
            'payment_method' => 'cash',
            'tax_profile_type' => 'zero_rated',
            'tax_rate' => 0,
            'treasury_account_id' => $treasury?->id,
        ], $user->id);
    }
}
