<?php

namespace Tests\Feature\Feature\Finance;

use App\Models\Contract\Contract;
use App\Models\Customer;
use App\Models\Finance\FinanceBillingSchedule;
use App\Models\Finance\FinanceCreditNote;
use App\Models\Finance\FinanceInvoice;
use App\Models\Plan;
use App\Models\PosCashierInvoice;
use App\Models\PosMenuItem;
use App\Models\Subscription;
use App\Models\User;
use App\Models\Workspace;
use App\Models\WorkspaceFeatureFlag;
use App\Services\Contracts\ContractService;
use App\Services\Finance\BillingScheduleService;
use App\Services\Finance\CreditNoteService;
use App\Services\Finance\FinanceBootstrapService;
use App\Services\Finance\InvoiceService;
use App\Services\Finance\ReportService;
use App\Support\Tenancy\WorkspaceContext;
use Database\Seeders\FoundationSeeder;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

class Phase1CriticalFixesTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Notification::fake();
        $this->seed(FoundationSeeder::class);
    }

    public function test_user_with_invoices_edit_cannot_issue_invoice(): void
    {
        [$owner, $workspace] = $this->createWorkspaceOwner('company');
        $customer = $this->makeCustomer($workspace, 'Edit Only Customer');
        $invoice = $this->createDraftInvoice($workspace, $customer, (int) $owner->id);
        $editor = $this->attachStaff($workspace, ['invoices.view', 'invoices.edit', 'invoices.create']);

        $this->actingAs($editor)
            ->withSession(['current_workspace_id' => $workspace->id])
            ->post(route('workspace.finance.invoices.issue', $invoice))
            ->assertForbidden();

        $this->assertSame('draft', $invoice->fresh()->invoice_status);
    }

    public function test_user_with_invoices_issue_can_issue_invoice(): void
    {
        [$owner, $workspace] = $this->createWorkspaceOwner('company');
        $customer = $this->makeCustomer($workspace, 'Issuer Customer');
        $invoice = $this->createDraftInvoice($workspace, $customer, (int) $owner->id);
        $issuer = $this->attachStaff($workspace, ['invoices.view', 'invoices.issue']);

        $this->actingAs($issuer)
            ->withSession(['current_workspace_id' => $workspace->id])
            ->post(route('workspace.finance.invoices.issue', $invoice))
            ->assertRedirect();

        $this->assertSame('issued', $invoice->fresh()->invoice_status);
    }

    public function test_user_without_invoices_issue_cannot_issue_invoice(): void
    {
        [$owner, $workspace] = $this->createWorkspaceOwner('company');
        $customer = $this->makeCustomer($workspace, 'Viewer Customer');
        $invoice = $this->createDraftInvoice($workspace, $customer, (int) $owner->id);
        $viewer = $this->attachStaff($workspace, ['invoices.view']);

        $this->actingAs($viewer)
            ->withSession(['current_workspace_id' => $workspace->id])
            ->post(route('workspace.finance.invoices.issue', $invoice))
            ->assertForbidden();
    }

    public function test_user_with_invoices_create_only_cannot_create_or_issue_credit_note(): void
    {
        [$owner, $workspace] = $this->createWorkspaceOwner('company');
        $customer = $this->makeCustomer($workspace, 'Credit Auth Customer');
        $invoice = $this->createIssuedInvoice($workspace, $customer, (int) $owner->id);
        $creator = $this->attachStaff($workspace, ['invoices.view', 'invoices.create', 'invoices.edit']);

        $payload = [
            'type' => 'credit',
            'reason' => 'غير مصرح',
            'issue_date' => now()->toDateString(),
            'status' => 'draft',
            'items_json' => json_encode([[
                'product_name' => 'خصم',
                'quantity' => 1,
                'unit_price' => 10,
                'discount' => 0,
                'tax_rate' => 15,
            ]]),
        ];

        $this->actingAs($creator)
            ->withSession(['current_workspace_id' => $workspace->id])
            ->post(route('workspace.finance.invoices.credit-notes.store', $invoice), $payload)
            ->assertForbidden();

        $this->assertSame(0, FinanceCreditNote::withoutGlobalScopes()->count());

        $note = app(CreditNoteService::class)->create($workspace, $invoice, [
            'type' => 'credit',
            'reason' => 'مسودة داخلية',
            'issue_date' => now()->toDateString(),
            'status' => 'draft',
            'items' => [[
                'product_name' => 'خصم',
                'quantity' => 1,
                'unit_price' => 10,
                'discount' => 0,
                'tax_rate' => 15,
            ]],
        ], (int) $owner->id);

        $this->actingAs($creator)
            ->withSession(['current_workspace_id' => $workspace->id])
            ->post(route('workspace.finance.invoices.credit-notes.issue', [$invoice, $note]))
            ->assertForbidden();

        $this->assertSame('draft', $note->fresh()->status);
    }

    public function test_draft_invoice_can_be_deleted_with_invoices_delete(): void
    {
        [$owner, $workspace] = $this->createWorkspaceOwner('company');
        $customer = $this->makeCustomer($workspace, 'Delete Draft Customer');
        $invoice = $this->createDraftInvoice($workspace, $customer, (int) $owner->id);
        $deleter = $this->attachStaff($workspace, ['invoices.view', 'invoices.delete']);

        $this->actingAs($deleter)
            ->withSession(['current_workspace_id' => $workspace->id])
            ->delete(route('workspace.finance.invoices.destroy', $invoice))
            ->assertRedirect(route('workspace.finance.invoices.index'));

        $this->assertSoftDeleted('finance_invoices', ['id' => $invoice->id]);
    }

    public function test_issued_invoice_cannot_be_deleted(): void
    {
        [$owner, $workspace] = $this->createWorkspaceOwner('company');
        $customer = $this->makeCustomer($workspace, 'Delete Issued Customer');
        $invoice = $this->createIssuedInvoice($workspace, $customer, (int) $owner->id);
        $deleter = $this->attachStaff($workspace, ['invoices.view', 'invoices.delete']);

        $this->actingAs($deleter)
            ->withSession(['current_workspace_id' => $workspace->id])
            ->delete(route('workspace.finance.invoices.destroy', $invoice))
            ->assertRedirect();

        $this->assertDatabaseHas('finance_invoices', [
            'id' => $invoice->id,
            'invoice_status' => 'issued',
        ]);
        $this->assertNull($invoice->fresh()->deleted_at);
    }

    public function test_concurrent_cashier_invoice_creation_does_not_duplicate_invoice_numbers(): void
    {
        [$owner, $workspace] = $this->createWorkspaceOwner('store');
        app(WorkspaceContext::class)->set($workspace);
        $item = PosMenuItem::withoutGlobalScopes()->create([
            'workspace_id' => $workspace->id,
            'name' => 'منتج كاشير',
            'price' => 25,
            'currency' => 'SAR',
            'is_active' => true,
        ]);

        $numbers = [];
        for ($i = 0; $i < 5; $i++) {
            $order = $this->placePosOrder($workspace, $owner, [
                'order_type' => 'takeaway',
                'items' => [['pos_menu_item_id' => $item->id, 'quantity' => 1]],
            ]);
            $invoice = PosCashierInvoice::withoutGlobalScopes()->find($order->pos_cashier_invoice_id);
            $this->assertNotNull($invoice);
            $this->assertMatchesRegularExpression('/^CASH-\d{8}$/', (string) $invoice->invoice_number);
            $numbers[] = $invoice->invoice_number;
        }

        $this->assertCount(5, array_unique($numbers));

        $this->expectException(UniqueConstraintViolationException::class);
        PosCashierInvoice::withoutGlobalScopes()->create([
            'workspace_id' => $workspace->id,
            'invoice_number' => $numbers[0],
            'status' => 'closed',
            'currency' => 'SAR',
            'subtotal' => 10,
            'discount_amount' => 0,
            'total_amount' => 10,
            'closed_at' => now(),
        ]);
    }

    public function test_vat_report_subtracts_issued_credit_note_tax(): void
    {
        [$owner, $workspace] = $this->createWorkspaceOwner('company');
        $customer = $this->makeCustomer($workspace, 'VAT Credit Customer');
        $invoice = $this->createIssuedInvoice($workspace, $customer, (int) $owner->id, 100);
        $this->assertSame('15.00', (string) $invoice->tax_amount);

        app(CreditNoteService::class)->create($workspace, $invoice, [
            'type' => 'credit',
            'reason' => 'خصم ضريبي',
            'issue_date' => now()->toDateString(),
            'status' => 'issued',
            'items' => [[
                'product_name' => 'تعويض',
                'quantity' => 1,
                'unit_price' => 20,
                'discount' => 0,
                'tax_rate' => 15,
            ]],
        ], (int) $owner->id);

        app(CreditNoteService::class)->create($workspace, $invoice->fresh(), [
            'type' => 'credit',
            'reason' => 'مسودة لا تدخل التقرير',
            'issue_date' => now()->toDateString(),
            'status' => 'draft',
            'items' => [[
                'product_name' => 'مسودة',
                'quantity' => 1,
                'unit_price' => 10,
                'discount' => 0,
                'tax_rate' => 15,
            ]],
        ], (int) $owner->id);

        $summary = app(ReportService::class)->summary(
            now()->startOfMonth()->toDateString(),
            now()->endOfMonth()->toDateString()
        );
        $this->assertSame(12.0, $summary['vat']['output']);
        $this->assertSame(12.0, $summary['vat']['net']);
    }

    public function test_vat_report_adds_issued_debit_note_tax(): void
    {
        [$owner, $workspace] = $this->createWorkspaceOwner('company');
        $customer = $this->makeCustomer($workspace, 'VAT Debit Customer');
        $invoice = $this->createIssuedInvoice($workspace, $customer, (int) $owner->id, 100);
        $this->assertSame('15.00', (string) $invoice->tax_amount);

        app(CreditNoteService::class)->create($workspace, $invoice, [
            'type' => 'debit',
            'reason' => 'رسوم إضافية',
            'issue_date' => now()->toDateString(),
            'status' => 'issued',
            'items' => [[
                'product_name' => 'رسوم',
                'quantity' => 1,
                'unit_price' => 20,
                'discount' => 0,
                'tax_rate' => 15,
            ]],
        ], (int) $owner->id);

        $summary = app(ReportService::class)->summary(
            now()->startOfMonth()->toDateString(),
            now()->endOfMonth()->toDateString()
        );
        $this->assertSame(18.0, $summary['vat']['output']);
        $this->assertSame(18.0, $summary['vat']['net']);
    }

    public function test_cancel_contract_stops_active_billing_schedules(): void
    {
        [$owner, $workspace] = $this->createWorkspaceOwner('company');
        [$contract, $schedule] = $this->openContractWithActiveSchedule($workspace, $owner);

        app(ContractService::class)->cancel($contract);

        $this->assertSame('cancelled', $contract->fresh()->status);
        $schedule->refresh();
        $this->assertSame(FinanceBillingSchedule::STATUS_CANCELLED, $schedule->status);
        $this->assertNull($schedule->next_run_on);
        $this->assertNull(app(BillingScheduleService::class)->generateOne($schedule->fresh()));
        $this->assertSame(0, FinanceInvoice::withoutGlobalScopes()->where('contract_id', $contract->id)->count());
    }

    public function test_close_contract_blocks_future_billing(): void
    {
        [$owner, $workspace] = $this->createWorkspaceOwner('company');
        [$contract, $schedule] = $this->openContractWithActiveSchedule($workspace, $owner);

        app(ContractService::class)->close($contract);

        $this->assertSame('closed', $contract->fresh()->status);
        $schedule->refresh();
        $this->assertSame(FinanceBillingSchedule::STATUS_CANCELLED, $schedule->status);
        $this->assertNull(app(BillingScheduleService::class)->generateOne($schedule->fresh()));
    }

    public function test_expired_contract_blocks_future_billing_even_if_schedule_stays_active(): void
    {
        [$owner, $workspace] = $this->createWorkspaceOwner('company');
        [$contract, $schedule] = $this->openContractWithActiveSchedule($workspace, $owner);

        $contract->update([
            'end_date' => now()->subDay()->toDateString(),
        ]);

        $this->assertSame('open', $contract->fresh()->status);
        $this->assertSame(FinanceBillingSchedule::STATUS_ACTIVE, $schedule->fresh()->status);
        $this->assertNull(app(BillingScheduleService::class)->generateOne($schedule->fresh()));
        $this->assertSame(0, FinanceInvoice::withoutGlobalScopes()->where('billing_schedule_id', $schedule->id)->count());
    }

    public function test_running_billing_generation_twice_does_not_duplicate_invoice(): void
    {
        [$owner, $workspace] = $this->createWorkspaceOwner('company');
        [$contract, $schedule] = $this->openContractWithActiveSchedule($workspace, $owner);

        $first = app(BillingScheduleService::class)->generateDueInvoices((int) $workspace->id);
        $second = app(BillingScheduleService::class)->generateDueInvoices((int) $workspace->id);

        $this->assertSame(1, $first);
        $this->assertSame(0, $second);
        $this->assertSame(1, FinanceInvoice::withoutGlobalScopes()->where('contract_id', $contract->id)->count());
        $this->assertSame(1, FinanceInvoice::withoutGlobalScopes()->where('billing_schedule_id', $schedule->id)->count());
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

        foreach (['finance', 'products', 'orders', 'customers', 'pos'] as $feature) {
            WorkspaceFeatureFlag::withoutGlobalScopes()->updateOrCreate(
                ['workspace_id' => $workspace->id, 'feature_key' => $feature],
                ['workspace_id' => $workspace->id, 'feature_key' => $feature, 'enabled' => true, 'source' => 'manual']
            );
        }

        $plan = Plan::query()
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

        app(WorkspaceContext::class)->set($workspace);
        app(PermissionRegistrar::class)->setPermissionsTeamId($workspace->id);
        app(FinanceBootstrapService::class)->ensureWorkspaceFinanceSetup($workspace);

        return [$user, $workspace];
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

    private function makeCustomer(Workspace $workspace, string $name): Customer
    {
        return Customer::withoutGlobalScopes()->create([
            'workspace_id' => $workspace->id,
            'name' => $name,
            'phone' => '05'.random_int(10000000, 99999999),
            'email' => strtolower(str_replace(' ', '.', $name)).'@example.com',
        ]);
    }

    private function invoicePayload(int $customerId, float $unitPrice = 100): array
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
                'unit_price' => $unitPrice,
                'discount' => 0,
                'tax_rate' => 15,
                'tax_type' => 'standard',
            ]],
        ];
    }

    private function createDraftInvoice(Workspace $workspace, Customer $customer, int $actorId, float $unitPrice = 100): FinanceInvoice
    {
        return app(InvoiceService::class)->create($workspace, $this->invoicePayload($customer->id, $unitPrice), $actorId);
    }

    private function createIssuedInvoice(Workspace $workspace, Customer $customer, int $actorId, float $unitPrice = 100): FinanceInvoice
    {
        $payload = $this->invoicePayload($customer->id, $unitPrice);
        $payload['invoice_status'] = 'issued';

        return app(InvoiceService::class)->create($workspace, $payload, $actorId);
    }

    /**
     * @return array{0: Contract, 1: FinanceBillingSchedule}
     */
    private function openContractWithActiveSchedule(Workspace $workspace, User $owner): array
    {
        $customer = $this->makeCustomer($workspace, 'Contract Billing Customer');
        $contract = app(ContractService::class)->create($workspace, [
            'title' => 'عقد فوترة',
            'customer_id' => $customer->id,
            'value' => 230,
            'currency' => 'SAR',
            'start_date' => now()->toDateString(),
            'items' => [
                ['title' => 'خدمة شهرية', 'quantity' => 1, 'unit_price' => 230],
            ],
        ], (int) $owner->id);
        $contract = app(ContractService::class)->activate($contract, (int) $owner->id);

        $schedule = app(BillingScheduleService::class)->createFromContract($contract, [
            'title' => 'قسط أول',
            'frequency' => 'installment',
            'total_occurrences' => 2,
            'start_date' => now()->toDateString(),
            'status' => FinanceBillingSchedule::STATUS_ACTIVE,
        ], (int) $owner->id);

        return [$contract, $schedule->fresh()];
    }
}
