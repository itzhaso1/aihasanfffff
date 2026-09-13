<?php

namespace Tests\Feature\Feature\Finance;

use App\EInvoicing\Xml\DocumentUuid;
use App\EInvoicing\Xml\EInvoiceXmlMappingException;
use App\Enums\EInvoicing\ComplianceStatus;
use App\Enums\EInvoicing\ElectronicDocumentKind;
use App\Enums\EInvoicing\InvoiceTransactionCode;
use App\Enums\EInvoicing\InvoiceTypeCode;
use App\Enums\Finance\TaxProfileType;
use App\Models\Customer;
use App\Models\EInvoicing\EInvoiceDocumentRecord;
use App\Models\EInvoicing\EInvoiceSecurityRecord;
use App\Models\Finance\FinanceCreditNote;
use App\Models\Finance\FinanceInvoice;
use App\Models\Finance\FinanceSetting;
use App\Models\Finance\IssuedDocumentSnapshot;
use App\Models\Plan;
use App\Models\PosCashierInvoice;
use App\Models\PosMenuItem;
use App\Models\Subscription;
use App\Models\User;
use App\Models\Workspace;
use App\Models\WorkspaceFeatureFlag;
use App\Services\EInvoicing\EInvoiceFactory;
use App\Services\EInvoicing\EInvoiceXmlGenerator;
use App\Services\EInvoicing\InvoiceIssueService;
use App\Services\EInvoicing\QR\EInvoiceQrService;
use App\Services\Finance\CreditNoteService;
use App\Services\Finance\FinanceBootstrapService;
use App\Services\Finance\InvoiceService;
use App\Services\Pos\PosOrderService;
use App\Support\Money\Money;
use App\Support\Tenancy\WorkspaceContext;
use Database\Seeders\FoundationSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class Phase10InvoiceLifecycleIntegrationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Notification::fake();
        $this->seed(FoundationSeeder::class);
    }

    public function test_issuing_finance_invoice_connects_snapshot_einvoice_xml_security_and_qr(): void
    {
        [$owner, $workspace] = $this->createWorkspaceOwner();
        $customer = $this->makeBuyer($workspace, 'Phase10 Buyer');
        $this->completeSeller($workspace);

        $invoice = $this->issueFinance($workspace, $customer, (int) $owner->id);

        $snapshot = $this->financeSnapshot($invoice);
        $record = EInvoiceDocumentRecord::withoutGlobalScopes()
            ->where('issued_document_snapshot_id', $snapshot->id)
            ->firstOrFail();
        $document = app(EInvoiceFactory::class)->make($snapshot);
        $security = EInvoiceSecurityRecord::withoutGlobalScopes()
            ->where('e_invoice_document_id', $record->id)
            ->firstOrFail();

        $this->assertSame('issued', $invoice->invoice_status ?? $invoice->status);
        $this->assertSame(ComplianceStatus::Generated, $record->compliance_status);
        $this->assertNotSame($invoice->invoice_status ?? $invoice->status, $record->compliance_status->value);
        $this->assertSame(ElectronicDocumentKind::TaxInvoice, $record->document_kind);
        $this->assertSame(InvoiceTypeCode::TaxInvoice, $record->type_code);
        $this->assertSame(InvoiceTransactionCode::Standard, $record->transaction_code);
        $this->assertSame('15.00', data_get($snapshot->payload, 'tax.amount'));
        $this->assertSame('15.00', $document->tax->amount);
        $this->assertSame('115.00', $document->totals->total);

        $xml = app(EInvoiceXmlGenerator::class)->generate($document);
        $again = app(EInvoiceXmlGenerator::class)->generate($document);
        $this->assertSame($xml->xml, $again->xml);
        $this->assertSame($invoice->invoice_number, $xml->documentNumber);
        $this->assertSame(DocumentUuid::fromDocument($document), $xml->documentUuid);

        $qr = app(EInvoiceQrService::class)->generateFromSecurityRecord($document, $security);
        $qrAgain = app(EInvoiceQrService::class)->generateFromSecurityRecord($document, $security);
        $this->assertSame($qr->base64, $qrAgain->base64);
        $this->assertSame($security->invoice_hash, $qr->valueForTag(6));
        $this->assertFalse($qr->includesCryptographicTags());
    }

    public function test_repeated_issue_is_idempotent_for_snapshot_einvoice_and_icv(): void
    {
        [$owner, $workspace] = $this->createWorkspaceOwner();
        $customer = $this->makeBuyer($workspace, 'Idem Buyer');
        $this->completeSeller($workspace);
        $invoice = $this->issueFinance($workspace, $customer, (int) $owner->id);

        app(InvoiceService::class)->issue($invoice->fresh(), (int) $owner->id);
        app(InvoiceIssueService::class)->prepareFromSnapshot($this->financeSnapshot($invoice));

        $this->assertSame(1, IssuedDocumentSnapshot::withoutGlobalScopes()
            ->where('source_type', IssuedDocumentSnapshot::SOURCE_FINANCE_INVOICE)
            ->where('source_id', $invoice->id)
            ->count());
        $this->assertSame(1, EInvoiceDocumentRecord::withoutGlobalScopes()
            ->where('source_type', IssuedDocumentSnapshot::SOURCE_FINANCE_INVOICE)
            ->where('source_id', $invoice->id)
            ->count());
        $this->assertSame(1, EInvoiceSecurityRecord::withoutGlobalScopes()->count());
        $this->assertSame(1, (int) EInvoiceSecurityRecord::withoutGlobalScopes()->value('icv'));
    }

    public function test_pos_close_preserves_pos_tax_and_creates_einvoice_without_xml(): void
    {
        [$owner, $workspace] = $this->createWorkspaceOwner();
        $this->completeSeller($workspace);
        $customer = $this->makeBuyer($workspace, 'POS Buyer');
        $item = PosMenuItem::withoutGlobalScopes()->create([
            'workspace_id' => $workspace->id,
            'name' => 'Service A',
            'item_type' => 'خدمات',
            'price' => 100,
            'currency' => 'SAR',
            'is_active' => true,
        ]);

        $order = app(PosOrderService::class)->createPosOrder($workspace, [
            'order_type' => 'takeaway',
            'customer_id' => $customer->id,
            'items' => [['pos_menu_item_id' => $item->id, 'quantity' => 1]],
        ], $owner);
        $invoice = app(PosOrderService::class)->createInvoiceFromOrder($order, (int) $owner->id);
        $snapshot = $this->posSnapshot($invoice);
        $record = EInvoiceDocumentRecord::withoutGlobalScopes()
            ->where('issued_document_snapshot_id', $snapshot->id)
            ->firstOrFail();
        $document = app(EInvoiceFactory::class)->make($snapshot);

        $this->assertSame('15.00', Money::of($order->fresh()->tax_amount));
        $this->assertSame('15.00', Money::of($invoice->fresh()->tax_amount));
        $this->assertSame('15.00', data_get($snapshot->payload, 'tax.amount'));
        $this->assertSame('15.00', $document->tax->amount);
        $this->assertSame('pos', data_get($snapshot->payload, 'tax.engine'));
        $this->assertSame(ElectronicDocumentKind::PosCashierInvoice, $record->document_kind);
        $this->assertNull($record->type_code);
        $this->assertNull($record->transaction_code);
        $this->assertSame(ComplianceStatus::Ready, $record->compliance_status);
        $this->assertSame(0, EInvoiceSecurityRecord::withoutGlobalScopes()->count());
        $this->assertSame(0, FinanceInvoice::withoutGlobalScopes()->count());

        $this->expectException(EInvoiceXmlMappingException::class);
        app(EInvoiceXmlGenerator::class)->generate($document);
    }

    public function test_credit_and_debit_notes_join_einvoice_orchestration(): void
    {
        [$owner, $workspace] = $this->createWorkspaceOwner();
        $customer = $this->makeBuyer($workspace, 'Note Buyer');
        $this->completeSeller($workspace);
        $invoice = $this->issueFinance($workspace, $customer, (int) $owner->id);

        $credit = app(CreditNoteService::class)->create($workspace, $invoice, [
            'type' => 'credit',
            'reason' => 'خصم تجاري',
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
        $debit = app(CreditNoteService::class)->create($workspace, $invoice->fresh(), [
            'type' => 'debit',
            'reason' => 'رسوم إضافية',
            'issue_date' => now()->toDateString(),
            'status' => 'issued',
            'items' => [[
                'product_name' => 'رسوم',
                'quantity' => 1,
                'unit_price' => 10,
                'discount' => 0,
                'tax_rate' => 15,
            ]],
        ], (int) $owner->id);

        $creditRecord = EInvoiceDocumentRecord::withoutGlobalScopes()
            ->where('source_type', IssuedDocumentSnapshot::SOURCE_FINANCE_CREDIT_NOTE)
            ->where('source_id', $credit->id)
            ->firstOrFail();
        $debitRecord = EInvoiceDocumentRecord::withoutGlobalScopes()
            ->where('source_type', IssuedDocumentSnapshot::SOURCE_FINANCE_DEBIT_NOTE)
            ->where('source_id', $debit->id)
            ->firstOrFail();
        $creditDocument = app(EInvoiceFactory::class)->make($this->noteSnapshot($credit));
        $debitDocument = app(EInvoiceFactory::class)->make($this->noteSnapshot($debit));

        $this->assertSame(ElectronicDocumentKind::CreditNote, $creditRecord->document_kind);
        $this->assertSame(InvoiceTypeCode::CreditNote, $creditRecord->type_code);
        $this->assertSame($invoice->invoice_number, $creditDocument->originalDocument?->invoiceNumber);
        $this->assertSame('خصم تجاري', $creditDocument->reason);
        $this->assertSame('3.00', $creditDocument->tax->amount);
        $this->assertSame(ComplianceStatus::Generated, $creditRecord->compliance_status);

        $this->assertSame(ElectronicDocumentKind::DebitNote, $debitRecord->document_kind);
        $this->assertSame(InvoiceTypeCode::DebitNote, $debitRecord->type_code);
        $this->assertSame($invoice->invoice_number, $debitDocument->originalDocument?->invoiceNumber);
        $this->assertSame('رسوم إضافية', $debitDocument->reason);
        $this->assertSame('1.50', $debitDocument->tax->amount);

        app(CreditNoteService::class)->issue($credit->fresh(), (int) $owner->id);
        $this->assertSame(1, EInvoiceDocumentRecord::withoutGlobalScopes()
            ->where('source_id', $credit->id)
            ->where('source_type', IssuedDocumentSnapshot::SOURCE_FINANCE_CREDIT_NOTE)
            ->count());
    }

    public function test_repeated_xml_and_qr_generation_do_not_duplicate_security_records(): void
    {
        [$owner, $workspace] = $this->createWorkspaceOwner();
        $customer = $this->makeBuyer($workspace, 'Repeat Buyer');
        $this->completeSeller($workspace);
        $invoice = $this->issueFinance($workspace, $customer, (int) $owner->id);
        $snapshot = $this->financeSnapshot($invoice);
        $document = app(EInvoiceFactory::class)->make($snapshot);
        $record = EInvoiceDocumentRecord::withoutGlobalScopes()
            ->where('issued_document_snapshot_id', $snapshot->id)
            ->firstOrFail();
        $security = EInvoiceSecurityRecord::withoutGlobalScopes()
            ->where('e_invoice_document_id', $record->id)
            ->firstOrFail();

        $xmlA = app(EInvoiceXmlGenerator::class)->generate($document);
        $xmlB = app(EInvoiceXmlGenerator::class)->generate($document);
        $qrA = app(EInvoiceQrService::class)->generateFromSecurityRecord($document, $security);
        $qrB = app(EInvoiceQrService::class)->generateFromSecurityRecord($document, $security);

        $this->assertSame($xmlA->xml, $xmlB->xml);
        $this->assertSame($qrA->tlvBytes, $qrB->tlvBytes);
        $this->assertSame(1, EInvoiceSecurityRecord::withoutGlobalScopes()->count());
        $this->assertFalse(Schema::hasTable('e_invoice_qr_records'));
    }

    /**
     * @return array{0: User, 1: Workspace}
     */
    private function createWorkspaceOwner(): array
    {
        $user = User::factory()->create();
        $workspace = Workspace::factory()->create([
            'owner_user_id' => $user->id,
            'type' => 'company',
            'name' => 'Phase10 Workspace',
        ]);
        $workspace->users()->attach($user->id, [
            'membership_role' => 'owner',
            'status' => 'active',
            'joined_at' => now(),
        ]);
        app(WorkspaceContext::class)->set($workspace);

        foreach (['finance', 'pos', 'products', 'orders', 'customers'] as $feature) {
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
        $settings = is_array($workspace->settings) ? $workspace->settings : [];
        $pos = is_array($settings['pos'] ?? null) ? $settings['pos'] : [];
        $pos['tax_rate'] = 15;
        $settings['pos'] = $pos;
        $workspace->update(['settings' => $settings]);

        return [$user, $workspace->fresh()];
    }

    private function completeSeller(Workspace $workspace): void
    {
        FinanceSetting::withoutGlobalScopes()
            ->where('workspace_id', $workspace->id)
            ->update([
                'company_name' => 'Issued Co',
                'vat_number' => '310000000000003',
                'commercial_registration' => '1010000000',
                'street' => 'King Fahd Road',
                'building_number' => '1234',
                'district' => 'Al Olaya',
                'city' => 'Riyadh',
                'postal_code' => '12345',
                'country_code' => 'SA',
            ]);
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    private function makeBuyer(Workspace $workspace, string $name, array $attributes = []): Customer
    {
        return Customer::withoutGlobalScopes()->create(array_merge([
            'workspace_id' => $workspace->id,
            'name' => $name,
            'phone' => '05'.random_int(10000000, 99999999),
            'email' => strtolower(str_replace(' ', '.', $name)).'@example.com',
            'vat_number' => '300111111111113',
            'address' => 'Buyer Street',
            'street' => 'Buyer Street',
            'city' => 'Jeddah',
            'country_code' => 'SA',
            'district' => 'Al Balad',
            'postal_code' => '22222',
        ], $attributes));
    }

    /**
     * @param  array<string, mixed>  $overrides
     */
    private function issueFinance(Workspace $workspace, Customer $customer, int $actorId, array $overrides = []): FinanceInvoice
    {
        $payload = array_merge([
            'type' => 'sales',
            'customer_id' => $customer->id,
            'issue_date' => now()->toDateString(),
            'currency' => 'SAR',
            'invoice_status' => 'issued',
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
        ], $overrides);

        return app(InvoiceService::class)->create($workspace, $payload, $actorId);
    }

    private function financeSnapshot(FinanceInvoice $invoice): IssuedDocumentSnapshot
    {
        return IssuedDocumentSnapshot::withoutGlobalScopes()
            ->where('source_type', IssuedDocumentSnapshot::SOURCE_FINANCE_INVOICE)
            ->where('source_id', $invoice->id)
            ->firstOrFail();
    }

    private function noteSnapshot(FinanceCreditNote $note): IssuedDocumentSnapshot
    {
        $sourceType = $note->isCredit()
            ? IssuedDocumentSnapshot::SOURCE_FINANCE_CREDIT_NOTE
            : IssuedDocumentSnapshot::SOURCE_FINANCE_DEBIT_NOTE;

        return IssuedDocumentSnapshot::withoutGlobalScopes()
            ->where('source_type', $sourceType)
            ->where('source_id', $note->id)
            ->firstOrFail();
    }

    private function posSnapshot(PosCashierInvoice $invoice): IssuedDocumentSnapshot
    {
        return IssuedDocumentSnapshot::withoutGlobalScopes()
            ->where('source_type', IssuedDocumentSnapshot::SOURCE_POS_CASHIER_INVOICE)
            ->where('source_id', $invoice->id)
            ->firstOrFail();
    }
}
