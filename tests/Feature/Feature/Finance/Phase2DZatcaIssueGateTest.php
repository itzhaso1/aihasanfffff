<?php

namespace Tests\Feature\Feature\Finance;

use App\EInvoicing\Xml\EInvoiceXmlMappingException;
use App\EInvoicing\Xml\Validation\XsdValidator;
use App\Enums\EInvoicing\ValidationChannelStatus;
use App\Enums\Finance\TaxProfileType;
use App\Exceptions\Finance\ZatcaRequiredIssueBlockedException;
use App\Models\Customer;
use App\Models\Finance\FinanceInvoice;
use App\Models\Finance\FinanceJournalEntry;
use App\Models\Finance\FinanceSetting;
use App\Models\Finance\IssuedDocumentSnapshot;
use App\Models\Plan;
use App\Models\Subscription;
use App\Models\User;
use App\Models\Workspace;
use App\Models\WorkspaceFeatureFlag;
use App\Services\EInvoicing\ComplianceReadinessService;
use App\Services\EInvoicing\EInvoiceXmlGenerator;
use App\Services\Finance\FinanceBootstrapService;
use App\Services\Finance\InvoiceService;
use App\Support\Tenancy\WorkspaceContext;
use Database\Seeders\FoundationSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Laravel\Sanctum\Sanctum;
use Mockery;
use Tests\TestCase;

class Phase2DZatcaIssueGateTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Notification::fake();
        $this->seed(FoundationSeeder::class);
    }

    protected function tearDown(): void
    {
        Mockery::close();
        parent::tearDown();
    }

    public function test_required_zatca_with_complete_data_issues_and_persists_validation(): void
    {
        [$owner, $workspace, $customer] = $this->boot();
        $this->completeSeller($workspace);
        $invoice = $this->createDraft($workspace, $customer, (int) $owner->id, ['zatca_requirement' => 'required']);
        $issued = app(InvoiceService::class)->issue($invoice, (int) $owner->id);

        $this->assertSame('issued', $issued->invoice_status);
        $assessment = app(ComplianceReadinessService::class)->assessFinanceInvoice($issued->fresh());
        $this->assertTrue($assessment->zatcaRequired);
        $this->assertTrue($assessment->readyForRequiredZatca());
        $this->assertNotNull($assessment->validation);
        if (app(XsdValidator::class)->schemaRoot() !== null) {
            $this->assertSame(ValidationChannelStatus::Valid, $assessment->validation->xsd->status);
        }
        $this->assertNotNull($assessment->documentRecord?->validated_at);
    }

    public function test_required_missing_seller_rejects_issue_and_rolls_back(): void
    {
        [$owner, $workspace, $customer] = $this->boot();
        FinanceSetting::withoutGlobalScopes()->where('workspace_id', $workspace->id)->update(['street' => null, 'city' => null]);
        $invoice = $this->createDraft($workspace, $customer, (int) $owner->id, ['zatca_requirement' => 'required']);

        try {
            app(InvoiceService::class)->issue($invoice, (int) $owner->id);
            $this->fail('Expected ZatcaRequiredIssueBlockedException');
        } catch (ZatcaRequiredIssueBlockedException $exception) {
            $this->assertFalse($exception->assessment->seller->ready);
        }

        $this->assertSame('draft', $invoice->fresh()->invoice_status);
        $this->assertSame(0, IssuedDocumentSnapshot::withoutGlobalScopes()->count());
    }

    public function test_required_missing_buyer_address_rejects_issue(): void
    {
        [$owner, $workspace, $customer] = $this->boot();
        $this->completeSeller($workspace);
        $customer->update(['street' => null, 'city' => null, 'country_code' => null]);
        $invoice = $this->createDraft($workspace, $customer, (int) $owner->id, ['zatca_requirement' => 'required']);

        $this->expectException(ZatcaRequiredIssueBlockedException::class);
        app(InvoiceService::class)->issue($invoice, (int) $owner->id);
    }

    public function test_required_xml_generation_failure_rejects_issue(): void
    {
        [$owner, $workspace, $customer] = $this->boot();
        $this->completeSeller($workspace);
        $invoice = $this->createDraft($workspace, $customer, (int) $owner->id, ['zatca_requirement' => 'required']);

        $this->app->instance(EInvoiceXmlGenerator::class, Mockery::mock(EInvoiceXmlGenerator::class)
            ->shouldReceive('generate')
            ->andThrow(new EInvoiceXmlMappingException('broken', fieldPath: 'seller.vat'))
            ->getMock());

        $this->expectException(ZatcaRequiredIssueBlockedException::class);
        app(InvoiceService::class)->issue($invoice, (int) $owner->id);
        $this->assertSame('draft', $invoice->fresh()->invoice_status);
    }

    public function test_not_required_incomplete_seller_still_issues(): void
    {
        [$owner, $workspace, $customer] = $this->boot();
        FinanceSetting::withoutGlobalScopes()->where('workspace_id', $workspace->id)->update(['street' => null]);
        $invoice = $this->createDraft($workspace, $customer, (int) $owner->id, ['zatca_requirement' => 'not_required']);

        $issued = app(InvoiceService::class)->issue($invoice, (int) $owner->id);
        $this->assertSame('issued', $issued->invoice_status);
    }

    public function test_failed_required_issue_twice_does_not_duplicate_gl(): void
    {
        [$owner, $workspace, $customer] = $this->boot();
        FinanceSetting::withoutGlobalScopes()->where('workspace_id', $workspace->id)->update(['city' => null]);
        $invoice = $this->createDraft($workspace, $customer, (int) $owner->id, ['zatca_requirement' => 'required']);

        foreach ([1, 2] as $_) {
            try {
                app(InvoiceService::class)->issue($invoice->fresh(), (int) $owner->id);
            } catch (ZatcaRequiredIssueBlockedException) {
            }
        }

        $this->assertSame(0, FinanceJournalEntry::withoutGlobalScopes()
            ->where('reference_type', FinanceInvoice::class)
            ->where('reference_id', $invoice->id)
            ->count());
    }

    public function test_required_issue_idempotent_on_success(): void
    {
        [$owner, $workspace, $customer] = $this->boot();
        $this->completeSeller($workspace);
        $invoice = $this->createDraft($workspace, $customer, (int) $owner->id, ['zatca_requirement' => 'required']);
        $first = app(InvoiceService::class)->issue($invoice, (int) $owner->id);
        $second = app(InvoiceService::class)->issue($first->fresh(), (int) $owner->id);
        $this->assertSame($first->id, $second->id);
        $this->assertSame('issued', $second->invoice_status);
    }

    public function test_api_issue_compliance_rejection_returns_422_with_compliance_payload(): void
    {
        [$owner, $workspace, $customer] = $this->boot();
        FinanceSetting::withoutGlobalScopes()->where('workspace_id', $workspace->id)->update(['city' => null]);
        $invoice = $this->createDraft($workspace, $customer, (int) $owner->id, ['zatca_requirement' => 'required']);

        Sanctum::actingAs($owner);
        $response = $this->withHeaders(['X-Workspace-Id' => (string) $workspace->id])
            ->postJson('/api/finance/v1/sales-invoices/'.$invoice->id.'/issue');

        $response->assertStatus(422);
        $response->assertJsonPath('code', 'cannot_issue');
        $this->assertIsArray($response->json('compliance'));
        $this->assertFalse($response->json('compliance.ready'));
    }

    public function test_schematron_unavailable_does_not_block_required_issue_when_xsd_passes(): void
    {
        if (app(XsdValidator::class)->schemaRoot() === null) {
            $this->markTestSkipped('UBL XSD bundle not available.');
        }

        [$owner, $workspace, $customer] = $this->boot();
        $this->completeSeller($workspace);
        $invoice = $this->createDraft($workspace, $customer, (int) $owner->id, ['zatca_requirement' => 'required']);
        $issued = app(InvoiceService::class)->issue($invoice, (int) $owner->id);

        $assessment = app(ComplianceReadinessService::class)->assessFinanceInvoice($issued->fresh());
        $this->assertSame(ValidationChannelStatus::NotAvailable, $assessment->validation?->schematron->status);
        $this->assertTrue($assessment->passesRequiredZatcaIssueGate());
    }

    /**
     * @param  array<string, mixed>  $extra
     */
    private function createDraft(Workspace $workspace, Customer $customer, int $actorId, array $extra = []): FinanceInvoice
    {
        return app(InvoiceService::class)->create($workspace, array_merge([
            'type' => 'sales',
            'customer_id' => $customer->id,
            'issue_date' => now()->toDateString(),
            'currency' => 'SAR',
            'invoice_status' => 'draft',
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
        ], $extra), $actorId);
    }

    /**
     * @return array{0: User, 1: Workspace, 2: Customer}
     */
    private function boot(): array
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
        $customer = Customer::withoutGlobalScopes()->create([
            'workspace_id' => $workspace->id,
            'name' => 'Buyer',
            'phone' => '0512345678',
            'street' => 'Buyer Street',
            'city' => 'Jeddah',
            'country_code' => 'SA',
        ]);

        return [$user, $workspace->fresh(), $customer];
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
}
