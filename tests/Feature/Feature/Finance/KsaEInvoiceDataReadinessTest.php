<?php

namespace Tests\Feature\Feature\Finance;

use App\EInvoicing\EInvoiceParty;
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
use App\Services\EInvoicing\ComplianceReadinessService;
use App\Services\Finance\EInvoiceArtifactService;
use App\Services\EInvoicing\FinanceInvoicePartyResolver;
use App\Services\EInvoicing\KsaEInvoiceDataReadinessEvaluator;
use App\Services\Finance\Api\InvoiceDocumentReadService;
use App\Services\Finance\FinanceBootstrapService;
use App\Services\Finance\InvoiceService;
use App\Support\Tenancy\WorkspaceContext;
use Database\Seeders\FoundationSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class KsaEInvoiceDataReadinessTest extends TestCase
{
    use RefreshDatabase;

    private KsaEInvoiceDataReadinessEvaluator $evaluator;

    protected function setUp(): void
    {
        parent::setUp();
        Notification::fake();
        $this->seed(FoundationSeeder::class);
        $this->evaluator = app(KsaEInvoiceDataReadinessEvaluator::class);
    }

    public function test_complete_seller_profile_is_ready(): void
    {
        $party = $this->sellerParty([
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

        $readiness = $this->evaluator->evaluateSeller($party);
        $this->assertTrue($readiness->ready);
        $this->assertSame([], $readiness->errors);
    }

    public function test_seller_missing_vat_is_not_ready(): void
    {
        $readiness = $this->evaluator->evaluateSeller($this->sellerParty(['vat_number' => null]));
        $this->assertFalse($readiness->ready);
        $this->assertSame('seller.vat_number', $readiness->errors[0]->field);
    }

    public function test_seller_missing_legal_name_is_not_ready(): void
    {
        $readiness = $this->evaluator->evaluateSeller($this->sellerParty(['company_name' => null, 'name' => null]));
        $this->assertFalse($readiness->ready);
        $this->assertSame('seller.name', $readiness->errors[0]->field);
    }

    public function test_seller_missing_street_is_not_ready(): void
    {
        $readiness = $this->evaluator->evaluateSeller($this->sellerParty(['street' => null, 'address_line' => null]));
        $this->assertFalse($readiness->ready);
        $this->assertSame('seller.address.street', $readiness->errors[0]->field);
    }

    public function test_seller_missing_building_number_is_not_ready(): void
    {
        $readiness = $this->evaluator->evaluateSeller($this->sellerParty(['building_number' => null]));
        $this->assertFalse($readiness->ready);
        $this->assertSame('seller.address.building_number', $readiness->errors[0]->field);
    }

    public function test_seller_missing_city_is_not_ready(): void
    {
        $readiness = $this->evaluator->evaluateSeller($this->sellerParty(['city' => null]));
        $this->assertFalse($readiness->ready);
        $this->assertSame('seller.address.city', $readiness->errors[0]->field);
    }

    public function test_seller_missing_postal_code_is_not_ready(): void
    {
        $readiness = $this->evaluator->evaluateSeller($this->sellerParty(['postal_code' => null]));
        $this->assertFalse($readiness->ready);
        $this->assertSame('seller.address.postal_code', $readiness->errors[0]->field);
    }

    public function test_seller_missing_country_is_not_ready(): void
    {
        $readiness = $this->evaluator->evaluateSeller($this->sellerParty(['country_code' => null]));
        $this->assertFalse($readiness->ready);
        $this->assertSame('seller.address.country_code', $readiness->errors[0]->field);
    }

    public function test_standard_buyer_complete_profile_is_ready(): void
    {
        $buyer = $this->buyerParty([
            'name' => 'Buyer Co',
            'street' => 'Buyer Street',
            'city' => 'Jeddah',
            'country_code' => 'SA',
        ]);

        $readiness = $this->evaluator->evaluateBuyer($buyer, standard: true);
        $this->assertTrue($readiness->ready);
    }

    public function test_standard_buyer_missing_street_is_not_ready(): void
    {
        $readiness = $this->evaluator->evaluateBuyer(
            $this->buyerParty(['name' => 'Buyer', 'street' => null, 'city' => 'Jeddah', 'country_code' => 'SA']),
            standard: true,
        );
        $this->assertFalse($readiness->ready);
        $this->assertSame('buyer.address.street', $readiness->errors[0]->field);
    }

    public function test_standard_buyer_missing_city_is_not_ready(): void
    {
        $readiness = $this->evaluator->evaluateBuyer(
            $this->buyerParty(['name' => 'Buyer', 'street' => 'St', 'city' => null, 'country_code' => 'SA']),
            standard: true,
        );
        $this->assertFalse($readiness->ready);
        $this->assertSame('buyer.address.city', $readiness->errors[0]->field);
    }

    public function test_standard_buyer_missing_country_is_not_ready(): void
    {
        $readiness = $this->evaluator->evaluateBuyer(
            $this->buyerParty(['name' => 'Buyer', 'street' => 'St', 'city' => 'Jeddah', 'country_code' => null]),
            standard: true,
        );
        $this->assertFalse($readiness->ready);
        $this->assertSame('buyer.address.country_code', $readiness->errors[0]->field);
    }

    public function test_standard_walk_in_buyer_does_not_require_address(): void
    {
        $readiness = $this->evaluator->evaluateBuyer(
            $this->buyerParty(['name' => 'Walk-in', 'walk_in' => true, 'street' => null, 'city' => null, 'country_code' => null]),
            standard: true,
        );
        $this->assertTrue($readiness->ready);
        $this->assertSame('walk_in_relaxed', $readiness->fields['buyer.address']);
    }

    public function test_simplified_walk_in_buyer_is_ready_without_b2b_address(): void
    {
        $readiness = $this->evaluator->evaluateBuyer(
            $this->buyerParty(['name' => 'Retail', 'walk_in' => true]),
            standard: false,
        );
        $this->assertTrue($readiness->ready);
    }

    public function test_draft_readiness_reflects_live_settings_changes(): void
    {
        [$owner, $workspace] = $this->createWorkspaceOwner();
        $customer = $this->makeBuyer($workspace, 'Draft Buyer');
        FinanceSetting::withoutGlobalScopes()->where('workspace_id', $workspace->id)->update(['street' => null]);
        $draft = $this->issueFinance($workspace, $customer, (int) $owner->id, [
            'invoice_status' => 'draft',
        ]);

        $before = app(ComplianceReadinessService::class)->assessFinanceInvoice($draft->fresh());
        $this->assertFalse($before->seller->ready);
        $this->assertSame('live', $before->dataSource);

        $this->completeSeller($workspace);
        $after = app(ComplianceReadinessService::class)->assessFinanceInvoice($draft->fresh());
        $this->assertTrue($after->seller->ready);
    }

    public function test_issued_invoice_readiness_uses_snapshot_not_live_settings(): void
    {
        [$owner, $workspace] = $this->createWorkspaceOwner();
        $customer = $this->makeBuyer($workspace, 'Snapshot Seller Test');
        $this->completeSeller($workspace);
        $invoice = $this->issueFinance($workspace, $customer, (int) $owner->id);

        $before = app(ComplianceReadinessService::class)->assessFinanceInvoice($invoice->fresh());
        $this->assertTrue($before->seller->ready);
        $this->assertSame('snapshot', $before->dataSource);

        FinanceSetting::withoutGlobalScopes()->where('workspace_id', $workspace->id)->update(['street' => null]);

        $after = app(ComplianceReadinessService::class)->assessFinanceInvoice($invoice->fresh());
        $this->assertTrue($after->seller->ready);
        $this->assertSame('snapshot', $after->dataSource);
    }

    public function test_issued_invoice_readiness_ignores_live_customer_mutations(): void
    {
        [$owner, $workspace] = $this->createWorkspaceOwner();
        $customer = $this->makeBuyer($workspace, 'Immutable Buyer');
        $this->completeSeller($workspace);
        $invoice = $this->issueFinance($workspace, $customer, (int) $owner->id);

        $before = app(ComplianceReadinessService::class)->assessFinanceInvoice($invoice->fresh());
        $this->assertTrue($before->buyer->ready);

        $customer->update(['street' => null, 'city' => null, 'country_code' => null]);

        $after = app(ComplianceReadinessService::class)->assessFinanceInvoice($invoice->fresh());
        $this->assertTrue($after->buyer->ready);
    }

    public function test_api_exposes_structured_compliance_errors_with_field_paths(): void
    {
        [$owner, $workspace] = $this->createWorkspaceOwner();
        $customer = $this->makeBuyer($workspace, 'Api Buyer');
        FinanceSetting::withoutGlobalScopes()->where('workspace_id', $workspace->id)->update([
            'company_name' => 'Issued Co',
            'vat_number' => '310000000000003',
            'commercial_registration' => '1010000000',
            'street' => null,
            'building_number' => '1234',
            'district' => 'Al Olaya',
            'city' => 'Riyadh',
            'postal_code' => '12345',
            'country_code' => 'SA',
        ]);
        $invoice = $this->issueFinance($workspace, $customer, (int) $owner->id, ['invoice_status' => 'draft']);

        Sanctum::actingAs($owner);
        $response = $this->withHeaders($this->workspaceHeader($workspace))
            ->getJson('/api/finance/v1/invoices/'.$invoice->id)
            ->assertOk();

        $errors = $response->json('data.compliance.errors') ?? [];
        $this->assertNotEmpty($errors);
        $fields = array_column($errors, 'field');
        $this->assertContains('seller.address.street', $fields);
        $this->assertArrayHasKey('code', $errors[0]);
        $this->assertArrayHasKey('message', $errors[0]);
    }

    public function test_artifact_payload_keeps_xml_qr_fields_and_adds_seller_readiness(): void
    {
        [$owner, $workspace] = $this->createWorkspaceOwner();
        $customer = $this->makeBuyer($workspace, 'Artifact Buyer');
        $this->completeSeller($workspace);
        $invoice = $this->issueFinance($workspace, $customer, (int) $owner->id);

        $artifact = app(EInvoiceArtifactService::class)->availabilityForInvoice($invoice->fresh());
        $this->assertArrayHasKey('xml_available', $artifact);
        $this->assertArrayHasKey('qr_available', $artifact);
        $this->assertArrayHasKey('has_xml', $artifact);
        $this->assertTrue($artifact['compliance']['seller']['ready'] ?? false);
    }

    public function test_settings_api_includes_workspace_seller_e_invoice_readiness(): void
    {
        [$owner, $workspace] = $this->createWorkspaceOwner();
        $this->completeSeller($workspace);

        Sanctum::actingAs($owner);
        $this->withHeaders($this->workspaceHeader($workspace))
            ->getJson('/api/finance/v1/settings')
            ->assertOk()
            ->assertJsonPath('data.e_invoice_readiness.seller.ready', true);
    }

    public function test_flag_parity_includes_seller_readiness(): void
    {
        [$owner, $workspace] = $this->createWorkspaceOwner();
        $customer = $this->makeBuyer($workspace, 'Parity Buyer');
        $this->completeSeller($workspace);
        $invoice = $this->issueFinance($workspace, $customer, (int) $owner->id);
        app(WorkspaceContext::class)->set($workspace);

        $invoice = $invoice->fresh();
        $assessment = app(ComplianceReadinessService::class)->assessFinanceInvoice($invoice);
        $details = app(InvoiceDocumentReadService::class)->financeDetails($invoice);

        $this->assertSame($assessment->seller->ready, $details->compliance->seller['ready'] ?? null);
        $this->assertSame($assessment->buyer->ready, $details->compliance->buyer['ready'] ?? null);
    }

    /**
     * @param  array<string, mixed>  $overrides
     */
    private function sellerParty(array $overrides = []): EInvoiceParty
    {
        return EInvoiceParty::fromSnapshot(array_merge([
            'kind' => 'company',
            'company_name' => 'Issued Co',
            'vat_number' => '310000000000003',
            'commercial_registration' => '1010000000',
            'street' => 'King Fahd Road',
            'building_number' => '1234',
            'district' => 'Al Olaya',
            'city' => 'Riyadh',
            'postal_code' => '12345',
            'country_code' => 'SA',
        ], $overrides));
    }

    /**
     * @param  array<string, mixed>  $overrides
     */
    private function buyerParty(array $overrides = []): EInvoiceParty
    {
        return EInvoiceParty::fromSnapshot(array_merge([
            'kind' => 'customer',
            'walk_in' => false,
            'name' => 'Buyer',
            'street' => 'Buyer Street',
            'city' => 'Jeddah',
            'country_code' => 'SA',
        ], $overrides));
    }

    /**
     * @return array{0: User, 1: Workspace}
     */
    private function createWorkspaceOwner(string $name = 'KSA Readiness Workspace'): array
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

    /**
     * @return array<string, string>
     */
    private function workspaceHeader(Workspace $workspace): array
    {
        return ['X-Workspace-Id' => (string) $workspace->id];
    }
}
