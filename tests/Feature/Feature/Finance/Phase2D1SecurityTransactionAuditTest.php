<?php

namespace Tests\Feature\Feature\Finance;

use App\EInvoicing\Xml\EInvoiceXmlMappingException;
use App\EInvoicing\Xml\Validation\XsdValidator;
use App\Enums\Finance\TaxProfileType;
use App\Exceptions\Finance\ZatcaRequiredIssueBlockedException;
use App\Models\Customer;
use App\Models\EInvoicing\EgsUnit;
use App\Models\EInvoicing\EInvoiceSecurityRecord;
use App\Models\Finance\FinanceInvoice;
use App\Models\Finance\FinanceJournalEntry;
use App\Models\Finance\FinanceSetting;
use App\Models\Finance\IssuedDocumentSnapshot;
use App\Models\Plan;
use App\Models\Subscription;
use App\Models\User;
use App\Models\Workspace;
use App\Models\WorkspaceFeatureFlag;
use App\Services\EInvoicing\EInvoiceXmlGenerator;
use App\Services\EInvoicing\InvoiceIssueService;
use App\Services\Finance\FinanceBootstrapService;
use App\Services\Finance\InvoiceService;
use App\Support\Tenancy\WorkspaceContext;
use Database\Seeders\FoundationSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Mockery;
use Tests\TestCase;

class Phase2D1SecurityTransactionAuditTest extends TestCase
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

    public function test_required_security_generation_failure_rolls_back_issue_and_gl(): void
    {
        [$owner, $workspace, $customer] = $this->boot();
        $this->completeSeller($workspace);
        $this->seedInactiveEgsUnit($workspace);
        $invoice = $this->createDraft($workspace, $customer, (int) $owner->id, ['zatca_requirement' => 'required']);
        $this->assertSame('required', $invoice->zatca_requirement);

        try {
            $this->issueFresh($invoice, (int) $owner->id);
            $this->fail('Expected ZatcaRequiredIssueBlockedException');
        } catch (ZatcaRequiredIssueBlockedException) {
        }

        $this->assertSame('draft', $invoice->fresh()->invoice_status);
        $this->assertSame(0, $this->glCount($invoice));
        $this->assertSame(0, IssuedDocumentSnapshot::withoutGlobalScopes()->where('source_id', $invoice->id)->count());
        $this->assertSame(0, EInvoiceSecurityRecord::withoutGlobalScopes()->count());
    }

    public function test_required_downstream_xml_failure_after_gate_rolls_back(): void
    {
        if (app(XsdValidator::class)->schemaRoot() === null) {
            $this->markTestSkipped('UBL XSD bundle not available.');
        }

        [$owner, $workspace, $customer] = $this->boot();
        $this->completeSeller($workspace);
        $invoice = $this->createDraft($workspace, $customer, (int) $owner->id, ['zatca_requirement' => 'required']);

        $real = app(EInvoiceXmlGenerator::class);
        $mock = Mockery::mock(EInvoiceXmlGenerator::class);
        $mock->shouldReceive('generate')
            ->andReturnUsing(function ($document, bool $validate = false) use ($real) {
                static $calls = 0;
                $calls++;
                if ($calls >= 2) {
                    throw new EInvoiceXmlMappingException('Downstream XML failure', fieldPath: 'xml');
                }

                return $real->generate($document, $validate);
            });
        $this->app->instance(EInvoiceXmlGenerator::class, $mock);
        $this->forgetIssueServices();

        try {
            $this->issueFresh($invoice, (int) $owner->id);
            $this->fail('Expected issue to fail when downstream XML breaks');
        } catch (ZatcaRequiredIssueBlockedException) {
        }

        $this->assertSame('draft', $invoice->fresh()->invoice_status);
        $this->assertSame(0, $this->glCount($invoice));
    }

    public function test_not_required_invoice_commits_when_security_skipped(): void
    {
        [$owner, $workspace, $customer] = $this->boot();
        FinanceSetting::withoutGlobalScopes()->where('workspace_id', $workspace->id)->update(['street' => null]);
        $this->seedInactiveEgsUnit($workspace);
        $invoice = $this->createDraft($workspace, $customer, (int) $owner->id, ['zatca_requirement' => 'not_required']);

        $issued = $this->issueFresh($invoice, (int) $owner->id);
        $this->assertSame('issued', $issued->invoice_status);
        $this->assertGreaterThan(0, $this->glCount($issued));
    }

    public function test_icv_sequence_not_advanced_when_required_security_fails(): void
    {
        if (app(XsdValidator::class)->schemaRoot() === null) {
            $this->markTestSkipped('UBL XSD bundle not available.');
        }

        [$owner, $workspace, $customer] = $this->boot();
        $this->completeSeller($workspace);
        $this->seedInactiveEgsUnit($workspace);

        $invoiceA = $this->createDraft($workspace, $customer, (int) $owner->id, ['zatca_requirement' => 'required']);
        try {
            $this->issueFresh($invoiceA, (int) $owner->id);
        } catch (ZatcaRequiredIssueBlockedException) {
        }

        EgsUnit::withoutGlobalScopes()
            ->where('workspace_id', $workspace->id)
            ->update(['status' => EgsUnit::STATUS_ACTIVE]);

        $invoiceB = $this->createDraft($workspace, $customer, (int) $owner->id, ['zatca_requirement' => 'required']);
        $this->issueFresh($invoiceB, (int) $owner->id);

        $unit = EgsUnit::withoutGlobalScopes()->where('workspace_id', $workspace->id)->firstOrFail();
        $this->assertSame(2, (int) $unit->fresh()->next_icv);
        $this->assertSame(1, EInvoiceSecurityRecord::withoutGlobalScopes()->count());
    }

    public function test_required_failed_issue_twice_does_not_duplicate_gl(): void
    {
        [$owner, $workspace, $customer] = $this->boot();
        $this->completeSeller($workspace);
        $this->seedInactiveEgsUnit($workspace);
        $invoice = $this->createDraft($workspace, $customer, (int) $owner->id, ['zatca_requirement' => 'required']);

        foreach ([1, 2] as $_) {
            try {
                $this->issueFresh($invoice->fresh(), (int) $owner->id);
            } catch (ZatcaRequiredIssueBlockedException) {
            }
        }

        $this->assertSame(0, $this->glCount($invoice));
    }

    private function seedInactiveEgsUnit(Workspace $workspace): void
    {
        EgsUnit::withoutGlobalScopes()->updateOrCreate(
            [
                'workspace_id' => $workspace->id,
                'uuid' => EgsUnit::defaultUuidForWorkspace((int) $workspace->id),
            ],
            [
                'workspace_id' => $workspace->id,
                'uuid' => EgsUnit::defaultUuidForWorkspace((int) $workspace->id),
                'name' => 'Default EGS',
                'status' => 'inactive',
                'next_icv' => 1,
                'last_invoice_hash' => null,
            ],
        );
    }

    private function forgetIssueServices(): void
    {
        $this->app->forgetInstance(InvoiceIssueService::class);
        $this->app->forgetInstance(InvoiceService::class);
        $this->app->forgetInstance(\App\Services\EInvoicing\ZatcaRequiredIssueGate::class);
        $this->app->forgetInstance(\App\Services\EInvoicing\ComplianceReadinessService::class);
    }

    private function issueFresh(FinanceInvoice $invoice, int $actorId): FinanceInvoice
    {
        return app(InvoiceService::class)->issue($invoice, $actorId);
    }

    private function glCount(FinanceInvoice $invoice): int
    {
        return FinanceJournalEntry::withoutGlobalScopes()
            ->where('reference_type', FinanceInvoice::class)
            ->where('reference_id', $invoice->id)
            ->count();
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
