<?php

namespace Tests\Feature\Feature\Finance;

use App\EInvoicing\Xml\Validation\SchematronValidator;
use App\EInvoicing\Xml\Validation\XsdValidator;
use App\Enums\EInvoicing\ValidationChannelStatus;
use App\Enums\Finance\TaxProfileType;
use App\Models\Customer;
use App\Models\Finance\FinanceSetting;
use App\Models\Plan;
use App\Models\Subscription;
use App\Models\User;
use App\Models\Workspace;
use App\Models\WorkspaceFeatureFlag;
use App\Services\EInvoicing\ComplianceReadinessService;
use App\Services\EInvoicing\EInvoiceXmlValidationService;
use App\Services\Finance\FinanceBootstrapService;
use App\Services\Finance\InvoiceService;
use App\Support\Tenancy\WorkspaceContext;
use Database\Seeders\FoundationSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class Phase2CXmlValidationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Notification::fake();
        $this->seed(FoundationSeeder::class);
    }

    public function test_malformed_xml_is_not_well_formed(): void
    {
        $assessment = app(EInvoiceXmlValidationService::class)->assessXml('<Invoice><unclosed>', 'test');
        $this->assertFalse($assessment->wellFormed);
        $this->assertFalse($assessment->isFullyValid());
    }

    public function test_invalid_xml_fails_xsd_with_structured_issues(): void
    {
        $invalid = <<<'XML'
<?xml version="1.0" encoding="UTF-8"?>
<Invoice xmlns="urn:oasis:names:specification:ubl:schema:xsd:Invoice-2">
    <NotAUblElement>broken</NotAUblElement>
</Invoice>
XML;
        $result = app(XsdValidator::class)->validateStructured($invalid);
        $this->assertSame(ValidationChannelStatus::Invalid, $result->status);
        $this->assertNotEmpty($result->issues);
        $this->assertSame('xsd', $result->issues[0]->source);
    }

    public function test_issued_invoice_validation_runs_on_snapshot_xml(): void
    {
        [$owner, $workspace] = $this->bootWorkspace();
        $customer = $this->makeBuyer($workspace);
        $this->completeSeller($workspace);
        $invoice = $this->issueFinance($workspace, $customer, (int) $owner->id);

        $assessment = app(ComplianceReadinessService::class)->assessFinanceInvoice($invoice->fresh());
        $this->assertTrue($assessment->xmlAvailable);
        $this->assertNotNull($assessment->validation);
        $this->assertTrue($assessment->validation->wellFormed);

        if (app(XsdValidator::class)->schemaRoot() === null) {
            $this->assertSame(ValidationChannelStatus::NotAvailable, $assessment->validation->xsd->status);
        } else {
            $this->assertNotSame(ValidationChannelStatus::NotAvailable, $assessment->validation->xsd->status);
        }
    }

    public function test_xsd_not_available_is_explicit_and_not_treated_as_valid(): void
    {
        $emptyRoot = sys_get_temp_dir().'/hasem-xsd-empty-'.uniqid('', true);
        mkdir($emptyRoot);
        try {
            $validator = new XsdValidator(schemaRoot: $emptyRoot);
            $result = $validator->validateStructured('<Invoice xmlns="urn:oasis:names:specification:ubl:schema:xsd:Invoice-2"/>');
            $this->assertSame(ValidationChannelStatus::NotAvailable, $result->status);
            $this->assertNotSame(ValidationChannelStatus::Valid, $result->status);
        } finally {
            @rmdir($emptyRoot);
        }
    }

    public function test_schematron_local_rules_fail_invalid_root(): void
    {
        $invalid = '<?xml version="1.0"?><WrongRoot xmlns="urn:example:invalid"/>';
        $path = SchematronValidator::localHarnessRulesFile();
        $this->assertNotNull($path);
        $result = (new SchematronValidator(rulesPath: $path))->validateStructured($invalid);
        $this->assertSame(ValidationChannelStatus::Invalid, $result->status);
    }

    public function test_issued_validation_persists_and_ignores_live_settings_mutations(): void
    {
        [$owner, $workspace] = $this->bootWorkspace();
        $customer = $this->makeBuyer($workspace);
        $this->completeSeller($workspace);
        $invoice = $this->issueFinance($workspace, $customer, (int) $owner->id);

        $first = app(ComplianceReadinessService::class)->assessFinanceInvoice($invoice->fresh());
        $this->assertNotNull($first->validation);
        $this->assertSame('snapshot', $first->validation->dataSource);
        $storedXsd = $first->documentRecord?->fresh()->xsd_validation_status;

        FinanceSetting::withoutGlobalScopes()->where('workspace_id', $workspace->id)->update(['street' => null]);

        $second = app(ComplianceReadinessService::class)->assessFinanceInvoice($invoice->fresh());
        $this->assertSame($storedXsd, $second->documentRecord?->fresh()->xsd_validation_status);
        $this->assertTrue($second->seller->ready);
    }

    public function test_api_exposes_additive_validation_block(): void
    {
        [$owner, $workspace] = $this->bootWorkspace();
        $customer = $this->makeBuyer($workspace);
        $this->completeSeller($workspace);
        $invoice = $this->issueFinance($workspace, $customer, (int) $owner->id);

        Sanctum::actingAs($owner);
        $response = $this->withHeaders(['X-Workspace-Id' => (string) $workspace->id])
            ->getJson('/api/finance/v1/invoices/'.$invoice->id)
            ->assertOk();

        $this->assertTrue($response->json('data.compliance.xml_available'));
        $this->assertArrayHasKey('validation', $response->json('data.compliance') ?? []);
        $this->assertArrayHasKey('xsd', $response->json('data.compliance.validation') ?? []);
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

    private function issueFinance(Workspace $workspace, Customer $customer, int $actorId)
    {
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
        ], $actorId);
    }
}
