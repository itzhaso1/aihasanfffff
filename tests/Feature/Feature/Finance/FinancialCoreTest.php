<?php

namespace Tests\Feature\Feature\Finance;

use App\Models\Customer;
use App\Models\Finance\FinanceAccount;
use App\Models\Finance\FinanceAccountingPeriod;
use App\Models\Finance\FinanceEmployee;
use App\Models\Finance\FinanceExpense;
use App\Models\Finance\FinanceFiscalYear;
use App\Models\Finance\FinanceInvoice;
use App\Models\Finance\FinanceInvoicePayment;
use App\Models\Finance\FinanceJournalEntry;
use App\Models\Finance\FinancePayrollAdjustment;
use App\Models\Finance\FinancePriceList;
use App\Models\Finance\FinanceSalaryAdvance;
use App\Models\Finance\FinanceSalaryAdvanceRepayment;
use App\Models\Finance\FinanceSetting;
use App\Models\Finance\FinanceSupplier;
use App\Models\Plan;
use App\Models\Subscription;
use App\Models\User;
use App\Models\Workspace;
use App\Models\WorkspaceFeatureFlag;
use App\Services\Finance\AccountingService;
use App\Services\Finance\ChartOfAccountsService;
use App\Services\Finance\InvoiceService;
use App\Services\Finance\PayrollService;
use App\Services\Finance\TaxService;
use App\Support\Tenancy\WorkspaceContext;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Foundation\Testing\RefreshDatabase;
use RuntimeException;
use Tests\TestCase;

class FinancialCoreTest extends TestCase
{
    use RefreshDatabase;

    public function test_sales_invoice_creation_recalculates_and_posts_balanced_entry(): void
    {
        [$user, $workspace] = $this->createWorkspaceOwner('company');
        $customer = Customer::withoutGlobalScopes()->create([
            'workspace_id' => $workspace->id,
            'name' => 'ACME Customer',
            'phone' => '0500001111',
            'email' => 'acme@example.com',
        ]);

        $response = $this->actingAs($user)
            ->withSession(['current_workspace_id' => $workspace->id])
            ->post(route('workspace.finance.invoices.store'), [
                'type' => 'sales',
                'customer_id' => $customer->id,
                'issue_date' => now()->toDateString(),
                'due_date' => now()->addDays(10)->toDateString(),
                'currency' => 'SAR',
                'invoice_status' => 'issued',
                'tax_profile_type' => 'standard',
                'tax_rate' => 15,
                'items_json' => json_encode([
                    [
                        'product_name' => 'Consulting Service',
                        'description' => 'Consulting',
                        'quantity' => 2,
                        'unit_price' => 100,
                        'discount' => 0,
                        'tax_rate' => 15,
                        'tax_type' => 'standard',
                    ],
                ]),
            ]);

        $response->assertRedirect();

        $invoice = FinanceInvoice::withoutGlobalScopes()->firstOrFail();
        $this->assertSame('sales', $invoice->type);
        $this->assertSame('issued', $invoice->invoice_status);
        $this->assertSame('unpaid', $invoice->payment_status);
        $this->assertSame('unpaid', $invoice->status);
        $this->assertSame('200.00', (string) $invoice->subtotal);
        $this->assertSame('200.00', (string) $invoice->taxable_amount);
        $this->assertSame('30.00', (string) $invoice->tax_amount);
        $this->assertSame('230.00', (string) $invoice->total);
        $this->assertSame('230.00', (string) $invoice->amount_due);

        $entry = FinanceJournalEntry::withoutGlobalScopes()
            ->where('reference_type', FinanceInvoice::class)
            ->where('reference_id', $invoice->id)
            ->firstOrFail();

        app(WorkspaceContext::class)->runFor($workspace, fn () => $entry->load('lines'));
        $debit = (float) $entry->lines->sum('debit');
        $credit = (float) $entry->lines->sum('credit');
        $this->assertEquals($debit, $credit);
    }

    public function test_partial_and_full_payment_update_invoice_status_and_due(): void
    {
        [$user, $workspace] = $this->createWorkspaceOwner('company');
        $customer = Customer::withoutGlobalScopes()->create([
            'workspace_id' => $workspace->id,
            'name' => 'Partial Customer',
            'phone' => '0500002222',
        ]);

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
                'items_json' => json_encode([
                    [
                        'product_name' => 'Implementation',
                        'quantity' => 1,
                        'unit_price' => 200,
                        'discount' => 0,
                        'tax_rate' => 15,
                        'tax_type' => 'standard',
                    ],
                ]),
            ])
            ->assertRedirect();

        $invoice = FinanceInvoice::withoutGlobalScopes()->firstOrFail();
        $this->assertSame('230.00', (string) $invoice->amount_due);

        $this->actingAs($user)
            ->withSession(['current_workspace_id' => $workspace->id])
            ->post(route('workspace.finance.invoices.payments.store', $invoice), [
                'payment_date' => now()->toDateString(),
                'amount' => 100,
                'method' => 'cash',
            ])
            ->assertRedirect();

        $invoice->refresh();
        $this->assertSame('issued', $invoice->invoice_status);
        $this->assertSame('partial', $invoice->payment_status);
        $this->assertSame('partial', $invoice->status);
        $this->assertSame('100.00', (string) $invoice->amount_paid);
        $this->assertSame('130.00', (string) $invoice->amount_due);

        $this->actingAs($user)
            ->withSession(['current_workspace_id' => $workspace->id])
            ->post(route('workspace.finance.invoices.payments.store', $invoice), [
                'payment_date' => now()->toDateString(),
                'amount' => 130,
                'method' => 'cash',
            ])
            ->assertRedirect();

        $invoice->refresh();
        $this->assertSame('issued', $invoice->invoice_status);
        $this->assertSame('paid', $invoice->payment_status);
        $this->assertSame('paid', $invoice->status);
        $this->assertSame('230.00', (string) $invoice->amount_paid);
        $this->assertSame('0.00', (string) $invoice->amount_due);
    }

    public function test_purchase_invoice_posts_accounts_payable_entry(): void
    {
        [$user, $workspace] = $this->createWorkspaceOwner('company');
        $supplier = FinanceSupplier::withoutGlobalScopes()->create([
            'workspace_id' => $workspace->id,
            'name' => 'Supplier A',
            'status' => 'active',
        ]);

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
                'items_json' => json_encode([
                    [
                        'product_name' => 'Vendor Service',
                        'quantity' => 1,
                        'unit_price' => 300,
                        'discount' => 0,
                        'tax_rate' => 15,
                        'tax_type' => 'standard',
                    ],
                ]),
            ])
            ->assertRedirect();

        $invoice = FinanceInvoice::withoutGlobalScopes()->firstOrFail();
        $entry = FinanceJournalEntry::withoutGlobalScopes()
            ->where('reference_type', FinanceInvoice::class)
            ->where('reference_id', $invoice->id)
            ->firstOrFail();

        app(WorkspaceContext::class)->runFor($workspace, fn () => $entry->load('lines.account'));
        $apLine = $entry->lines->first(fn ($line) => $line->account?->code === '2000');
        $this->assertNotNull($apLine);
        $this->assertSame('345.00', number_format((float) $apLine->credit, 2, '.', ''));
    }

    public function test_sales_invoice_accepts_walk_in_customer_name_without_registered_customer(): void
    {
        [$user, $workspace] = $this->createWorkspaceOwner('company');

        $this->actingAs($user)
            ->withSession(['current_workspace_id' => $workspace->id])
            ->post(route('workspace.finance.invoices.store'), [
                'type' => 'sales',
                'customer_name' => 'عميل نقدي',
                'issue_date' => now()->toDateString(),
                'currency' => 'SAR',
                'invoice_status' => 'issued',
                'tax_profile_type' => 'standard',
                'tax_rate' => 15,
                'items_json' => json_encode([
                    [
                        'product_name' => 'خدمة سريعة',
                        'quantity' => 1,
                        'unit_price' => 100,
                        'discount' => 0,
                        'tax_rate' => 15,
                        'tax_type' => 'standard',
                    ],
                ]),
            ])
            ->assertRedirect();

        $invoice = FinanceInvoice::withoutGlobalScopes()->firstOrFail();
        $this->assertNull($invoice->customer_id);
        $this->assertSame('عميل نقدي', $invoice->customer_name);
        $this->assertSame('115.00', (string) $invoice->total);
    }

    public function test_finance_invoice_isolated_per_workspace(): void
    {
        [$userA, $workspaceA] = $this->createWorkspaceOwner('company');
        [, $workspaceB] = $this->createWorkspaceOwner('store');

        $invoiceB = FinanceInvoice::withoutGlobalScopes()->create([
            'workspace_id' => $workspaceB->id,
            'invoice_number' => 'INV-B-000001',
            'type' => 'sales',
            'status' => 'unpaid',
            'invoice_status' => 'issued',
            'payment_status' => 'unpaid',
            'issue_date' => now()->toDateString(),
            'currency' => 'SAR',
            'subtotal' => 100,
            'discount' => 0,
            'taxable_amount' => 100,
            'tax_amount' => 15,
            'total' => 115,
            'amount_paid' => 0,
            'amount_due' => 115,
            'tax_profile_type' => 'standard',
            'tax_rate' => 15,
        ]);

        $this->actingAs($userA)
            ->withSession(['current_workspace_id' => $workspaceA->id])
            ->get(route('workspace.finance.invoices.show', $invoiceB))
            ->assertNotFound();
    }

    public function test_accounting_service_rejects_unbalanced_entry(): void
    {
        [, $workspace] = $this->createWorkspaceOwner('company');
        $chart = app(ChartOfAccountsService::class);
        $chart->ensureDefaultAccounts($workspace);

        $ar = FinanceAccount::withoutGlobalScopes()
            ->where('workspace_id', $workspace->id)
            ->where('code', '1200')
            ->firstOrFail();

        $sales = FinanceAccount::withoutGlobalScopes()
            ->where('workspace_id', $workspace->id)
            ->where('code', '4000')
            ->firstOrFail();

        $service = app(AccountingService::class);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Journal entry is not balanced');

        $service->createEntry(
            workspaceId: $workspace->id,
            entryDate: now()->toDateString(),
            type: 'manual',
            lines: [
                ['account_id' => $ar->id, 'debit' => 100, 'credit' => 0],
                ['account_id' => $sales->id, 'debit' => 0, 'credit' => 90],
            ],
        );
    }

    public function test_expense_creation_posts_accounting_entry(): void
    {
        [$user, $workspace] = $this->createWorkspaceOwner('company');

        $this->actingAs($user)
            ->withSession(['current_workspace_id' => $workspace->id])
            ->post(route('workspace.finance.expenses.store'), [
                'expense_date' => now()->toDateString(),
                'amount' => 100,
                'tax_profile_type' => 'standard',
                'tax_rate' => 15,
                'payment_method' => 'cash',
                'status' => 'paid',
            ])
            ->assertRedirect();

        $expense = FinanceExpense::withoutGlobalScopes()->firstOrFail();
        $this->assertSame('100.00', (string) $expense->amount);
        $this->assertSame('15.00', (string) $expense->tax_amount);
        $this->assertSame('115.00', (string) $expense->total);

        $entry = FinanceJournalEntry::withoutGlobalScopes()
            ->where('reference_type', FinanceExpense::class)
            ->where('reference_id', $expense->id)
            ->firstOrFail();
        app(WorkspaceContext::class)->runFor($workspace, fn () => $entry->load('lines'));
        $this->assertEquals((float) $entry->lines->sum('debit'), (float) $entry->lines->sum('credit'));
    }

    public function test_tax_and_payroll_services_calculate_expected_values(): void
    {
        $taxService = app(TaxService::class);
        $standard = $taxService->calculateAmount(1000, 'standard', 15);
        $exempt = $taxService->calculateAmount(1000, 'exempt', 15);

        $this->assertSame(1000.0, $standard['taxable_amount']);
        $this->assertSame(150.0, $standard['tax_amount']);
        $this->assertSame(1150.0, $standard['total']);
        $this->assertSame(0.0, $exempt['tax_amount']);
        $this->assertSame(1000.0, $exempt['total']);

        $payrollService = app(PayrollService::class);
        $payroll = $payrollService->calculate([
            'basic_salary' => 5000,
            'housing_allowance' => 1000,
            'transport_allowance' => 500,
            'other_allowances' => 300,
            'overtime' => 200,
            'bonuses' => 100,
            'deductions' => 250,
            'advances' => 400,
            'absence_deduction' => 150,
        ]);

        $this->assertSame(7100.0, $payroll['gross']);
        $this->assertSame(800.0, $payroll['deductions']);
        $this->assertSame(6300.0, $payroll['net']);
    }

    public function test_finance_access_requires_permission_or_elevated_membership_role(): void
    {
        $owner = User::factory()->create();
        $workspace = Workspace::factory()->create([
            'owner_user_id' => $owner->id,
            'type' => 'company',
        ]);
        $workspace->users()->attach($owner->id, [
            'membership_role' => 'owner',
            'status' => 'active',
            'joined_at' => now(),
        ]);

        WorkspaceFeatureFlag::withoutGlobalScopes()->updateOrCreate(
            ['workspace_id' => $workspace->id, 'feature_key' => 'finance'],
            ['workspace_id' => $workspace->id, 'feature_key' => 'finance', 'enabled' => true, 'source' => 'manual']
        );

        $agent = User::factory()->create();
        $workspace->users()->attach($agent->id, [
            'membership_role' => 'agent',
            'status' => 'active',
            'joined_at' => now(),
        ]);

        $this->actingAs($agent)
            ->withSession(['current_workspace_id' => $workspace->id])
            ->get(route('workspace.finance.dashboard'))
            ->assertForbidden();
    }

    public function test_reports_page_uses_safe_default_dates_when_filters_missing(): void
    {
        [$user, $workspace] = $this->createWorkspaceOwner('company');

        $this->actingAs($user)
            ->withSession(['current_workspace_id' => $workspace->id])
            ->get(route('workspace.finance.reports.index'))
            ->assertOk();
    }

    public function test_sales_module_page_is_functional_with_filters(): void
    {
        [$user, $workspace] = $this->createWorkspaceOwner('company');
        $customer = Customer::withoutGlobalScopes()->create([
            'workspace_id' => $workspace->id,
            'name' => 'عميل المبيعات',
            'phone' => '0501002000',
        ]);

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
                'items_json' => json_encode([
                    [
                        'product_name' => 'خدمة مبيعات',
                        'quantity' => 1,
                        'unit_price' => 100,
                        'discount' => 0,
                        'tax_rate' => 15,
                        'tax_type' => 'standard',
                    ],
                ]),
            ])
            ->assertRedirect();

        $this->actingAs($user)
            ->withSession(['current_workspace_id' => $workspace->id])
            ->get(route('workspace.finance.sales.index', ['payment_status' => 'unpaid', 'customer_id' => $customer->id]))
            ->assertOk()
            ->assertSee('وحدة المبيعات');
    }

    public function test_draft_invoice_keeps_invoice_status_separate_from_payment_status(): void
    {
        [$user, $workspace] = $this->createWorkspaceOwner('company');

        $this->actingAs($user)
            ->withSession(['current_workspace_id' => $workspace->id])
            ->post(route('workspace.finance.invoices.store'), [
                'type' => 'sales',
                'customer_name' => 'Draft Customer',
                'issue_date' => now()->toDateString(),
                'currency' => 'SAR',
                'invoice_status' => 'draft',
                'tax_profile_type' => 'standard',
                'tax_rate' => 15,
                'items_json' => json_encode([
                    [
                        'product_name' => 'تصميم موقع',
                        'quantity' => 1,
                        'unit_price' => 1000,
                        'discount' => 0,
                        'tax_rate' => 15,
                        'tax_type' => 'standard',
                    ],
                ]),
            ])
            ->assertRedirect();

        $invoice = FinanceInvoice::withoutGlobalScopes()->firstOrFail();
        $this->assertSame('draft', $invoice->invoice_status);
        $this->assertSame('unpaid', $invoice->payment_status);
        $this->assertSame('draft', $invoice->status);
    }

    public function test_overdue_status_is_recalculated_for_issued_unpaid_invoices(): void
    {
        [$user, $workspace] = $this->createWorkspaceOwner('company');

        $this->actingAs($user)
            ->withSession(['current_workspace_id' => $workspace->id])
            ->post(route('workspace.finance.invoices.store'), [
                'type' => 'sales',
                'customer_name' => 'Overdue Customer',
                'issue_date' => now()->subDays(20)->toDateString(),
                'due_date' => now()->subDays(5)->toDateString(),
                'currency' => 'SAR',
                'invoice_status' => 'issued',
                'tax_profile_type' => 'standard',
                'tax_rate' => 15,
                'items_json' => json_encode([
                    [
                        'product_name' => 'خدمة استشارية',
                        'quantity' => 1,
                        'unit_price' => 200,
                        'discount' => 0,
                        'tax_rate' => 15,
                        'tax_type' => 'standard',
                    ],
                ]),
            ])
            ->assertRedirect();

        /** @var InvoiceService $invoiceService */
        $invoiceService = app(InvoiceService::class);
        $invoiceService->refreshIssuedPaymentStatuses($workspace->id);

        $invoice = FinanceInvoice::withoutGlobalScopes()->firstOrFail();
        $this->assertSame('issued', $invoice->invoice_status);
        $this->assertSame('overdue', $invoice->payment_status);
        $this->assertSame('overdue', $invoice->status);
    }

    public function test_company_snapshot_is_preserved_after_settings_change(): void
    {
        [$user, $workspace] = $this->createWorkspaceOwner('company');

        FinanceSetting::withoutGlobalScopes()->create([
            'workspace_id' => $workspace->id,
            'company_name' => 'Old Name LLC',
            'currency' => 'SAR',
            'country_code' => 'SA',
            'invoice_prefix' => 'INV',
            'next_invoice_sequence' => 1,
            'default_vat_rate' => 15,
        ]);

        $this->actingAs($user)
            ->withSession(['current_workspace_id' => $workspace->id])
            ->post(route('workspace.finance.invoices.store'), [
                'type' => 'sales',
                'customer_name' => 'Historical Customer',
                'issue_date' => now()->toDateString(),
                'currency' => 'SAR',
                'invoice_status' => 'issued',
                'tax_profile_type' => 'standard',
                'tax_rate' => 15,
                'items_json' => json_encode([
                    [
                        'product_name' => 'Historical Service',
                        'quantity' => 1,
                        'unit_price' => 150,
                        'discount' => 0,
                        'tax_rate' => 15,
                        'tax_type' => 'standard',
                    ],
                ]),
            ])
            ->assertRedirect();

        $invoice = FinanceInvoice::withoutGlobalScopes()->firstOrFail();
        $this->assertSame('Old Name LLC', data_get($invoice->company_snapshot, 'company_name'));

        FinanceSetting::withoutGlobalScopes()
            ->where('workspace_id', $workspace->id)
            ->update(['company_name' => 'New Name LLC']);

        $invoice->refresh();
        $this->assertSame('Old Name LLC', data_get($invoice->company_snapshot, 'company_name'));
    }

    public function test_customer_validation_rejects_cross_workspace_customer_ids(): void
    {
        [$userA, $workspaceA] = $this->createWorkspaceOwner('company');
        [, $workspaceB] = $this->createWorkspaceOwner('company');

        $foreignCustomer = Customer::withoutGlobalScopes()->create([
            'workspace_id' => $workspaceB->id,
            'name' => 'Foreign Customer',
            'phone' => '0503334444',
        ]);

        $response = $this->actingAs($userA)
            ->withSession(['current_workspace_id' => $workspaceA->id])
            ->post(route('workspace.finance.invoices.store'), [
                'type' => 'sales',
                'customer_id' => $foreignCustomer->id,
                'issue_date' => now()->toDateString(),
                'currency' => 'SAR',
                'invoice_status' => 'issued',
                'tax_profile_type' => 'standard',
                'tax_rate' => 15,
                'items_json' => json_encode([
                    [
                        'product_name' => 'Isolation Check',
                        'quantity' => 1,
                        'unit_price' => 100,
                        'discount' => 0,
                        'tax_rate' => 15,
                        'tax_type' => 'standard',
                    ],
                ]),
            ]);

        $response->assertSessionHasErrors('customer_id');
    }

    public function test_supplier_validation_rejects_cross_workspace_supplier_ids(): void
    {
        [$userA, $workspaceA] = $this->createWorkspaceOwner('company');
        [, $workspaceB] = $this->createWorkspaceOwner('company');

        $foreignSupplier = FinanceSupplier::withoutGlobalScopes()->create([
            'workspace_id' => $workspaceB->id,
            'name' => 'Foreign Supplier',
            'status' => 'active',
        ]);

        $response = $this->actingAs($userA)
            ->withSession(['current_workspace_id' => $workspaceA->id])
            ->post(route('workspace.finance.invoices.store'), [
                'type' => 'purchase',
                'supplier_id' => $foreignSupplier->id,
                'issue_date' => now()->toDateString(),
                'currency' => 'SAR',
                'invoice_status' => 'issued',
                'tax_profile_type' => 'standard',
                'tax_rate' => 15,
                'items_json' => json_encode([
                    [
                        'product_name' => 'Supplier Isolation',
                        'quantity' => 1,
                        'unit_price' => 100,
                        'discount' => 0,
                        'tax_rate' => 15,
                        'tax_type' => 'standard',
                    ],
                ]),
            ]);

        $response->assertSessionHasErrors('supplier_id');
    }

    public function test_pdf_endpoint_returns_pdf_binary_when_dependency_is_available(): void
    {
        if (! class_exists(Pdf::class)) {
            $this->markTestSkipped('DomPDF package is not available in current test runtime.');
        }

        [$user, $workspace] = $this->createWorkspaceOwner('company');

        $this->actingAs($user)
            ->withSession(['current_workspace_id' => $workspace->id])
            ->post(route('workspace.finance.invoices.store'), [
                'type' => 'sales',
                'customer_name' => 'PDF Customer',
                'issue_date' => now()->toDateString(),
                'currency' => 'SAR',
                'invoice_status' => 'issued',
                'tax_profile_type' => 'standard',
                'tax_rate' => 15,
                'items_json' => json_encode([
                    [
                        'product_name' => 'عقد صيانة',
                        'description' => str_repeat('وصف طويل ', 10),
                        'quantity' => 1,
                        'unit_price' => 500,
                        'discount' => 0,
                        'tax_rate' => 15,
                        'tax_type' => 'standard',
                    ],
                ]),
            ])
            ->assertRedirect();

        $invoice = FinanceInvoice::withoutGlobalScopes()->firstOrFail();

        $response = $this->actingAs($user)
            ->withSession(['current_workspace_id' => $workspace->id])
            ->get(route('workspace.finance.invoices.pdf', $invoice));

        $response->assertOk();
        $this->assertStringContainsString('application/pdf', (string) $response->headers->get('content-type'));
    }

    public function test_repeated_payment_reference_is_idempotent_and_prevents_duplicate_entries(): void
    {
        [$user, $workspace] = $this->createWorkspaceOwner('company');
        $customer = Customer::withoutGlobalScopes()->create([
            'workspace_id' => $workspace->id,
            'name' => 'Ref Customer',
            'phone' => '0506007000',
        ]);

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
                'items_json' => json_encode([
                    [
                        'product_name' => 'Idempotent Payment Service',
                        'quantity' => 1,
                        'unit_price' => 200,
                        'discount' => 0,
                        'tax_rate' => 15,
                        'tax_type' => 'standard',
                    ],
                ]),
            ])
            ->assertRedirect();

        $invoice = FinanceInvoice::withoutGlobalScopes()->firstOrFail();

        $payload = [
            'payment_date' => now()->toDateString(),
            'amount' => 100,
            'method' => 'cash',
            'reference' => 'TXN-REF-123',
        ];

        $this->actingAs($user)
            ->withSession(['current_workspace_id' => $workspace->id])
            ->post(route('workspace.finance.invoices.payments.store', $invoice), $payload)
            ->assertRedirect();

        $this->actingAs($user)
            ->withSession(['current_workspace_id' => $workspace->id])
            ->post(route('workspace.finance.invoices.payments.store', $invoice), $payload)
            ->assertRedirect();

        $invoice->refresh();
        $payments = $invoice->payments()->withoutGlobalScopes()->where('reference', 'TXN-REF-123')->get();
        $this->assertCount(1, $payments);
        $this->assertSame('100.00', (string) $invoice->amount_paid);

        $paymentEntryCount = FinanceJournalEntry::withoutGlobalScopes()
            ->where('workspace_id', $workspace->id)
            ->where('type', 'invoice_payment')
            ->where('reference_type', FinanceInvoicePayment::class)
            ->where('reference_id', $payments->first()->id)
            ->count();
        $this->assertSame(1, $paymentEntryCount);
    }

    public function test_closed_period_blocks_issued_invoice_but_allows_draft(): void
    {
        [$user, $workspace] = $this->createWorkspaceOwner('company');
        $today = now()->toDateString();

        $year = FinanceFiscalYear::withoutGlobalScopes()->create([
            'workspace_id' => $workspace->id,
            'name' => 'FY-CLOSED-TEST',
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

        $issuedResponse = $this->actingAs($user)
            ->withSession(['current_workspace_id' => $workspace->id])
            ->post(route('workspace.finance.invoices.store'), [
                'type' => 'sales',
                'customer_name' => 'Closed Period Issued',
                'issue_date' => $today,
                'currency' => 'SAR',
                'invoice_status' => 'issued',
                'tax_profile_type' => 'standard',
                'tax_rate' => 15,
                'items_json' => json_encode([
                    [
                        'product_name' => 'Issued in Closed Period',
                        'quantity' => 1,
                        'unit_price' => 100,
                        'discount' => 0,
                        'tax_rate' => 15,
                        'tax_type' => 'standard',
                    ],
                ]),
            ]);

        $issuedResponse->assertRedirect();
        $issuedResponse->assertSessionHas('error');

        $draftResponse = $this->actingAs($user)
            ->withSession(['current_workspace_id' => $workspace->id])
            ->post(route('workspace.finance.invoices.store'), [
                'type' => 'sales',
                'customer_name' => 'Closed Period Draft',
                'issue_date' => $today,
                'currency' => 'SAR',
                'invoice_status' => 'draft',
                'tax_profile_type' => 'standard',
                'tax_rate' => 15,
                'items_json' => json_encode([
                    [
                        'product_name' => 'Draft in Closed Period',
                        'quantity' => 1,
                        'unit_price' => 100,
                        'discount' => 0,
                        'tax_rate' => 15,
                        'tax_type' => 'standard',
                    ],
                ]),
            ]);

        $draftResponse->assertRedirect();
        $draftInvoice = FinanceInvoice::withoutGlobalScopes()
            ->where('workspace_id', $workspace->id)
            ->where('customer_name', 'Closed Period Draft')
            ->first();

        $this->assertNotNull($draftInvoice);
        $this->assertSame('draft', $draftInvoice->invoice_status);
    }

    public function test_price_lists_module_allows_create_and_add_items_and_isolated_by_workspace(): void
    {
        [$userA, $workspaceA] = $this->createWorkspaceOwner('company');
        [$userB, $workspaceB] = $this->createWorkspaceOwner('store');

        $this->actingAs($userA)
            ->withSession(['current_workspace_id' => $workspaceA->id])
            ->post(route('workspace.finance.price-lists.store'), [
                'name' => 'قائمة جملة',
                'currency' => 'SAR',
            ])
            ->assertRedirect();

        $listA = FinancePriceList::withoutGlobalScopes()
            ->where('workspace_id', $workspaceA->id)
            ->where('name', 'قائمة جملة')
            ->firstOrFail();

        $this->actingAs($userA)
            ->withSession(['current_workspace_id' => $workspaceA->id])
            ->post(route('workspace.finance.price-lists.items.store', $listA), [
                'product_name' => 'خدمة دعم',
                'price' => 250,
                'min_quantity' => 1,
                'tax_rate' => 15,
            ])
            ->assertRedirect();

        $listA->refresh();
        $this->assertSame(1, app(WorkspaceContext::class)->runFor($workspaceA, fn () => $listA->items()->count()));

        $listB = FinancePriceList::withoutGlobalScopes()->create([
            'workspace_id' => $workspaceB->id,
            'name' => 'قائمة خاصة B',
            'currency' => 'SAR',
            'status' => 'draft',
        ]);

        $this->actingAs($userA)
            ->withSession(['current_workspace_id' => $workspaceA->id])
            ->put(route('workspace.finance.price-lists.update', $listB), [
                'name' => 'اختراق',
                'currency' => 'SAR',
            ])
            ->assertNotFound();
    }

    public function test_payroll_adjustment_posting_creates_journal_entry(): void
    {
        [$owner, $workspace] = $this->createWorkspaceOwner('company');
        $financeEmployee = FinanceEmployee::withoutGlobalScopes()->create([
            'workspace_id' => $workspace->id,
            'employee_code' => 'FIN-EMP-1001',
            'full_name' => 'موظف شركة للاختبار',
            'job_title' => 'محاسب',
            'basic_salary' => 5000,
            'status' => 'active',
            'created_by' => $owner->id,
        ]);

        $this->actingAs($owner)
            ->withSession(['current_workspace_id' => $workspace->id])
            ->post(route('workspace.finance.payroll-adjustments.store'), [
                'type' => 'bonus',
                'finance_employee_id' => $financeEmployee->id,
                'title' => 'مكافأة أداء',
                'amount' => 500,
                'effective_date' => now()->toDateString(),
                'status' => 'approved',
            ])
            ->assertRedirect();

        $adjustment = FinancePayrollAdjustment::withoutGlobalScopes()->firstOrFail();

        $this->actingAs($owner)
            ->withSession(['current_workspace_id' => $workspace->id])
            ->post(route('workspace.finance.payroll-adjustments.post', $adjustment))
            ->assertRedirect();

        $adjustment->refresh();
        $this->assertSame('posted', $adjustment->status);
        $this->assertNotNull($adjustment->posted_journal_entry_id);

        $entry = FinanceJournalEntry::withoutGlobalScopes()->findOrFail($adjustment->posted_journal_entry_id);
        app(WorkspaceContext::class)->runFor($workspace, fn () => $entry->load('lines'));
        $this->assertEquals((float) $entry->lines->sum('debit'), (float) $entry->lines->sum('credit'));
    }

    public function test_salary_advance_issue_and_repayments_update_status_and_balances(): void
    {
        [$owner, $workspace] = $this->createWorkspaceOwner('company');
        $financeEmployee = FinanceEmployee::withoutGlobalScopes()->create([
            'workspace_id' => $workspace->id,
            'employee_code' => 'FIN-EMP-1002',
            'full_name' => 'موظف سلف',
            'job_title' => 'موظف مالي',
            'basic_salary' => 4500,
            'status' => 'active',
            'created_by' => $owner->id,
        ]);

        $this->actingAs($owner)
            ->withSession(['current_workspace_id' => $workspace->id])
            ->post(route('workspace.finance.salary-advances.store'), [
                'finance_employee_id' => $financeEmployee->id,
                'amount' => 500,
                'issued_at' => now()->toDateString(),
                'type' => 'salary_advance',
                'payment_method' => 'cash',
            ])
            ->assertRedirect();

        $advance = FinanceSalaryAdvance::withoutGlobalScopes()->firstOrFail();
        $this->assertSame('open', $advance->status);
        $this->assertSame('500.00', (string) $advance->remaining_amount);

        $this->actingAs($owner)
            ->withSession(['current_workspace_id' => $workspace->id])
            ->post(route('workspace.finance.salary-advances.repay', $advance), [
                'payment_date' => now()->toDateString(),
                'amount' => 200,
                'method' => 'cash',
            ])
            ->assertRedirect();

        $advance->refresh();
        $this->assertSame('open', $advance->status);
        $this->assertSame('300.00', (string) $advance->remaining_amount);

        $this->actingAs($owner)
            ->withSession(['current_workspace_id' => $workspace->id])
            ->post(route('workspace.finance.salary-advances.repay', $advance), [
                'payment_date' => now()->toDateString(),
                'amount' => 300,
                'method' => 'payroll_deduction',
            ])
            ->assertRedirect();

        $advance->refresh();
        $this->assertSame('closed', $advance->status);
        $this->assertSame('0.00', (string) $advance->remaining_amount);
        $this->assertSame(2, FinanceSalaryAdvanceRepayment::withoutGlobalScopes()->count());
    }

    public function test_fiscal_year_module_prevents_overlapping_year_ranges(): void
    {
        [$user, $workspace] = $this->createWorkspaceOwner('company');

        $this->actingAs($user)
            ->withSession(['current_workspace_id' => $workspace->id])
            ->post(route('workspace.finance.fiscal-years.store'), [
                'name' => 'FY-2030',
                'start_date' => '2030-01-01',
                'end_date' => '2030-12-31',
            ])
            ->assertRedirect();

        $response = $this->actingAs($user)
            ->withSession(['current_workspace_id' => $workspace->id])
            ->post(route('workspace.finance.fiscal-years.store'), [
                'name' => 'FY-2030-overlap',
                'start_date' => '2030-06-01',
                'end_date' => '2031-05-31',
            ]);

        $response->assertRedirect();
        $response->assertSessionHas('error');

        $this->assertFalse(FinanceFiscalYear::withoutGlobalScopes()
            ->where('workspace_id', $workspace->id)
            ->where('name', 'FY-2030-overlap')
            ->exists());
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
