<?php

namespace Tests\Feature\Feature\Finance;

use App\Models\Customer;
use App\Models\Finance\FinanceInvoice;
use App\Models\Finance\FinanceInvoiceItem;
use App\Models\Plan;
use App\Models\Product;
use App\Models\Subscription;
use App\Models\User;
use App\Models\Workspace;
use App\Models\WorkspaceFeatureFlag;
use App\Services\Finance\CustomerBalanceService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Tests\TestCase;

class FinancePhaseAFoundationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Notification::fake();
    }

    public function test_company_customer_can_store_vat_cr_and_address(): void
    {
        [$user, $workspace] = $this->createWorkspaceOwner('company');

        $this->actingAs($user)
            ->withSession(['current_workspace_id' => $workspace->id])
            ->post(route('workspace.customers.store'), [
                'name' => 'شركة العزل',
                'party_type' => 'company',
                'phone' => '0551112233',
                'email' => 'billing@isolation.example',
                'vat_number' => '310000000000003',
                'commercial_registration' => '1010000000',
                'address' => 'الرياض، حي العليا',
                'building_number' => '1234',
                'street' => 'طريق الملك فهد',
                'district' => 'العليا',
                'city' => 'الرياض',
                'postal_code' => '12345',
                'country_code' => 'sa',
                'additional_number' => '5678',
                'payment_terms' => 'صافي 30 يوم',
            ])
            ->assertRedirect(route('workspace.customers.index'));

        $customer = Customer::withoutGlobalScopes()
            ->where('workspace_id', $workspace->id)
            ->where('name', 'شركة العزل')
            ->firstOrFail();

        $this->assertSame(Customer::PARTY_TYPE_COMPANY, $customer->partyType());
        $this->assertTrue($customer->isCompany());
        $this->assertSame('310000000000003', $customer->vat_number);
        $this->assertSame('1010000000', $customer->commercial_registration);
        $this->assertSame('الرياض، حي العليا', $customer->address);
        $this->assertSame('1234', $customer->building_number);
        $this->assertSame('طريق الملك فهد', $customer->street);
        $this->assertSame('العليا', $customer->district);
        $this->assertSame('الرياض', $customer->city);
        $this->assertSame('12345', $customer->postal_code);
        $this->assertSame('SA', $customer->country_code);
        $this->assertSame('5678', $customer->additional_number);
    }

    public function test_individual_customer_works_without_company_identifiers(): void
    {
        [$user, $workspace] = $this->createWorkspaceOwner('company');

        $this->actingAs($user)
            ->withSession(['current_workspace_id' => $workspace->id])
            ->post(route('workspace.customers.store'), [
                'name' => 'أحمد الفرد',
                'party_type' => 'individual',
                'phone' => '0559998877',
            ])
            ->assertRedirect(route('workspace.customers.index'));

        $customer = Customer::withoutGlobalScopes()
            ->where('workspace_id', $workspace->id)
            ->where('name', 'أحمد الفرد')
            ->firstOrFail();

        $this->assertSame(Customer::PARTY_TYPE_INDIVIDUAL, $customer->partyType());
        $this->assertFalse($customer->isCompany());
        $this->assertNull($customer->vat_number);
        $this->assertNull($customer->commercial_registration);
    }

    public function test_workspace_a_cannot_access_customer_from_workspace_b(): void
    {
        [$userA, $workspaceA] = $this->createWorkspaceOwner('company');
        [, $workspaceB] = $this->createWorkspaceOwner('company');
        $foreign = $this->makeCustomer($workspaceB, 'Foreign Party', [
            'vat_number' => '399999999999993',
        ]);

        $this->actingAs($userA)
            ->withSession(['current_workspace_id' => $workspaceA->id])
            ->get(route('workspace.customers.edit', $foreign))
            ->assertNotFound();

        $this->actingAs($userA)
            ->withSession(['current_workspace_id' => $workspaceA->id])
            ->put(route('workspace.customers.update', $foreign), [
                'name' => 'Hacked Name',
                'phone' => '0500000000',
                'vat_number' => '000',
            ])
            ->assertNotFound();

        $this->actingAs($userA)
            ->withSession(['current_workspace_id' => $workspaceA->id])
            ->get(route('workspace.finance.customers.index'))
            ->assertOk()
            ->assertDontSee('Foreign Party')
            ->assertDontSee('399999999999993');

        $foreign->refresh();
        $this->assertSame('Foreign Party', $foreign->name);
        $this->assertSame('399999999999993', $foreign->vat_number);
    }

    public function test_customer_balance_uses_issued_invoice_movements_not_stored_column(): void
    {
        [$user, $workspace] = $this->createWorkspaceOwner('company');
        $customer = $this->makeCustomer($workspace, 'AR Customer', ['balance' => 9999]);

        $this->actingAs($user)
            ->withSession(['current_workspace_id' => $workspace->id])
            ->post(route('workspace.finance.invoices.store'), $this->invoicePayload($customer->id, [
                'invoice_status' => 'draft',
                'unit_price' => 100,
            ]))
            ->assertRedirect();

        $balances = app(CustomerBalanceService::class);
        $this->assertSame(0.0, $balances->outstanding((int) $workspace->id, (int) $customer->id));
        $this->assertSame(9999.0, $customer->fresh()->storedBalance());

        $this->actingAs($user)
            ->withSession(['current_workspace_id' => $workspace->id])
            ->post(route('workspace.finance.invoices.store'), $this->invoicePayload($customer->id, [
                'unit_price' => 100,
            ]))
            ->assertRedirect();

        $invoice = FinanceInvoice::withoutGlobalScopes()
            ->where('workspace_id', $workspace->id)
            ->where('invoice_status', 'issued')
            ->firstOrFail();
        $this->assertSame(115.0, $balances->outstanding((int) $workspace->id, (int) $customer->id));

        $this->actingAs($user)
            ->withSession(['current_workspace_id' => $workspace->id])
            ->post(route('workspace.finance.invoices.payments.store', $invoice), [
                'payment_date' => now()->toDateString(),
                'amount' => 15,
                'method' => 'cash',
            ])
            ->assertRedirect();

        $this->assertSame(100.0, $balances->outstanding((int) $workspace->id, (int) $customer->id));
        $this->assertSame(9999.0, $customer->fresh()->storedBalance());

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

        $this->assertSame(88.5, $balances->outstanding((int) $workspace->id, (int) $customer->id));

        $this->actingAs($user)
            ->withSession(['current_workspace_id' => $workspace->id])
            ->get(route('workspace.finance.customers.index'))
            ->assertOk()
            ->assertSee('88.50')
            ->assertDontSee('9,999.00');
    }

    public function test_free_text_invoice_line_works_without_product_id(): void
    {
        [$user, $workspace] = $this->createWorkspaceOwner('company');
        $customer = $this->makeCustomer($workspace, 'Free Text Buyer');

        $this->actingAs($user)
            ->withSession(['current_workspace_id' => $workspace->id])
            ->post(route('workspace.finance.invoices.store'), $this->invoicePayload($customer->id, [
                'items' => [[
                    'product_id' => null,
                    'product_name' => '',
                    'description' => 'عزل أسطح',
                    'unit' => 'متر',
                    'quantity' => 500,
                    'unit_price' => 45,
                    'discount' => 0,
                    'tax_rate' => 15,
                    'tax_type' => 'standard',
                    'total' => 1,
                    'tax_amount' => 1,
                ]],
            ]))
            ->assertRedirect();

        $invoice = FinanceInvoice::withoutGlobalScopes()->firstOrFail();
        $line = $invoice->items()->firstOrFail();

        $this->assertNull($line->product_id);
        $this->assertSame('عزل أسطح', $line->product_name);
        $this->assertSame('عزل أسطح', $line->description);
        $this->assertSame('متر', $line->unit);
        $this->assertNull($line->unit_code);
        $this->assertSame('500.000', (string) $line->quantity);
        $this->assertSame('45.00', (string) $line->unit_price);
        $this->assertSame('22500.00', (string) $invoice->subtotal);
        $this->assertSame('3375.00', (string) $invoice->tax_amount);
        $this->assertSame('25875.00', (string) $invoice->total);
    }

    public function test_invoice_line_with_product_id_still_works(): void
    {
        [$user, $workspace] = $this->createWorkspaceOwner('company');
        $customer = $this->makeCustomer($workspace, 'Catalog Buyer');
        $product = Product::withoutGlobalScopes()->create([
            'workspace_id' => $workspace->id,
            'name' => 'خدمة كتالوج',
            'slug' => 'catalog-service',
            'sku' => 'CAT-1',
            'price' => 80,
            'currency' => 'SAR',
            'status' => 'active',
            'inventory_tracking' => false,
            'stock' => 0,
        ]);

        $this->actingAs($user)
            ->withSession(['current_workspace_id' => $workspace->id])
            ->post(route('workspace.finance.invoices.store'), $this->invoicePayload($customer->id, [
                'items' => [[
                    'product_id' => $product->id,
                    'product_name' => 'خدمة كتالوج',
                    'description' => 'من الكتالوج',
                    'unit' => 'ساعة',
                    'quantity' => 2,
                    'unit_price' => 80,
                    'discount' => 0,
                    'tax_rate' => 15,
                    'tax_type' => 'standard',
                ]],
            ]))
            ->assertRedirect();

        $line = FinanceInvoiceItem::withoutGlobalScopes()->firstOrFail();
        $this->assertSame((int) $product->id, (int) $line->product_id);
        $this->assertSame('خدمة كتالوج', $line->product_name);
        $this->assertSame('ساعة', $line->unit);
        $this->assertSame('160.00', (string) $line->invoice()->first()?->subtotal);
    }

    public function test_quantity_unit_price_discount_and_tax_remain_server_side(): void
    {
        [$user, $workspace] = $this->createWorkspaceOwner('company');
        $customer = $this->makeCustomer($workspace, 'Calc Buyer');

        $this->actingAs($user)
            ->withSession(['current_workspace_id' => $workspace->id])
            ->post(route('workspace.finance.invoices.store'), $this->invoicePayload($customer->id, [
                'items' => [[
                    'product_name' => 'عزل أسطح',
                    'description' => 'عزل أسطح',
                    'unit' => 'متر',
                    'quantity' => 500,
                    'unit_price' => 45,
                    'discount' => 500,
                    'tax_rate' => 15,
                    'tax_type' => 'standard',
                    'total' => 999999,
                    'tax_amount' => 1,
                ]],
            ]))
            ->assertRedirect();

        $invoice = FinanceInvoice::withoutGlobalScopes()->firstOrFail();
        $line = $invoice->items()->firstOrFail();

        $this->assertSame('متر', $line->unit);
        $this->assertSame('500.00', (string) $line->discount);
        $this->assertSame('22500.00', (string) $invoice->subtotal);
        $this->assertSame('500.00', (string) $invoice->discount);
        $this->assertSame('22000.00', (string) $invoice->taxable_amount);
        $this->assertSame('3300.00', (string) $invoice->tax_amount);
        $this->assertSame('25300.00', (string) $invoice->total);
        $this->assertSame('standard', $invoice->tax_profile_type);
    }

    public function test_free_text_invoice_pdf_shows_description_and_unit(): void
    {
        [$user, $workspace] = $this->createWorkspaceOwner('company');
        $customer = $this->makeCustomer($workspace, 'PDF Free Text');

        $this->actingAs($user)
            ->withSession(['current_workspace_id' => $workspace->id])
            ->post(route('workspace.finance.invoices.store'), $this->invoicePayload($customer->id, [
                'items' => [[
                    'product_id' => null,
                    'description' => 'عزل أسطح',
                    'unit' => 'متر',
                    'quantity' => 500,
                    'unit_price' => 45,
                    'discount' => 0,
                    'tax_rate' => 15,
                    'tax_type' => 'standard',
                ]],
            ]))
            ->assertRedirect();

        $invoice = FinanceInvoice::withoutGlobalScopes()->firstOrFail();
        $html = view('workspace.finance.invoices.pdf', [
            'invoice' => $invoice->load(['items', 'customer', 'supplier']),
            'setting' => null,
            'companySnapshot' => $invoice->company_snapshot,
            'recipientSnapshot' => $invoice->recipient_snapshot,
            'pdfSnapshot' => $invoice->pdf_snapshot,
            'snapshotsAuthoritative' => true,
            'logoDataUri' => null,
        ])->render();

        $this->assertStringContainsString('عزل أسطح', $html);
        $this->assertStringContainsString('متر', $html);
        $this->assertStringContainsString('500.000', $html);
        $this->assertStringContainsString('45.00', $html);
        $this->assertStringContainsString('22,500.00', $html);
        $this->assertStringContainsString('الوحدة', $html);

        $this->actingAs($user)
            ->withSession(['current_workspace_id' => $workspace->id])
            ->get(route('workspace.finance.invoices.create'))
            ->assertOk()
            ->assertSee('بند حر')
            ->assertSee('اسم البند')
            ->assertSee('الوحدة');
    }

    /**
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    private function invoicePayload(int $customerId, array $overrides = []): array
    {
        $items = $overrides['items'] ?? [[
            'product_name' => 'خدمة فوترة',
            'description' => 'بند اختبار',
            'quantity' => 1,
            'unit_price' => $overrides['unit_price'] ?? 100,
            'discount' => $overrides['discount'] ?? 0,
            'tax_rate' => $overrides['tax_rate'] ?? 15,
            'tax_type' => $overrides['tax_type'] ?? 'standard',
        ]];

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
            'items_json' => json_encode($items),
        ];

        unset($overrides['items']);

        return array_merge($payload, $overrides);
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
