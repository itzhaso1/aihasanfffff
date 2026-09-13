<?php

namespace Tests\Feature\Feature\Finance;

use App\Enums\Finance\TaxProfileType;
use App\Models\Customer;
use App\Models\Finance\FinanceInvoice;
use App\Models\Finance\FinanceSetting;
use App\Models\Plan;
use App\Models\PosMenuItem;
use App\Models\Subscription;
use App\Models\User;
use App\Models\Workspace;
use App\Models\WorkspaceFeatureFlag;
use App\Services\Finance\CreditNoteService;
use App\Services\Finance\FinanceBootstrapService;
use App\Services\Finance\InvoiceService;
use App\Services\Pos\PosOrderService;
use App\Support\Tenancy\WorkspaceContext;
use Database\Seeders\FoundationSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class Phase10InvoiceApiContractTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Notification::fake();
        $this->seed(FoundationSeeder::class);
    }

    public function test_finance_invoice_list_detail_xml_and_qr_contract(): void
    {
        [$owner, $workspace] = $this->createWorkspaceOwner();
        $customer = $this->makeBuyer($workspace, 'Api Buyer');
        $this->completeSeller($workspace);
        $invoice = $this->issueFinance($workspace, $customer, (int) $owner->id);

        Sanctum::actingAs($owner);

        $list = $this->withHeaders($this->workspaceHeader($workspace))
            ->getJson('/api/finance/v1/invoices')
            ->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.0.id', $invoice->id)
            ->assertJsonPath('data.0.document_number', $invoice->invoice_number)
            ->assertJsonPath('data.0.business_status', 'issued')
            ->assertJsonPath('data.0.compliance_status', 'generated');

        $this->assertArrayNotHasKey('payload', $list->json('data.0') ?? []);

        $detail = $this->withHeaders($this->workspaceHeader($workspace))
            ->getJson('/api/finance/v1/invoices/'.$invoice->id)
            ->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.document_number', $invoice->invoice_number)
            ->assertJsonPath('data.seller.name', 'Issued Co')
            ->assertJsonPath('data.buyer.name', 'Api Buyer')
            ->assertJsonPath('data.lines.0.product_name', 'خدمة فوترة')
            ->assertJsonPath('data.tax.amount', '15.00')
            ->assertJsonPath('data.totals.total', '115.00')
            ->assertJsonPath('data.compliance.business_status', 'issued')
            ->assertJsonPath('data.compliance.compliance_status', 'generated')
            ->assertJsonPath('data.compliance.document_kind', 'tax_invoice')
            ->assertJsonPath('data.compliance.invoice_type', '388')
            ->assertJsonPath('data.compliance.transaction_code', '0100000')
            ->assertJsonPath('data.compliance.xml_available', true)
            ->assertJsonPath('data.compliance.qr_available', true)
            ->assertJsonPath('data.compliance.production_stamping_available', false);

        $this->assertArrayNotHasKey('icv', $detail->json('data') ?? []);
        $this->assertArrayNotHasKey('pih', $detail->json('data') ?? []);
        $encoded = json_encode($detail->json());
        $this->assertIsString($encoded);
        $this->assertStringNotContainsString('BEGIN PRIVATE KEY', $encoded);
        $this->assertStringNotContainsString('test.invoice_hash', $encoded);
        $this->assertStringNotContainsString('production.zatca.unresolved', $encoded);
        $this->assertStringNotContainsString('TestCryptographic', $encoded);
        $this->assertStringNotContainsString('private_key', $encoded);

        $xml = $this->withHeaders($this->workspaceHeader($workspace))
            ->getJson('/api/finance/v1/invoices/'.$invoice->id.'/xml')
            ->assertOk()
            ->assertJsonPath('data.document_number', $invoice->invoice_number);

        $this->assertStringContainsString('<cbc:ID>'.$invoice->invoice_number.'</cbc:ID>', (string) $xml->json('data.xml'));

        $qr = $this->withHeaders($this->workspaceHeader($workspace))
            ->getJson('/api/finance/v1/invoices/'.$invoice->id.'/qr')
            ->assertOk()
            ->assertJsonPath('data.profile', 'phase8_unsigned');

        $this->assertNotEmpty($qr->json('data.qr_base64'));
        $this->assertNotEmpty($qr->json('data.tags.seller_name'));
        $this->assertNotEmpty($qr->json('data.tags.seller_vat'));
        $this->assertNotEmpty($qr->json('data.tags.invoice_hash'));
        $this->assertArrayNotHasKey('ecdsa_signature', $qr->json('data.tags') ?? []);
        $this->assertArrayNotHasKey(7, $qr->json('data.tags') ?? []);
    }

    public function test_issue_endpoint_is_idempotent_and_cancelled_invoice_cannot_be_issued(): void
    {
        [$owner, $workspace] = $this->createWorkspaceOwner();
        $customer = $this->makeBuyer($workspace, 'Issue Buyer');
        $this->completeSeller($workspace);
        $draft = $this->issueFinance($workspace, $customer, (int) $owner->id, [
            'invoice_status' => 'draft',
        ]);

        Sanctum::actingAs($owner);
        $headers = $this->workspaceHeader($workspace);

        $first = $this->withHeaders($headers)
            ->postJson('/api/finance/v1/invoices/'.$draft->id.'/issue')
            ->assertOk()
            ->assertJsonPath('data.compliance.business_status', 'issued')
            ->assertJsonPath('data.compliance.compliance_status', 'generated');

        $second = $this->withHeaders($headers)
            ->postJson('/api/finance/v1/invoices/'.$draft->id.'/issue')
            ->assertOk()
            ->assertJsonPath('data.document_uuid', $first->json('data.document_uuid'));

        $issued = $draft->fresh();
        app(InvoiceService::class)->cancel($issued, (int) $owner->id);

        $this->withHeaders($headers)
            ->postJson('/api/finance/v1/invoices/'.$draft->id.'/issue')
            ->assertStatus(422)
            ->assertJsonPath('success', false)
            ->assertJsonPath('code', 'cannot_issue');
    }

    public function test_unauthorized_forbidden_not_found_and_validation_errors(): void
    {
        [$owner, $workspace] = $this->createWorkspaceOwner();
        $customer = $this->makeBuyer($workspace, 'Auth Buyer');
        $this->completeSeller($workspace);
        $invoice = $this->issueFinance($workspace, $customer, (int) $owner->id);

        $this->getJson('/api/finance/v1/invoices')
            ->assertStatus(401)
            ->assertJsonPath('code', 'unauthorized');

        $agent = User::factory()->create();
        $workspace->users()->attach($agent->id, [
            'membership_role' => 'agent',
            'status' => 'active',
            'joined_at' => now(),
        ]);
        Sanctum::actingAs($agent);
        $this->withHeaders($this->workspaceHeader($workspace))
            ->getJson('/api/finance/v1/invoices/'.$invoice->id)
            ->assertStatus(403)
            ->assertJsonPath('code', 'forbidden');

        Sanctum::actingAs($owner);
        $this->withHeaders($this->workspaceHeader($workspace))
            ->getJson('/api/finance/v1/invoices/999999')
            ->assertStatus(404)
            ->assertJsonPath('code', 'not_found');

        $this->withHeaders($this->workspaceHeader($workspace))
            ->getJson('/api/finance/v1/invoices?per_page=0')
            ->assertStatus(422)
            ->assertJsonPath('code', 'validation_failed')
            ->assertJsonStructure(['errors']);
    }

    public function test_cross_workspace_invoice_access_is_forbidden(): void
    {
        [$ownerA, $workspaceA] = $this->createWorkspaceOwner('Workspace A');
        $customerA = $this->makeBuyer($workspaceA, 'A Buyer');
        $this->completeSeller($workspaceA);
        $invoiceA = $this->issueFinance($workspaceA, $customerA, (int) $ownerA->id);

        [$ownerB, $workspaceB] = $this->createWorkspaceOwner('Workspace B');
        $this->completeSeller($workspaceB);

        Sanctum::actingAs($ownerB);
        $this->withHeaders($this->workspaceHeader($workspaceB))
            ->getJson('/api/finance/v1/invoices/'.$invoiceA->id)
            ->assertStatus(404)
            ->assertJsonPath('code', 'not_found');

        Sanctum::actingAs($ownerA);
        $this->withHeaders($this->workspaceHeader($workspaceB))
            ->getJson('/api/finance/v1/invoices/'.$invoiceA->id)
            ->assertStatus(404);
    }

    public function test_production_crypto_request_fails_without_test_fallback(): void
    {
        [$owner, $workspace] = $this->createWorkspaceOwner();
        $customer = $this->makeBuyer($workspace, 'Crypto Buyer');
        $this->completeSeller($workspace);
        $invoice = $this->issueFinance($workspace, $customer, (int) $owner->id);

        Sanctum::actingAs($owner);
        $response = $this->withHeaders($this->workspaceHeader($workspace))
            ->postJson('/api/finance/v1/invoices/'.$invoice->id.'/cryptographic-stamp')
            ->assertStatus(409)
            ->assertJsonPath('success', false)
            ->assertJsonPath('code', 'production_crypto_unavailable')
            ->assertJsonPath('message', 'Production ZATCA cryptographic profile is unresolved and unavailable.');

        $encoded = json_encode($response->json());
        $this->assertIsString($encoded);
        $this->assertStringNotContainsString('test.invoice_hash', $encoded);
        $this->assertStringNotContainsString('TestCryptographicStampSigner', $encoded);
        $this->assertStringNotContainsString('production.zatca.unresolved', $encoded);
        $this->assertStringNotContainsString('BEGIN PRIVATE KEY', $encoded);
    }

    public function test_credit_note_and_pos_api_boundaries(): void
    {
        [$owner, $workspace] = $this->createWorkspaceOwner();
        $customer = $this->makeBuyer($workspace, 'Boundary Buyer');
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
        $posInvoice = app(PosOrderService::class)->createInvoiceFromOrder($order, (int) $owner->id);

        Sanctum::actingAs($owner);
        $headers = $this->workspaceHeader($workspace);

        $this->withHeaders($headers)
            ->getJson('/api/finance/v1/notes/'.$credit->id)
            ->assertOk()
            ->assertJsonPath('data.document_type', 'credit')
            ->assertJsonPath('data.references.original_invoice_number', $invoice->invoice_number)
            ->assertJsonPath('data.references.reason', 'خصم تجاري')
            ->assertJsonPath('data.compliance.document_kind', 'credit_note');

        $this->withHeaders($headers)
            ->getJson('/api/finance/v1/pos-invoices/'.$posInvoice->id)
            ->assertOk()
            ->assertJsonPath('data.source_type', 'pos_cashier_invoice')
            ->assertJsonPath('data.tax.amount', '15.00')
            ->assertJsonPath('data.compliance.document_kind', 'pos_cashier_invoice')
            ->assertJsonPath('data.compliance.xml_available', false);

        $this->withHeaders($headers)
            ->getJson('/api/finance/v1/pos-invoices/'.$posInvoice->id.'/xml')
            ->assertStatus(409)
            ->assertJsonPath('code', 'compliance_unavailable');
    }

    /**
     * @return array{0: User, 1: Workspace}
     */
    private function createWorkspaceOwner(string $name = 'Phase10 API Workspace'): array
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
