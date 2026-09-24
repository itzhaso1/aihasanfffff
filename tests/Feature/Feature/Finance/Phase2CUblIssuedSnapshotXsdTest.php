<?php

namespace Tests\Feature\Feature\Finance;

use App\EInvoicing\Xml\Validation\XsdValidator;
use App\Enums\EInvoicing\ValidationChannelStatus;
use App\Enums\Finance\TaxProfileType;
use App\Models\Customer;
use App\Models\Finance\FinanceInvoice;
use App\Models\Finance\FinanceSetting;
use App\Models\Finance\IssuedDocumentSnapshot;
use App\Models\Plan;
use App\Models\Subscription;
use App\Models\User;
use App\Models\Workspace;
use App\Models\WorkspaceFeatureFlag;
use App\Services\EInvoicing\EInvoiceFactory;
use App\Services\EInvoicing\EInvoiceXmlGenerator;
use App\Services\Finance\CreditNoteService;
use App\Services\Finance\FinanceBootstrapService;
use App\Services\Finance\InvoiceService;
use App\Support\Tenancy\WorkspaceContext;
use Database\Seeders\FoundationSeeder;
use DOMDocument;
use DOMXPath;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Tests\TestCase;

class Phase2CUblIssuedSnapshotXsdTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Notification::fake();
        $this->seed(FoundationSeeder::class);
    }

    public function test_standard_issued_finance_invoice_xml_passes_xsd_from_real_snapshot(): void
    {
        $invoice = $this->issueStandardInvoice();
        $this->assertXsdFromIssuedSnapshot($invoice, '//cbc:InvoiceTypeCode', '388');
    }

    public function test_simplified_issued_finance_invoice_xml_from_real_snapshot(): void
    {
        [$owner, $workspace] = $this->bootWorkspace();
        $this->completeSeller($workspace);
        $invoice = app(InvoiceService::class)->create($workspace, [
            'type' => 'sales',
            'customer_name' => 'Walk-in Retail',
            'issue_date' => now()->toDateString(),
            'currency' => 'SAR',
            'invoice_status' => 'issued',
            'tax_document_subtype' => 'simplified',
            'tax_profile_type' => TaxProfileType::Standard->value,
            'tax_rate' => 15,
            'items' => [[
                'product_name' => 'Retail item',
                'quantity' => 1,
                'unit_price' => 50,
                'discount' => 0,
                'tax_rate' => 15,
                'tax_type' => TaxProfileType::Standard->value,
            ]],
        ], (int) $owner->id);

        $xml = $this->xmlFromInvoice($invoice);
        $xp = $this->xpath($xml);
        $this->assertSame('0200000', $this->attr($xp, '//cbc:InvoiceTypeCode', 'name'));
        $this->assertXsdValid($xml);
    }

    public function test_credit_note_issued_snapshot_xml_passes_xsd(): void
    {
        $invoice = $this->issueStandardInvoice();
        $note = app(CreditNoteService::class)->create(
            $invoice->workspace,
            $invoice,
            [
                'type' => 'credit',
                'reason' => 'Commercial discount',
                'issue_date' => now()->toDateString(),
                'status' => 'issued',
                'items' => [[
                    'product_name' => 'Adjustment',
                    'quantity' => 1,
                    'unit_price' => 20,
                    'discount' => 0,
                    'tax_rate' => 15,
                ]],
            ],
            (int) $invoice->created_by,
        );

        $snapshot = IssuedDocumentSnapshot::withoutGlobalScopes()
            ->where('source_type', IssuedDocumentSnapshot::SOURCE_FINANCE_CREDIT_NOTE)
            ->where('source_id', $note->id)
            ->firstOrFail();
        $generated = app(EInvoiceXmlGenerator::class)->generate(app(EInvoiceFactory::class)->make($snapshot));
        $this->assertSame('CreditNote', $generated->rootLocalName);
        $this->assertXsdValid($generated->xml);
    }

    public function test_debit_note_issued_snapshot_uses_invoice_root_and_type_383(): void
    {
        $invoice = $this->issueStandardInvoice();
        $note = app(CreditNoteService::class)->create(
            $invoice->workspace,
            $invoice,
            [
                'type' => 'debit',
                'reason' => 'Extra fee',
                'issue_date' => now()->toDateString(),
                'status' => 'issued',
                'items' => [[
                    'product_name' => 'Fee',
                    'quantity' => 1,
                    'unit_price' => 10,
                    'discount' => 0,
                    'tax_rate' => 15,
                ]],
            ],
            (int) $invoice->created_by,
        );

        $snapshot = IssuedDocumentSnapshot::withoutGlobalScopes()
            ->where('source_type', IssuedDocumentSnapshot::SOURCE_FINANCE_DEBIT_NOTE)
            ->where('source_id', $note->id)
            ->firstOrFail();
        $generated = app(EInvoiceXmlGenerator::class)->generate(app(EInvoiceFactory::class)->make($snapshot));
        $xp = $this->xpath($generated->xml);
        $this->assertSame('Invoice', $generated->rootLocalName);
        $this->assertSame('383', $this->value($xp, '//cbc:InvoiceTypeCode'));
        $this->assertXsdValid($generated->xml);
    }

    public function test_mixed_tax_breakdown_emits_one_tax_subtotal_per_category(): void
    {
        [$owner, $workspace] = $this->bootWorkspace();
        $customer = $this->makeBuyer($workspace);
        $this->completeSeller($workspace);
        $invoice = app(InvoiceService::class)->create($workspace, [
            'type' => 'sales',
            'customer_id' => $customer->id,
            'issue_date' => now()->toDateString(),
            'currency' => 'SAR',
            'invoice_status' => 'issued',
            'tax_profile_type' => TaxProfileType::Standard->value,
            'tax_rate' => 15,
            'items' => [
                ['product_name' => 'A', 'quantity' => 1, 'unit_price' => 100, 'discount' => 0, 'tax_rate' => 15, 'tax_type' => 'standard'],
                ['product_name' => 'B', 'quantity' => 1, 'unit_price' => 40, 'discount' => 0, 'tax_rate' => 0, 'tax_type' => 'zero_rated'],
                ['product_name' => 'C', 'quantity' => 1, 'unit_price' => 25, 'discount' => 0, 'tax_rate' => 0, 'tax_type' => 'exempt', 'exemption_code' => 'VATEX-SA-EDU', 'exemption_reason' => 'Education'],
                ['product_name' => 'D', 'quantity' => 2, 'unit_price' => 10, 'discount' => 0, 'tax_rate' => 0, 'tax_type' => 'out_of_scope', 'exemption_code' => 'VATEX-SA-OOS', 'exemption_reason' => 'Out of scope'],
            ],
        ], (int) $owner->id);

        $snapshot = IssuedDocumentSnapshot::withoutGlobalScopes()
            ->where('source_type', IssuedDocumentSnapshot::SOURCE_FINANCE_INVOICE)
            ->where('source_id', $invoice->id)
            ->firstOrFail();
        $expectedTaxable = (string) data_get($snapshot->payload, 'totals.taxable_amount');
        $expectedTax = (string) data_get($snapshot->payload, 'tax.amount');

        $xml = $this->xmlFromInvoice($invoice);
        $xp = $this->xpath($xml);
        $this->assertSame(4, $xp->query('//cac:TaxTotal/cac:TaxSubtotal')->length);
        $this->assertSame($expectedTax, $this->value($xp, '//cac:TaxTotal/cbc:TaxAmount'));
        $sumTaxable = '0.00';
        $sumTax = '0.00';
        foreach ($xp->query('//cac:TaxTotal/cac:TaxSubtotal') as $subtotal) {
            $taxableNode = $xp->query('cbc:TaxableAmount', $subtotal)?->item(0);
            $taxNode = $xp->query('cbc:TaxAmount', $subtotal)?->item(0);
            $sumTaxable = \App\Support\Money\Money::add($sumTaxable, trim((string) $taxableNode?->textContent));
            $sumTax = \App\Support\Money\Money::add($sumTax, trim((string) $taxNode?->textContent));
        }
        $this->assertSame($expectedTaxable, $sumTaxable);
        $this->assertSame($expectedTax, $sumTax);
        $this->assertXsdValid($xml);
    }

    public function test_issued_xml_unchanged_when_live_customer_is_mutated(): void
    {
        $invoice = $this->issueStandardInvoice();
        $before = $this->xmlFromInvoice($invoice);
        $invoice->customer?->update(['street' => null, 'city' => null, 'country_code' => null]);
        $after = $this->xmlFromInvoice($invoice->fresh());
        $this->assertSame($before, $after);
    }

    private function issueStandardInvoice(): FinanceInvoice
    {
        [$owner, $workspace] = $this->bootWorkspace();
        $customer = $this->makeBuyer($workspace);
        $this->completeSeller($workspace);

        return app(InvoiceService::class)->create($workspace, [
            'type' => 'sales',
            'customer_id' => $customer->id,
            'issue_date' => now()->toDateString(),
            'currency' => 'SAR',
            'invoice_status' => 'issued',
            'tax_profile_type' => TaxProfileType::Standard->value,
            'tax_rate' => 15,
            'items' => [[
                'product_name' => 'Service',
                'quantity' => 1,
                'unit_price' => 100,
                'discount' => 0,
                'tax_rate' => 15,
                'tax_type' => TaxProfileType::Standard->value,
            ]],
        ], (int) $owner->id);
    }

    private function assertXsdFromIssuedSnapshot(FinanceInvoice $invoice, string $typeXPath, string $expectedType): void
    {
        $xml = $this->xmlFromInvoice($invoice);
        $xp = $this->xpath($xml);
        $this->assertSame($expectedType, $this->value($xp, $typeXPath));
        $this->assertXsdValid($xml);
    }

    private function xmlFromInvoice(FinanceInvoice $invoice): string
    {
        $snapshot = IssuedDocumentSnapshot::withoutGlobalScopes()
            ->where('source_type', IssuedDocumentSnapshot::SOURCE_FINANCE_INVOICE)
            ->where('source_id', $invoice->id)
            ->firstOrFail();

        return app(EInvoiceXmlGenerator::class)->generate(app(EInvoiceFactory::class)->make($snapshot))->xml;
    }

    private function assertXsdValid(string $xml): void
    {
        $result = app(XsdValidator::class)->validateStructured($xml);
        if ($result->status === ValidationChannelStatus::NotAvailable) {
            $this->markTestSkipped('UBL XSD bundle not available in this environment.');
        }
        $this->assertSame(ValidationChannelStatus::Valid, $result->status, implode('; ', array_map(
            static fn ($issue) => $issue->message,
            $result->issues,
        )));
    }

    private function xpath(string $xml): DOMXPath
    {
        $document = new DOMDocument;
        $document->loadXML($xml);
        $xp = new DOMXPath($document);
        $xp->registerNamespace('cac', 'urn:oasis:names:specification:ubl:schema:xsd:CommonAggregateComponents-2');
        $xp->registerNamespace('cbc', 'urn:oasis:names:specification:ubl:schema:xsd:CommonBasicComponents-2');

        return $xp;
    }

    private function value(DOMXPath $xp, string $query): string
    {
        return trim((string) $xp->evaluate('string('.$query.')'));
    }

    private function attr(DOMXPath $xp, string $query, string $name): string
    {
        $node = $xp->query($query)?->item(0);
        if (! $node instanceof \DOMElement) {
            return '';
        }

        return trim((string) $node->getAttribute($name));
    }

    /**
     * @return array{0: User, 1: Workspace}
     */
    private function bootWorkspace(): array
    {
        $user = User::factory()->create();
        $workspace = Workspace::factory()->create(['owner_user_id' => $user->id, 'type' => 'company']);
        $workspace->users()->attach($user->id, ['membership_role' => 'owner', 'status' => 'active', 'joined_at' => now()]);
        app(WorkspaceContext::class)->set($workspace);
        foreach (['finance', 'customers', 'products'] as $feature) {
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

        return [$user, $workspace->fresh()];
    }

    private function completeSeller(Workspace $workspace): void
    {
        FinanceSetting::withoutGlobalScopes()->where('workspace_id', $workspace->id)->update([
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

    private function makeBuyer(Workspace $workspace): Customer
    {
        return Customer::withoutGlobalScopes()->create([
            'workspace_id' => $workspace->id,
            'name' => 'Buyer',
            'phone' => '0512345678',
            'street' => 'Buyer Street',
            'city' => 'Jeddah',
            'country_code' => 'SA',
        ]);
    }
}
