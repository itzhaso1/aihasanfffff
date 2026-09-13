<?php

namespace Tests\Feature\Feature\Finance;

use App\Models\AuditLog;
use App\Models\Customer;
use App\Models\Finance\FinanceInvoice;
use App\Models\Finance\FinanceInvoiceItem;
use App\Models\Finance\FinanceJournalEntry;
use App\Models\Finance\FinanceSetting;
use App\Models\Finance\FinanceSupplier;
use App\Models\Plan;
use App\Models\Subscription;
use App\Models\User;
use App\Models\Workspace;
use App\Models\WorkspaceFeatureFlag;
use App\Services\Finance\CreditNoteService;
use App\Services\Finance\Tax\TaxCalculationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Storage;
use RuntimeException;
use Tests\TestCase;

class InvoiceDomainHardeningTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Notification::fake();
    }

    public function test_draft_invoice_can_be_edited(): void
    {
        [$user, $workspace] = $this->createWorkspaceOwner('company');
        $customer = $this->makeCustomer($workspace, 'Draft Customer');

        $this->actingAs($user)
            ->withSession(['current_workspace_id' => $workspace->id])
            ->post(route('workspace.finance.invoices.store'), $this->invoicePayload($customer->id, [
                'invoice_status' => 'draft',
            ]))
            ->assertRedirect();

        $invoice = FinanceInvoice::withoutGlobalScopes()->firstOrFail();
        $this->assertSame('draft', $invoice->invoice_status);
        $this->assertNull($invoice->issued_at);

        $this->actingAs($user)
            ->withSession(['current_workspace_id' => $workspace->id])
            ->put(route('workspace.finance.invoices.update', $invoice), $this->invoicePayload($customer->id, [
                'invoice_status' => 'draft',
                'unit_price' => 80,
                'tax_document_subtype' => 'simplified',
            ]))
            ->assertRedirect();

        $invoice->refresh();
        $this->assertSame('draft', $invoice->invoice_status);
        $this->assertSame('80.00', (string) $invoice->subtotal);
        $this->assertSame('simplified', $invoice->tax_document_subtype);
        $this->assertSame('standard', $invoice->items->first()?->tax_profile_type);
    }

    public function test_issued_invoice_financial_content_is_immutable(): void
    {
        [$user, $workspace] = $this->createWorkspaceOwner('company');
        $customer = $this->makeCustomer($workspace, 'Locked Customer', ['vat_number' => '300000000000003']);
        $invoice = $this->storeIssuedInvoice($user, $workspace, $customer);

        $originalNumber = $invoice->invoice_number;
        $originalTotal = (string) $invoice->total;
        $originalSnapshot = $invoice->company_snapshot;
        $originalRecipient = $invoice->recipient_snapshot;
        $originalTaxRate = (string) $invoice->tax_rate;

        $this->actingAs($user)
            ->withSession(['current_workspace_id' => $workspace->id])
            ->put(route('workspace.finance.invoices.update', $invoice), $this->invoicePayload($customer->id, [
                'unit_price' => 999,
            ]))
            ->assertRedirect();

        $invoice->refresh();
        $this->assertSame($originalTotal, (string) $invoice->total);
        $this->assertSame($originalNumber, $invoice->invoice_number);

        $this->assertThrowsOnLock($invoice, ['customer_id' => null, 'customer_name' => 'Hacked']);
        $this->assertThrowsOnLock($invoice, ['invoice_number' => 'HACK-999']);
        $this->assertThrowsOnLock($invoice, ['total' => 1, 'subtotal' => 1, 'tax_amount' => 0]);
        $this->assertThrowsOnLock($invoice, ['tax_rate' => 0, 'tax_profile_type' => 'exempt']);
        $this->assertThrowsOnLock($invoice, ['company_snapshot' => ['company_name' => 'Forged Co']]);
        $this->assertThrowsOnLock($invoice, ['recipient_snapshot' => ['name' => 'Forged Buyer', 'vat_number' => '000']]);

        $line = $invoice->items()->firstOrFail();
        $this->expectException(RuntimeException::class);
        $line->update(['unit_price' => 1, 'total' => 1]);
    }

    public function test_issued_invoice_snapshots_stay_authoritative_after_live_profile_changes(): void
    {
        [$user, $workspace] = $this->createWorkspaceOwner('company');
        $customer = $this->makeCustomer($workspace, 'Snapshot Customer', ['vat_number' => '300111111111113']);
        $this->actingAs($user)->withSession(['current_workspace_id' => $workspace->id])
            ->get(route('workspace.finance.settings.index'))
            ->assertOk();
        FinanceSetting::withoutGlobalScopes()
            ->where('workspace_id', $workspace->id)
            ->update(['company_name' => 'Issued Co', 'vat_number' => '310000000000003']);

        $invoice = $this->storeIssuedInvoice($user, $workspace, $customer);
        $this->assertSame('Issued Co', data_get($invoice->company_snapshot, 'company_name'));
        $this->assertSame('300111111111113', data_get($invoice->recipient_snapshot, 'vat_number'));

        $customer->update(['vat_number' => '399999999999993', 'name' => 'Changed Customer']);
        FinanceSetting::withoutGlobalScopes()
            ->where('workspace_id', $workspace->id)
            ->update(['company_name' => 'Tomorrow Co', 'vat_number' => '320000000000003']);

        $invoice->refresh();
        $this->assertSame('Issued Co', data_get($invoice->company_snapshot, 'company_name'));
        $this->assertSame('300111111111113', data_get($invoice->recipient_snapshot, 'vat_number'));
        $this->assertSame('Snapshot Customer', data_get($invoice->recipient_snapshot, 'name'));

        $this->actingAs($user)
            ->withSession(['current_workspace_id' => $workspace->id])
            ->get(route('workspace.finance.invoices.show', $invoice))
            ->assertOk()
            ->assertSee('300111111111113')
            ->assertDontSee('399999999999993')
            ->assertSee('غير مهيأة');
    }

    public function test_issued_invoice_rejects_forbidden_attachment_mutations(): void
    {
        Storage::fake('public');
        [$user, $workspace] = $this->createWorkspaceOwner('company');
        $customer = $this->makeCustomer($workspace, 'Attachment Customer');

        $this->actingAs($user)
            ->withSession(['current_workspace_id' => $workspace->id])
            ->post(route('workspace.finance.invoices.store'), $this->invoicePayload($customer->id, [
                'invoice_status' => 'draft',
            ]) + [
                'attachments' => [UploadedFile::fake()->create('source.pdf', 20, 'application/pdf')],
            ])
            ->assertRedirect();

        $invoice = FinanceInvoice::withoutGlobalScopes()->firstOrFail();
        $this->actingAs($user)
            ->withSession(['current_workspace_id' => $workspace->id])
            ->post(route('workspace.finance.invoices.issue', $invoice))
            ->assertRedirect();

        $invoice->refresh();
        $attachment = $invoice->attachments()->firstOrFail();
        $this->assertSame(1, $invoice->attachments()->count());

        $this->actingAs($user)
            ->withSession(['current_workspace_id' => $workspace->id])
            ->post(route('workspace.finance.invoices.attachments.store', $invoice), [
                'attachments' => [UploadedFile::fake()->create('late.pdf', 20, 'application/pdf')],
            ])
            ->assertRedirect()
            ->assertSessionHas('error');

        $this->actingAs($user)
            ->withSession(['current_workspace_id' => $workspace->id])
            ->delete(route('workspace.finance.invoices.attachments.destroy', [$invoice, $attachment]))
            ->assertRedirect()
            ->assertSessionHas('error');

        $this->assertTrue($attachment->fresh() !== null);
        $this->assertSame(1, $invoice->attachments()->count());
    }

    public function test_cancelled_invoice_remains_immutable_and_cannot_be_deleted(): void
    {
        [$user, $workspace] = $this->createWorkspaceOwner('company');
        $customer = $this->makeCustomer($workspace, 'Cancel Customer');
        $invoice = $this->storeIssuedInvoice($user, $workspace, $customer);

        $this->actingAs($user)
            ->withSession(['current_workspace_id' => $workspace->id])
            ->post(route('workspace.finance.invoices.cancel', $invoice))
            ->assertRedirect();

        $invoice->refresh();
        $this->assertSame('cancelled', $invoice->invoice_status);
        $this->assertNotNull($invoice->cancelled_at);

        $this->assertThrowsOnLock($invoice, ['total' => 1]);
        $this->assertThrowsOnLock($invoice, ['invoice_number' => 'CANCEL-REUSE']);

        $this->expectException(RuntimeException::class);
        $invoice->delete();
    }

    public function test_payments_can_be_added_and_reversed_without_changing_financials(): void
    {
        [$user, $workspace] = $this->createWorkspaceOwner('company');
        $customer = $this->makeCustomer($workspace, 'Pay Customer');
        $invoice = $this->storeIssuedInvoice($user, $workspace, $customer);
        $financial = $this->financialFingerprint($invoice);

        $this->actingAs($user)
            ->withSession(['current_workspace_id' => $workspace->id])
            ->post(route('workspace.finance.invoices.payments.store', $invoice), [
                'payment_date' => now()->toDateString(),
                'amount' => 50,
                'method' => 'cash',
                'reference' => 'PAY-1',
            ])
            ->assertRedirect();

        $invoice->refresh();
        $this->assertSame('issued', $invoice->invoice_status);
        $this->assertSame('partial', $invoice->payment_status);
        $this->assertSame($financial, $this->financialFingerprint($invoice));
        $this->assertSame('50.00', (string) $invoice->amount_paid);

        $payment = $invoice->payments()->firstOrFail();
        $this->actingAs($user)
            ->withSession(['current_workspace_id' => $workspace->id])
            ->post(route('workspace.finance.invoices.payments.reverse', [$invoice, $payment]), [
                'reversal_reason' => 'test reverse',
            ])
            ->assertRedirect();

        $invoice->refresh();
        $this->assertSame('issued', $invoice->invoice_status);
        $this->assertSame('unpaid', $invoice->payment_status);
        $this->assertSame($financial, $this->financialFingerprint($invoice));
        $this->assertSame('0.00', (string) $invoice->amount_paid);
    }

    public function test_credit_and_debit_notes_do_not_mutate_source_invoice_lines(): void
    {
        [$user, $workspace] = $this->createWorkspaceOwner('company');
        $customer = $this->makeCustomer($workspace, 'Note Customer');
        $invoice = $this->storeIssuedInvoice($user, $workspace, $customer);
        $lineFingerprint = $invoice->items->map(fn (FinanceInvoiceItem $item): array => $item->only([
            'quantity', 'unit_price', 'discount', 'tax_profile_type', 'tax_rate', 'tax_amount', 'total',
        ]))->all();

        $this->actingAs($user)
            ->withSession(['current_workspace_id' => $workspace->id])
            ->post(route('workspace.finance.invoices.credit-notes.store', $invoice), [
                'type' => 'credit',
                'reason' => 'خصم تجاري',
                'issue_date' => now()->toDateString(),
                'status' => 'issued',
                'items_json' => json_encode([[
                    'product_name' => 'خصم',
                    'quantity' => 1,
                    'unit_price' => 10,
                    'discount' => 0,
                    'tax_rate' => 15,
                    'tax_type' => 'standard',
                ]]),
            ])
            ->assertRedirect();

        $invoice->refresh()->load('items', 'creditNotes.items');
        $this->assertSame($lineFingerprint, $invoice->items->map(fn (FinanceInvoiceItem $item): array => $item->only([
            'quantity', 'unit_price', 'discount', 'tax_profile_type', 'tax_rate', 'tax_amount', 'total',
        ]))->all());
        $this->assertSame('11.50', (string) $invoice->amount_credited);
        $this->assertSame('standard', $invoice->creditNotes->first()?->items->first()?->tax_profile_type);

        app(CreditNoteService::class)->create($workspace, $invoice, [
            'type' => 'debit',
            'reason' => 'رسوم إضافية',
            'issue_date' => now()->toDateString(),
            'status' => 'issued',
            'items' => [[
                'product_name' => 'رسوم',
                'quantity' => 1,
                'unit_price' => 5,
                'discount' => 0,
                'tax_rate' => 15,
                'tax_type' => 'standard',
            ]],
        ], (int) $user->id);

        $invoice->refresh()->load('items');
        $this->assertSame($lineFingerprint, $invoice->items->map(fn (FinanceInvoiceItem $item): array => $item->only([
            'quantity', 'unit_price', 'discount', 'tax_profile_type', 'tax_rate', 'tax_amount', 'total',
        ]))->all());
        $this->assertSame('5.75', (string) $invoice->amount_debited);
    }

    public function test_workspace_cannot_read_foreign_invoice_or_settings(): void
    {
        [$userA, $workspaceA] = $this->createWorkspaceOwner('company');
        [$userB, $workspaceB] = $this->createWorkspaceOwner('store');
        $this->actingAs($userA)->withSession(['current_workspace_id' => $workspaceA->id])
            ->get(route('workspace.finance.settings.index'));
        $this->actingAs($userB)->withSession(['current_workspace_id' => $workspaceB->id])
            ->get(route('workspace.finance.settings.index'));

        FinanceSetting::withoutGlobalScopes()->where('workspace_id', $workspaceA->id)
            ->update(['company_name' => 'Alpha LLC', 'vat_number' => '310000000000003']);
        FinanceSetting::withoutGlobalScopes()->where('workspace_id', $workspaceB->id)
            ->update(['company_name' => 'Beta LLC', 'vat_number' => '310000000000011']);

        $customerB = $this->makeCustomer($workspaceB, 'Beta Customer');
        $invoiceB = $this->storeIssuedInvoice($userB, $workspaceB, $customerB);

        $this->assertSame('Beta LLC', data_get($invoiceB->company_snapshot, 'company_name'));
        $this->assertSame('Alpha LLC', FinanceSetting::forWorkspaceId((int) $workspaceA->id)?->company_name);
        $this->assertSame('Beta LLC', FinanceSetting::forWorkspaceId((int) $workspaceB->id)?->company_name);

        $this->actingAs($userA)
            ->withSession(['current_workspace_id' => $workspaceA->id])
            ->get(route('workspace.finance.invoices.show', $invoiceB))
            ->assertNotFound();

        $this->actingAs($userA)
            ->withSession(['current_workspace_id' => $workspaceA->id])
            ->get(route('workspace.finance.settings.index'))
            ->assertOk()
            ->assertSee('Alpha LLC')
            ->assertDontSee('Beta LLC');
    }

    public function test_automatic_numbering_skips_occupied_numbers(): void
    {
        [$user, $workspace] = $this->createWorkspaceOwner('company');
        $customer = $this->makeCustomer($workspace, 'Number Customer');
        $this->actingAs($user)->withSession(['current_workspace_id' => $workspace->id])
            ->get(route('workspace.finance.invoices.create'));

        FinanceInvoice::withoutGlobalScopes()->create([
            'workspace_id' => $workspace->id,
            'invoice_number' => 'INV-000001',
            'type' => 'sales',
            'status' => 'unpaid',
            'invoice_status' => 'issued',
            'payment_status' => 'unpaid',
            'issue_date' => now()->toDateString(),
            'currency' => 'SAR',
            'subtotal' => 10,
            'discount' => 0,
            'taxable_amount' => 10,
            'tax_amount' => 0,
            'total' => 10,
            'amount_paid' => 0,
            'amount_due' => 10,
            'tax_profile_type' => 'standard',
            'tax_rate' => 0,
        ]);

        $first = $this->storeIssuedInvoice($user, $workspace, $customer);
        $second = $this->storeIssuedInvoice($user, $workspace, $customer);

        $this->assertSame('INV-000002', $first->invoice_number);
        $this->assertSame('INV-000003', $second->invoice_number);
        $this->assertNotSame($first->invoice_number, $second->invoice_number);
    }

    public function test_manual_numbering_obeys_workspace_policy_and_cannot_reuse_cancelled_numbers(): void
    {
        [$user, $workspace] = $this->createWorkspaceOwner('company');
        $customer = $this->makeCustomer($workspace, 'Manual Customer');

        $this->actingAs($user)
            ->withSession(['current_workspace_id' => $workspace->id])
            ->post(route('workspace.finance.invoices.store'), $this->invoicePayload($customer->id, [
                'invoice_number' => 'MANUAL-1',
            ]))
            ->assertRedirect()
            ->assertSessionHas('error');

        $this->assertSame(0, FinanceInvoice::withoutGlobalScopes()->count());

        FinanceSetting::withoutGlobalScopes()
            ->where('workspace_id', $workspace->id)
            ->update(['allow_manual_invoice_numbers' => true]);

        $this->actingAs($user)
            ->withSession(['current_workspace_id' => $workspace->id])
            ->post(route('workspace.finance.invoices.store'), $this->invoicePayload($customer->id, [
                'invoice_number' => 'MANUAL-1',
            ]))
            ->assertRedirect();

        $invoice = FinanceInvoice::withoutGlobalScopes()->firstOrFail();
        $this->assertSame('MANUAL-1', $invoice->invoice_number);

        $this->actingAs($user)
            ->withSession(['current_workspace_id' => $workspace->id])
            ->post(route('workspace.finance.invoices.store'), $this->invoicePayload($customer->id, [
                'invoice_number' => 'MANUAL-1',
            ]))
            ->assertRedirect()
            ->assertSessionHas('error');

        $this->actingAs($user)
            ->withSession(['current_workspace_id' => $workspace->id])
            ->post(route('workspace.finance.invoices.cancel', $invoice))
            ->assertRedirect();

        $this->actingAs($user)
            ->withSession(['current_workspace_id' => $workspace->id])
            ->post(route('workspace.finance.invoices.store'), $this->invoicePayload($customer->id, [
                'invoice_number' => 'MANUAL-1',
            ]))
            ->assertRedirect()
            ->assertSessionHas('error');

        $this->assertSame(1, FinanceInvoice::withoutGlobalScopes()->count());
    }

    public function test_mixed_line_tax_classifications_are_persisted(): void
    {
        [$user, $workspace] = $this->createWorkspaceOwner('company');
        $customer = $this->makeCustomer($workspace, 'Mixed Tax Customer');

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
                'tax_document_subtype' => 'standard',
                'items_json' => json_encode([
                    [
                        'product_name' => 'Taxable',
                        'quantity' => 1,
                        'unit_price' => 100,
                        'discount' => 0,
                        'tax_rate' => 15,
                        'tax_type' => 'standard',
                    ],
                    [
                        'product_name' => 'Exempt',
                        'quantity' => 1,
                        'unit_price' => 50,
                        'discount' => 0,
                        'tax_rate' => 0,
                        'tax_type' => 'exempt',
                    ],
                ]),
            ])
            ->assertRedirect();

        $invoice = FinanceInvoice::withoutGlobalScopes()->with('items')->firstOrFail();
        $this->assertSame('standard', $invoice->tax_profile_type);
        $types = $invoice->items->pluck('tax_profile_type')->sort()->values()->all();
        $this->assertSame(['exempt', 'standard'], $types);
        $this->assertSame('150.00', (string) $invoice->taxable_amount);
        $this->assertSame('15.00', (string) $invoice->tax_amount);
        $this->assertSame('165.00', (string) $invoice->total);
    }

    public function test_direct_issue_uses_the_same_finalization_path_as_draft_issue(): void
    {
        [$user, $workspace] = $this->createWorkspaceOwner('company');
        $customer = $this->makeCustomer($workspace, 'Path Customer');

        $direct = $this->storeIssuedInvoice($user, $workspace, $customer);
        $this->assertSame('issued', $direct->invoice_status);
        $this->assertNotNull($direct->issued_at);
        $this->assertNotEmpty($direct->company_snapshot);
        $this->assertNotEmpty($direct->recipient_snapshot);
        $this->assertSame('standard', $direct->tax_document_subtype);
        $this->assertSame('not_required', $direct->zatca_requirement);

        $directJournal = FinanceJournalEntry::withoutGlobalScopes()
            ->where('reference_type', FinanceInvoice::class)
            ->where('reference_id', $direct->id)
            ->where('type', 'sales_invoice')
            ->count();
        $this->assertSame(1, $directJournal);

        $this->actingAs($user)
            ->withSession(['current_workspace_id' => $workspace->id])
            ->post(route('workspace.finance.invoices.store'), $this->invoicePayload($customer->id, [
                'invoice_status' => 'draft',
            ]))
            ->assertRedirect();

        $draft = FinanceInvoice::withoutGlobalScopes()->orderByDesc('id')->firstOrFail();
        $this->actingAs($user)
            ->withSession(['current_workspace_id' => $workspace->id])
            ->post(route('workspace.finance.invoices.issue', $draft))
            ->assertRedirect();

        $draft->refresh();
        $this->assertSame('issued', $draft->invoice_status);
        $this->assertNotNull($draft->issued_at);
        $this->assertNotEmpty($draft->company_snapshot);
        $this->assertSame(1, FinanceJournalEntry::withoutGlobalScopes()
            ->where('reference_type', FinanceInvoice::class)
            ->where('reference_id', $draft->id)
            ->where('type', 'sales_invoice')
            ->count());

        $actions = AuditLog::withoutGlobalScopes()
            ->where('entity_type', FinanceInvoice::class)
            ->where('entity_id', $direct->id)
            ->pluck('action')
            ->all();
        $this->assertContains('invoice_created', $actions);
        $this->assertContains('invoice_issued', $actions);
    }

    public function test_purchase_invoices_cannot_be_marked_zatca_required(): void
    {
        [$user, $workspace] = $this->createWorkspaceOwner('company');
        $supplier = FinanceSupplier::withoutGlobalScopes()->create([
            'workspace_id' => $workspace->id,
            'name' => 'Supplier Locked',
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
                'zatca_requirement' => 'required',
                'tax_document_subtype' => 'simplified',
                'items_json' => json_encode([[
                    'product_name' => 'Purchase line',
                    'quantity' => 1,
                    'unit_price' => 100,
                    'discount' => 0,
                    'tax_rate' => 15,
                    'tax_type' => 'standard',
                ]]),
            ])
            ->assertRedirect();

        $invoice = FinanceInvoice::withoutGlobalScopes()->firstOrFail();
        $this->assertSame('purchase', $invoice->type);
        $this->assertSame('simplified', $invoice->tax_document_subtype);
        $this->assertSame('not_required', $invoice->zatca_requirement);
    }

    public function test_historical_invoices_remain_readable_with_safe_defaults(): void
    {
        [$user, $workspace] = $this->createWorkspaceOwner('company');
        $invoice = FinanceInvoice::withoutGlobalScopes()->create([
            'workspace_id' => $workspace->id,
            'invoice_number' => 'HIST-1',
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
            'customer_name' => 'Legacy Buyer',
            'tax_profile_type' => 'standard',
            'tax_rate' => 15,
        ]);

        $this->assertSame('standard', $invoice->fresh()->tax_document_subtype ?: 'standard');
        $this->assertSame('not_required', $invoice->fresh()->zatca_requirement ?: 'not_required');

        $this->actingAs($user)
            ->withSession(['current_workspace_id' => $workspace->id])
            ->get(route('workspace.finance.invoices.show', $invoice))
            ->assertOk()
            ->assertSee('HIST-1')
            ->assertSee('Legacy Buyer')
            ->assertSee('غير مهيأة');
    }

    public function test_pdf_does_not_invent_zatca_data(): void
    {
        [$user, $workspace] = $this->createWorkspaceOwner('company');
        $customer = $this->makeCustomer($workspace, 'PDF Customer', ['vat_number' => '300222222222223']);
        $invoice = $this->storeIssuedInvoice($user, $workspace, $customer);

        $html = view('workspace.finance.invoices.pdf', [
            'invoice' => $invoice->load(['items', 'customer', 'supplier']),
            'setting' => null,
            'companySnapshot' => $invoice->company_snapshot,
            'recipientSnapshot' => $invoice->recipient_snapshot,
            'pdfSnapshot' => $invoice->pdf_snapshot,
            'snapshotsAuthoritative' => true,
            'logoDataUri' => null,
        ])->render();

        $this->assertStringContainsString('الفوترة الإلكترونية ZATCA: غير مهيأة', $html);
        $this->assertStringNotContainsString('QR: متوفر', $html);
        $this->assertStringNotContainsString('QR: غير متوفر', $html);
        $this->assertStringNotContainsString('مرجع ZATCA UUID', $html);
        $this->assertSame(null, $invoice->zatca_uuid);
        $this->assertSame(null, $invoice->zatca_qr_code);

        $this->actingAs($user)
            ->withSession(['current_workspace_id' => $workspace->id])
            ->get(route('workspace.finance.invoices.create'))
            ->assertOk()
            ->assertSee('قياسية')
            ->assertSee('مبسطة')
            ->assertDontSee('رقم الفاتورة (يدوي)');
    }

    public function test_fallback_standard_rate_is_centralized(): void
    {
        $this->assertSame(15.00, TaxCalculationService::FALLBACK_STANDARD_RATE);
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    private function assertThrowsOnLock(FinanceInvoice $invoice, array $attributes): void
    {
        $fresh = $invoice->fresh();
        try {
            $fresh->update($attributes);
            $this->fail('Issued/cancelled invoice accepted a forbidden update: '.implode(',', array_keys($attributes)));
        } catch (RuntimeException $exception) {
            $this->assertNotSame('', $exception->getMessage());
        }
    }

    private function financialFingerprint(FinanceInvoice $invoice): array
    {
        $invoice->refresh();

        return [
            'invoice_number' => $invoice->invoice_number,
            'issue_date' => optional($invoice->issue_date)?->toDateString(),
            'issued_at' => optional($invoice->issued_at)?->utc()->toIso8601String(),
            'currency' => $invoice->currency,
            'type' => $invoice->type,
            'tax_document_subtype' => $invoice->tax_document_subtype,
            'subtotal' => (string) $invoice->subtotal,
            'discount' => (string) $invoice->discount,
            'taxable_amount' => (string) $invoice->taxable_amount,
            'tax_amount' => (string) $invoice->tax_amount,
            'total' => (string) $invoice->total,
            'tax_profile_type' => $invoice->tax_profile_type,
            'tax_rate' => (string) $invoice->tax_rate,
            'company_snapshot' => $invoice->company_snapshot,
            'recipient_snapshot' => $invoice->recipient_snapshot,
            'pdf_snapshot' => $invoice->pdf_snapshot,
        ];
    }

    private function storeIssuedInvoice(User $user, Workspace $workspace, Customer $customer): FinanceInvoice
    {
        $before = FinanceInvoice::withoutGlobalScopes()->max('id') ?? 0;

        $this->actingAs($user)
            ->withSession(['current_workspace_id' => $workspace->id])
            ->post(route('workspace.finance.invoices.store'), $this->invoicePayload($customer->id))
            ->assertRedirect();

        return FinanceInvoice::withoutGlobalScopes()
            ->where('workspace_id', $workspace->id)
            ->where('id', '>', $before)
            ->orderByDesc('id')
            ->firstOrFail();
    }

    /**
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    private function invoicePayload(int $customerId, array $overrides = []): array
    {
        $payload = [
            'type' => 'sales',
            'customer_id' => $customerId,
            'issue_date' => $overrides['issue_date'] ?? now()->toDateString(),
            'due_date' => $overrides['due_date'] ?? now()->addDays(10)->toDateString(),
            'currency' => 'SAR',
            'invoice_status' => $overrides['invoice_status'] ?? 'issued',
            'tax_profile_type' => $overrides['tax_profile_type'] ?? 'standard',
            'tax_rate' => $overrides['tax_rate'] ?? 15,
            'tax_document_subtype' => $overrides['tax_document_subtype'] ?? 'standard',
            'items_json' => json_encode([
                [
                    'product_name' => 'خدمة فوترة',
                    'description' => 'بند اختبار',
                    'quantity' => 1,
                    'unit_price' => $overrides['unit_price'] ?? 100,
                    'discount' => $overrides['discount'] ?? 0,
                    'tax_rate' => $overrides['tax_rate'] ?? 15,
                    'tax_type' => $overrides['tax_type'] ?? 'standard',
                ],
            ]),
        ];

        if (array_key_exists('invoice_number', $overrides)) {
            $payload['invoice_number'] = $overrides['invoice_number'];
        }

        return $payload;
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
