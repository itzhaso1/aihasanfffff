<?php

namespace Tests\Feature\Feature\Finance;

use App\Models\Contract\Contract;
use App\Models\Customer;
use App\Models\Finance\FinanceBillingSchedule;
use App\Models\Finance\FinanceCreditNote;
use App\Models\Finance\FinanceInvoice;
use App\Models\Finance\FinanceInvoicePayment;
use App\Models\Finance\FinanceJournalEntry;
use App\Models\Plan;
use App\Models\Subscription;
use App\Models\User;
use App\Models\Workspace;
use App\Models\WorkspaceFeatureFlag;
use App\Services\Finance\CreditNoteService;
use App\Services\Finance\InvoicePaymentService;
use App\Services\Finance\InvoiceService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Tests\TestCase;

class BillingLifecycleTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Notification::fake();
    }

    public function test_draft_invoice_can_be_updated_then_issued_with_journal_entry(): void
    {
        [$user, $workspace] = $this->createWorkspaceOwner('company');
        $customer = $this->makeCustomer($workspace, 'Draft Customer');

        $this->actingAs($user)
            ->withSession(['current_workspace_id' => $workspace->id])
            ->post(route('workspace.finance.invoices.store'), $this->invoicePayload($customer->id, [
                'invoice_status' => 'draft',
                'unit_price' => 100,
            ]))
            ->assertRedirect();

        $invoice = FinanceInvoice::withoutGlobalScopes()->firstOrFail();
        $this->assertSame('draft', $invoice->invoice_status);
        $this->assertSame(0, FinanceJournalEntry::withoutGlobalScopes()->count());

        $this->actingAs($user)
            ->withSession(['current_workspace_id' => $workspace->id])
            ->put(route('workspace.finance.invoices.update', $invoice), $this->invoicePayload($customer->id, [
                'invoice_status' => 'draft',
                'unit_price' => 200,
                'discount' => 20,
            ]))
            ->assertRedirect();

        $invoice->refresh();
        $this->assertSame('200.00', (string) $invoice->subtotal);
        $this->assertSame('20.00', (string) $invoice->discount);
        $this->assertSame('27.00', (string) $invoice->tax_amount);
        $this->assertSame('207.00', (string) $invoice->total);

        $this->actingAs($user)
            ->withSession(['current_workspace_id' => $workspace->id])
            ->post(route('workspace.finance.invoices.issue', $invoice))
            ->assertRedirect();

        $invoice->refresh();
        $this->assertSame('issued', $invoice->invoice_status);
        $this->assertSame('unpaid', $invoice->payment_status);
        $this->assertNotNull(
            FinanceJournalEntry::withoutGlobalScopes()
                ->where('reference_type', FinanceInvoice::class)
                ->where('reference_id', $invoice->id)
                ->where('type', 'sales_invoice')
                ->first()
        );
    }

    public function test_overpayment_and_draft_payment_are_blocked(): void
    {
        [$user, $workspace] = $this->createWorkspaceOwner('company');
        $customer = $this->makeCustomer($workspace, 'Pay Customer');

        $this->actingAs($user)
            ->withSession(['current_workspace_id' => $workspace->id])
            ->post(route('workspace.finance.invoices.store'), $this->invoicePayload($customer->id, [
                'invoice_status' => 'draft',
                'unit_price' => 100,
            ]))
            ->assertRedirect();

        $draft = FinanceInvoice::withoutGlobalScopes()->firstOrFail();
        $this->actingAs($user)
            ->withSession(['current_workspace_id' => $workspace->id])
            ->post(route('workspace.finance.invoices.payments.store', $draft), [
                'payment_date' => now()->toDateString(),
                'amount' => 10,
                'method' => 'cash',
            ])
            ->assertRedirect();
        $this->assertSame(0, FinanceInvoicePayment::withoutGlobalScopes()->count());

        $this->inWorkspace($workspace, function () use ($draft, $user): void {
            app(InvoiceService::class)->issue($draft, (int) $user->id);
        });
        $issued = $draft->fresh();

        $this->actingAs($user)
            ->withSession(['current_workspace_id' => $workspace->id])
            ->post(route('workspace.finance.invoices.payments.store', $issued), [
                'payment_date' => now()->toDateString(),
                'amount' => 999,
                'method' => 'cash',
            ])
            ->assertSessionHas('error');

        $issued->refresh();
        $this->assertSame('0.00', (string) $issued->amount_paid);
        $this->assertSame('unpaid', $issued->payment_status);
    }

    public function test_payment_reference_is_idempotent_and_cancel_requires_reversal_first(): void
    {
        [$user, $workspace] = $this->createWorkspaceOwner('company');
        $customer = $this->makeCustomer($workspace, 'Idempotent Customer');

        $this->actingAs($user)
            ->withSession(['current_workspace_id' => $workspace->id])
            ->post(route('workspace.finance.invoices.store'), $this->invoicePayload($customer->id, [
                'unit_price' => 100,
            ]))
            ->assertRedirect();

        $invoice = FinanceInvoice::withoutGlobalScopes()->firstOrFail();
        $payload = [
            'payment_date' => now()->toDateString(),
            'amount' => 50,
            'method' => 'cash',
            'reference' => 'TXN-50',
        ];

        $this->actingAs($user)
            ->withSession(['current_workspace_id' => $workspace->id])
            ->post(route('workspace.finance.invoices.payments.store', $invoice), $payload)
            ->assertRedirect();

        $this->actingAs($user)
            ->withSession(['current_workspace_id' => $workspace->id])
            ->post(route('workspace.finance.invoices.payments.store', $invoice), $payload)
            ->assertRedirect();

        $this->assertSame(1, FinanceInvoicePayment::withoutGlobalScopes()->count());
        $invoice->refresh();
        $this->assertSame('50.00', (string) $invoice->amount_paid);
        $this->assertSame('partial', $invoice->payment_status);

        $this->actingAs($user)
            ->withSession(['current_workspace_id' => $workspace->id])
            ->post(route('workspace.finance.invoices.cancel', $invoice))
            ->assertSessionHas('error');

        $this->assertSame('issued', $invoice->fresh()->invoice_status);

        $payment = FinanceInvoicePayment::withoutGlobalScopes()->firstOrFail();
        $this->actingAs($user)
            ->withSession(['current_workspace_id' => $workspace->id])
            ->post(route('workspace.finance.invoices.payments.reverse', [$invoice, $payment]), [
                'reversal_reason' => 'test reverse',
            ])
            ->assertRedirect();

        $this->assertSame('reversed', $payment->fresh()->status);
        $this->assertSame('0.00', (string) $invoice->fresh()->amount_paid);

        $this->actingAs($user)
            ->withSession(['current_workspace_id' => $workspace->id])
            ->post(route('workspace.finance.invoices.cancel', $invoice))
            ->assertRedirect();

        $invoice->refresh();
        $this->assertSame('cancelled', $invoice->invoice_status);
        $this->assertNotNull(
            FinanceJournalEntry::withoutGlobalScopes()
                ->where('type', 'invoice_reversal')
                ->where('reference_type', FinanceInvoice::class)
                ->where('reference_id', $invoice->id)
                ->first()
        );
        $this->assertSame(
            1,
            FinanceJournalEntry::withoutGlobalScopes()->where('type', 'sales_invoice')->count()
        );
    }

    public function test_due_today_is_not_overdue_and_past_due_is_overdue(): void
    {
        [$user, $workspace] = $this->createWorkspaceOwner('company');
        $customer = $this->makeCustomer($workspace, 'Due Customer');

        $this->actingAs($user)
            ->withSession(['current_workspace_id' => $workspace->id])
            ->post(route('workspace.finance.invoices.store'), $this->invoicePayload($customer->id, [
                'due_date' => now()->toDateString(),
            ]))
            ->assertRedirect();

        $todayInvoice = FinanceInvoice::withoutGlobalScopes()->firstOrFail();
        $this->inWorkspace($workspace, function () use ($workspace): void {
            app(InvoiceService::class)->refreshIssuedPaymentStatuses($workspace->id);
        });
        $this->assertSame('unpaid', $todayInvoice->fresh()->payment_status);

        $this->actingAs($user)
            ->withSession(['current_workspace_id' => $workspace->id])
            ->post(route('workspace.finance.invoices.store'), $this->invoicePayload($customer->id, [
                'due_date' => now()->subDay()->toDateString(),
                'issue_date' => now()->subDays(3)->toDateString(),
            ]))
            ->assertRedirect();

        $this->inWorkspace($workspace, function () use ($workspace): void {
            app(InvoiceService::class)->refreshIssuedPaymentStatuses($workspace->id);
        });
        $overdue = FinanceInvoice::withoutGlobalScopes()->orderByDesc('id')->firstOrFail();
        $this->assertSame('overdue', $overdue->payment_status);
        $this->assertSame('overdue', $overdue->status);
    }

    public function test_credit_note_is_separate_document_and_reduces_amount_due(): void
    {
        [$user, $workspace] = $this->createWorkspaceOwner('company');
        $customer = $this->makeCustomer($workspace, 'Credit Customer');

        $this->actingAs($user)
            ->withSession(['current_workspace_id' => $workspace->id])
            ->post(route('workspace.finance.invoices.store'), $this->invoicePayload($customer->id, [
                'unit_price' => 100,
            ]))
            ->assertRedirect();

        $invoice = FinanceInvoice::withoutGlobalScopes()->firstOrFail();
        $this->assertSame('115.00', (string) $invoice->amount_due);

        $this->actingAs($user)
            ->withSession(['current_workspace_id' => $workspace->id])
            ->post(route('workspace.finance.invoices.credit-notes.store', $invoice), [
                'type' => 'credit',
                'reason' => 'خصم تعويض',
                'issue_date' => now()->toDateString(),
                'status' => 'issued',
                'items_json' => json_encode([
                    [
                        'product_name' => 'تعويض',
                        'quantity' => 1,
                        'unit_price' => 20,
                        'discount' => 0,
                        'tax_rate' => 15,
                    ],
                ]),
            ])
            ->assertRedirect();

        $note = FinanceCreditNote::withoutGlobalScopes()->firstOrFail();
        $this->assertSame('issued', $note->status);
        $this->assertSame('23.00', (string) $note->total);
        $this->assertNotSame($invoice->invoice_number, $note->note_number);

        $invoice->refresh();
        $this->assertSame('23.00', (string) $invoice->amount_credited);
        $this->assertSame('92.00', (string) $invoice->amount_due);
        $this->assertSame('unpaid', $invoice->payment_status);

        $this->assertNotNull(
            FinanceJournalEntry::withoutGlobalScopes()
                ->where('type', 'credit_note')
                ->where('reference_type', FinanceCreditNote::class)
                ->where('reference_id', $note->id)
                ->first()
        );
    }

    public function test_credit_note_cannot_exceed_remaining_balance(): void
    {
        [$user, $workspace] = $this->createWorkspaceOwner('company');
        $customer = $this->makeCustomer($workspace, 'Overflow Credit');

        $this->actingAs($user)
            ->withSession(['current_workspace_id' => $workspace->id])
            ->post(route('workspace.finance.invoices.store'), $this->invoicePayload($customer->id, [
                'unit_price' => 100,
            ]))
            ->assertRedirect();

        $invoice = FinanceInvoice::withoutGlobalScopes()->firstOrFail();

        $this->actingAs($user)
            ->withSession(['current_workspace_id' => $workspace->id])
            ->post(route('workspace.finance.invoices.credit-notes.store', $invoice), [
                'type' => 'credit',
                'reason' => 'أكبر من المتبقي',
                'issue_date' => now()->toDateString(),
                'status' => 'issued',
                'items_json' => json_encode([
                    [
                        'product_name' => 'مبالغة',
                        'quantity' => 1,
                        'unit_price' => 1000,
                        'discount' => 0,
                        'tax_rate' => 15,
                    ],
                ]),
            ])
            ->assertSessionHas('error');

        $this->assertSame(0, FinanceCreditNote::withoutGlobalScopes()->count());
        $this->assertSame('115.00', (string) $invoice->fresh()->amount_due);
    }

    public function test_workspace_isolation_blocks_foreign_invoice_and_statement(): void
    {
        [$userA, $workspaceA] = $this->createWorkspaceOwner('company');
        [, $workspaceB] = $this->createWorkspaceOwner('store');
        $customerB = $this->makeCustomer($workspaceB, 'Other WS Customer');

        $invoiceB = FinanceInvoice::withoutGlobalScopes()->create([
            'workspace_id' => $workspaceB->id,
            'customer_id' => $customerB->id,
            'invoice_number' => 'INV-B-ISO-1',
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

        $this->actingAs($userA)
            ->withSession(['current_workspace_id' => $workspaceA->id])
            ->post(route('workspace.finance.invoices.payments.store', $invoiceB), [
                'payment_date' => now()->toDateString(),
                'amount' => 10,
                'method' => 'cash',
            ])
            ->assertNotFound();

        $this->actingAs($userA)
            ->withSession(['current_workspace_id' => $workspaceA->id])
            ->get(route('workspace.finance.statements.show', [
                'customer_id' => $customerB->id,
                'from' => now()->startOfMonth()->toDateString(),
                'to' => now()->toDateString(),
            ]))
            ->assertSessionHasErrors('customer_id');
    }

    public function test_agent_cannot_create_invoice_without_permission(): void
    {
        [$owner, $workspace] = $this->createWorkspaceOwner('company');
        $agent = User::factory()->create();
        $workspace->users()->attach($agent->id, [
            'membership_role' => 'agent',
            'status' => 'active',
            'joined_at' => now(),
        ]);
        $customer = $this->makeCustomer($workspace, 'Agent Blocked');

        $this->actingAs($agent)
            ->withSession(['current_workspace_id' => $workspace->id])
            ->post(route('workspace.finance.invoices.store'), $this->invoicePayload($customer->id))
            ->assertForbidden();

        $this->actingAs($owner)
            ->withSession(['current_workspace_id' => $workspace->id])
            ->get(route('workspace.finance.billing.dashboard'))
            ->assertOk();
    }

    public function test_contract_billing_schedule_generates_finance_invoice_without_gateway_charge(): void
    {
        [$user, $workspace] = $this->createWorkspaceOwner('company');
        $customer = $this->makeCustomer($workspace, 'Contract Customer');

        $this->actingAs($user)
            ->withSession(['current_workspace_id' => $workspace->id])
            ->post(route('workspace.finance.contracts.store'), [
                'title' => 'عقد خدمات',
                'customer_id' => $customer->id,
                'value' => 230,
                'currency' => 'SAR',
                'start_date' => now()->toDateString(),
                'items' => [
                    ['title' => 'خدمة شهرية', 'quantity' => 1, 'unit_price' => 230],
                ],
            ])
            ->assertRedirect();

        $contract = Contract::withoutGlobalScopes()->firstOrFail();
        $this->actingAs($user)
            ->withSession(['current_workspace_id' => $workspace->id])
            ->post(route('workspace.finance.contracts.activate', $contract))
            ->assertRedirect();

        $this->actingAs($user)
            ->withSession(['current_workspace_id' => $workspace->id])
            ->post(route('workspace.finance.contracts.billing-schedules.store', $contract), [
                'title' => 'قسط أول',
                'frequency' => 'installment',
                'total_occurrences' => 2,
                'start_date' => now()->toDateString(),
            ])
            ->assertRedirect();

        $schedule = FinanceBillingSchedule::withoutGlobalScopes()->firstOrFail();
        $this->assertSame('draft', $schedule->status);

        $this->actingAs($user)
            ->withSession(['current_workspace_id' => $workspace->id])
            ->post(route('workspace.finance.contracts.billing-schedules.activate', [$contract, $schedule]))
            ->assertRedirect();

        $this->actingAs($user)
            ->withSession(['current_workspace_id' => $workspace->id])
            ->post(route('workspace.finance.contracts.billing-schedules.generate', [$contract, $schedule]))
            ->assertRedirect();

        $invoice = FinanceInvoice::withoutGlobalScopes()->where('contract_id', $contract->id)->firstOrFail();
        $this->assertSame($schedule->id, $invoice->billing_schedule_id);
        $this->assertSame($customer->id, $invoice->customer_id);
        $this->assertGreaterThan(0, (float) $invoice->total);
        $this->assertSame(0, FinanceInvoicePayment::withoutGlobalScopes()->count());
    }

    public function test_customer_statement_includes_invoice_payment_and_credit(): void
    {
        [$user, $workspace] = $this->createWorkspaceOwner('company');
        $customer = $this->makeCustomer($workspace, 'Statement Customer');

        $this->actingAs($user)
            ->withSession(['current_workspace_id' => $workspace->id])
            ->post(route('workspace.finance.invoices.store'), $this->invoicePayload($customer->id, [
                'unit_price' => 100,
            ]))
            ->assertRedirect();

        $invoice = FinanceInvoice::withoutGlobalScopes()->firstOrFail();
        $this->inWorkspace($workspace, function () use ($workspace, $invoice, $user): void {
            app(InvoicePaymentService::class)->recordPayment($invoice, [
                'amount' => 50,
                'method' => 'cash',
                'payment_date' => now()->toDateString(),
            ], (int) $user->id);

            app(CreditNoteService::class)->create($workspace, $invoice->fresh(), [
                'type' => 'credit',
                'reason' => 'تسوية',
                'issue_date' => now()->toDateString(),
                'status' => 'issued',
                'items' => [[
                    'product_name' => 'تسوية',
                    'quantity' => 1,
                    'unit_price' => 20,
                    'discount' => 0,
                    'tax_rate' => 15,
                ]],
            ], (int) $user->id);
        });

        $this->actingAs($user)
            ->withSession(['current_workspace_id' => $workspace->id])
            ->get(route('workspace.finance.statements.show', [
                'customer_id' => $customer->id,
                'from' => now()->subDay()->toDateString(),
                'to' => now()->toDateString(),
            ]))
            ->assertOk()
            ->assertSee('Statement Customer')
            ->assertSee('فاتورة مبيعات')
            ->assertSee('إشعار دائن');
    }

    /**
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    private function invoicePayload(int $customerId, array $overrides = []): array
    {
        $unitPrice = $overrides['unit_price'] ?? 100;
        $discount = $overrides['discount'] ?? 0;

        return [
            'type' => 'sales',
            'customer_id' => $customerId,
            'issue_date' => $overrides['issue_date'] ?? now()->toDateString(),
            'due_date' => $overrides['due_date'] ?? now()->addDays(10)->toDateString(),
            'currency' => 'SAR',
            'invoice_status' => $overrides['invoice_status'] ?? 'issued',
            'tax_profile_type' => 'standard',
            'tax_rate' => 15,
            'items_json' => json_encode([
                [
                    'product_name' => 'خدمة فوترة',
                    'description' => 'بند اختبار',
                    'quantity' => 1,
                    'unit_price' => $unitPrice,
                    'discount' => $discount,
                    'tax_rate' => 15,
                    'tax_type' => 'standard',
                ],
            ]),
        ];
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
