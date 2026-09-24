<?php

namespace Tests\Feature\Feature\Finance;

use App\EInvoicing\Xml\EInvoiceXmlMappingException;
use App\Enums\Finance\TaxProfileType;
use App\Models\Customer;
use App\Models\EInvoicing\EInvoiceDocumentRecord;
use App\Models\EInvoicing\EInvoiceSecurityRecord;
use App\Models\Finance\FinanceInvoice;
use App\Models\Finance\FinanceSetting;
use App\Models\Finance\IssuedDocumentSnapshot;
use App\Models\Plan;
use App\Models\Subscription;
use App\Models\User;
use App\Models\Workspace;
use App\Models\WorkspaceFeatureFlag;
use App\Services\EInvoicing\ComplianceReadinessService;
use App\Services\EInvoicing\EInvoiceXmlGenerator;
use App\Services\Finance\Api\InvoiceDocumentReadService;
use App\Services\Finance\EInvoiceArtifactService;
use App\Services\Finance\FinanceBootstrapService;
use App\Services\Finance\InvoiceService;
use App\Support\Tenancy\WorkspaceContext;
use Database\Seeders\FoundationSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Mockery;
use Tests\TestCase;

class ComplianceReadinessServiceTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Notification::fake();
        $this->seed(FoundationSeeder::class);
    }

    public function test_snapshot_with_xml_generation_failure_reports_xml_unavailable(): void
    {
        [$owner, $workspace] = $this->createWorkspaceOwner();
        $customer = $this->makeBuyer($workspace, 'Xml Fail Buyer');
        $this->completeSeller($workspace);
        $invoice = $this->issueFinance($workspace, $customer, (int) $owner->id);

        $this->bindXmlGeneratorMock(
            Mockery::mock(EInvoiceXmlGenerator::class)
                ->shouldReceive('generate')
                ->andThrow(new EInvoiceXmlMappingException('Seller VAT missing', fieldPath: 'seller.vat'))
                ->getMock()
        );

        $assessment = app(ComplianceReadinessService::class)->assessFinanceInvoice($invoice->fresh());

        $this->assertTrue($assessment->snapshotAvailable);
        $this->assertTrue($assessment->issued);
        $this->assertTrue($assessment->eligible);
        $this->assertFalse($assessment->xmlAvailable);
        $this->assertNotEmpty($assessment->errors);
    }

    public function test_snapshot_with_successful_xml_generation_reports_xml_available(): void
    {
        [$owner, $workspace] = $this->createWorkspaceOwner();
        $customer = $this->makeBuyer($workspace, 'Xml Ok Buyer');
        $this->completeSeller($workspace);
        $invoice = $this->issueFinance($workspace, $customer, (int) $owner->id);

        $assessment = app(ComplianceReadinessService::class)->assessFinanceInvoice($invoice->fresh());

        $this->assertTrue($assessment->snapshotAvailable);
        $this->assertTrue($assessment->eligible);
        $this->assertTrue($assessment->xmlAvailable);
    }

    public function test_missing_security_record_reports_security_unavailable_and_qr_respects_prerequisites(): void
    {
        [$owner, $workspace] = $this->createWorkspaceOwner();
        $customer = $this->makeBuyer($workspace, 'No Security Buyer');
        $this->completeSeller($workspace);
        $invoice = $this->issueFinance($workspace, $customer, (int) $owner->id);

        EInvoiceSecurityRecord::withoutGlobalScopes()->delete();

        $assessment = app(ComplianceReadinessService::class)->assessFinanceInvoice($invoice->fresh());

        $this->assertTrue($assessment->xmlAvailable);
        $this->assertFalse($assessment->securityAvailable);
        $this->assertFalse($assessment->qrAvailable);
    }

    public function test_security_record_with_valid_prerequisites_enables_qr_available(): void
    {
        [$owner, $workspace] = $this->createWorkspaceOwner();
        $customer = $this->makeBuyer($workspace, 'Qr Ok Buyer');
        $this->completeSeller($workspace);
        $invoice = $this->issueFinance($workspace, $customer, (int) $owner->id);

        $assessment = app(ComplianceReadinessService::class)->assessFinanceInvoice($invoice->fresh());

        $this->assertTrue($assessment->securityAvailable);
        $this->assertTrue($assessment->xmlAvailable);
        $this->assertTrue($assessment->qrAvailable);
    }

    public function test_not_required_zatca_invoice_is_not_classified_as_zatca_required(): void
    {
        [$owner, $workspace] = $this->createWorkspaceOwner();
        $customer = $this->makeBuyer($workspace, 'Not Required Buyer');
        $this->completeSeller($workspace);
        $invoice = $this->issueFinance($workspace, $customer, (int) $owner->id, [
            'zatca_requirement' => 'not_required',
        ]);

        $assessment = app(ComplianceReadinessService::class)->assessFinanceInvoice($invoice->fresh());

        $this->assertFalse($assessment->zatcaRequired);
        $this->assertTrue($assessment->xmlAvailable);
        $this->assertEmpty(array_filter(
            $assessment->warnings,
            fn (string $warning) => str_contains(strtolower($warning), 'zatca e-invoice is marked required')
        ));
    }

    public function test_required_zatca_with_xml_failure_exposes_warnings_without_false_readiness(): void
    {
        [$owner, $workspace] = $this->createWorkspaceOwner();
        $customer = $this->makeBuyer($workspace, 'Required Buyer');
        $this->completeSeller($workspace);
        $invoice = $this->issueFinance($workspace, $customer, (int) $owner->id, [
            'zatca_requirement' => 'required',
        ]);

        $this->bindXmlGeneratorMock(
            Mockery::mock(EInvoiceXmlGenerator::class)
                ->shouldReceive('generate')
                ->andThrow(new EInvoiceXmlMappingException('Incomplete mapping', fieldPath: 'lines.0'))
                ->getMock()
        );

        $assessment = app(ComplianceReadinessService::class)->assessFinanceInvoice($invoice->fresh());

        $this->assertTrue($assessment->zatcaRequired);
        $this->assertFalse($assessment->xmlAvailable);
        $this->assertNotEmpty($assessment->errors);
        $this->assertNotEmpty($assessment->warnings);
    }

    public function test_artifact_read_service_and_compliance_service_report_same_flags(): void
    {
        [$owner, $workspace] = $this->createWorkspaceOwner();
        $customer = $this->makeBuyer($workspace, 'Parity Buyer');
        $this->completeSeller($workspace);
        $invoice = $this->issueFinance($workspace, $customer, (int) $owner->id);
        app(WorkspaceContext::class)->set($workspace);

        $invoice = $invoice->fresh();
        $assessment = app(ComplianceReadinessService::class)->assessFinanceInvoice($invoice);
        $artifact = app(EInvoiceArtifactService::class)->availabilityForInvoice($invoice);
        $details = app(InvoiceDocumentReadService::class)->financeDetails($invoice);

        $this->assertSame($assessment->xmlAvailable, $artifact['xml_available']);
        $this->assertSame($assessment->qrAvailable, $artifact['qr_available']);
        $this->assertSame($assessment->xmlAvailable, $details->compliance->xmlAvailable);
        $this->assertSame($assessment->qrAvailable, $details->compliance->qrAvailable);
        $this->assertSame($assessment->xmlAvailable, $artifact['compliance']['xml_available'] ?? null);
        $this->assertSame($assessment->securityAvailable, $artifact['compliance']['security_available'] ?? null);
    }

    public function test_assessment_does_not_mutate_persisted_records(): void
    {
        [$owner, $workspace] = $this->createWorkspaceOwner();
        $customer = $this->makeBuyer($workspace, 'No Mutation Buyer');
        $this->completeSeller($workspace);
        $invoice = $this->issueFinance($workspace, $customer, (int) $owner->id);

        $countsBefore = $this->persistedCounts($workspace, $invoice);
        $statusBefore = (string) $invoice->fresh()->invoice_status;
        $snapshotRowBefore = IssuedDocumentSnapshot::withoutGlobalScopes()
            ->where('source_type', IssuedDocumentSnapshot::SOURCE_FINANCE_INVOICE)
            ->where('source_id', $invoice->id)
            ->firstOrFail();
        $snapshotPayloadBefore = json_encode($snapshotRowBefore->payload);
        $snapshotUpdatedBefore = (string) $snapshotRowBefore->updated_at;

        app(ComplianceReadinessService::class)->assessFinanceInvoice($invoice->fresh());
        app(EInvoiceArtifactService::class)->availabilityForInvoice($invoice->fresh());
        app(InvoiceDocumentReadService::class)->financeDetails($invoice->fresh());

        $this->assertSame($countsBefore, $this->persistedCounts($workspace, $invoice));
        $this->assertSame($statusBefore, (string) $invoice->fresh()->invoice_status);
        $snapshotRowAfter = IssuedDocumentSnapshot::withoutGlobalScopes()
            ->where('source_type', IssuedDocumentSnapshot::SOURCE_FINANCE_INVOICE)
            ->where('source_id', $invoice->id)
            ->firstOrFail();
        $this->assertSame($snapshotPayloadBefore, json_encode($snapshotRowAfter->payload));
        $this->assertSame($snapshotUpdatedBefore, (string) $snapshotRowAfter->updated_at);
    }

    public function test_xml_assessment_does_not_query_live_mutable_business_tables(): void
    {
        [$owner, $workspace] = $this->createWorkspaceOwner();
        $customer = $this->makeBuyer($workspace, 'Snapshot Only Buyer');
        $this->completeSeller($workspace);
        $invoice = $this->issueFinance($workspace, $customer, (int) $owner->id);

        DB::flushQueryLog();
        DB::enableQueryLog();
        app(ComplianceReadinessService::class)->assessFinanceInvoice($invoice);
        $queries = DB::getQueryLog();
        DB::disableQueryLog();

        foreach ($queries as $query) {
            $sql = strtolower((string) ($query['query'] ?? ''));
            $this->assertStringNotContainsString('finance_invoices', $sql);
            $this->assertStringNotContainsString('customers', $sql);
            $this->assertStringNotContainsString('products', $sql);
            $this->assertStringNotContainsString('finance_settings', $sql);
            $this->assertStringNotContainsString('from "orders"', $sql);
            $this->assertStringNotContainsString('from `orders`', $sql);
            $this->assertStringNotContainsString('order_items', $sql);
            $this->assertStringNotContainsString('finance_invoice_payments', $sql);
        }
    }

    private function bindXmlGeneratorMock(EInvoiceXmlGenerator $mock): void
    {
        $this->app->forgetInstance(ComplianceReadinessService::class);
        $this->app->forgetInstance(EInvoiceArtifactService::class);
        $this->app->forgetInstance(InvoiceDocumentReadService::class);
        $this->app->instance(EInvoiceXmlGenerator::class, $mock);
    }

    /**
     * @return array<string, int>
     */
    private function persistedCounts(Workspace $workspace, FinanceInvoice $invoice): array
    {
        $snapshotId = IssuedDocumentSnapshot::withoutGlobalScopes()
            ->where('workspace_id', $workspace->id)
            ->where('source_type', IssuedDocumentSnapshot::SOURCE_FINANCE_INVOICE)
            ->where('source_id', $invoice->id)
            ->value('id');

        return [
            'snapshots' => IssuedDocumentSnapshot::withoutGlobalScopes()->count(),
            'documents' => EInvoiceDocumentRecord::withoutGlobalScopes()->count(),
            'security' => EInvoiceSecurityRecord::withoutGlobalScopes()->count(),
            'snapshot_rows_for_invoice' => IssuedDocumentSnapshot::withoutGlobalScopes()
                ->where('source_id', $invoice->id)
                ->count(),
            'snapshot_id' => (int) $snapshotId,
        ];
    }

    /**
     * @return array{0: User, 1: Workspace}
     */
    private function createWorkspaceOwner(string $name = 'Compliance Readiness Workspace'): array
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

    private function makeBuyer(Workspace $workspace, string $name): Customer
    {
        return Customer::withoutGlobalScopes()->create([
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
        ]);
    }

    /**
     * @param  array<string, mixed>  $overrides
     */
    private function issueFinance(Workspace $workspace, Customer $customer, int $actorId, array $overrides = []): FinanceInvoice
    {
        app(WorkspaceContext::class)->set($workspace);

        return app(InvoiceService::class)->create($workspace, array_merge([
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
        ], $overrides), $actorId);
    }
}
