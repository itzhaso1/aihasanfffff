<?php

namespace Tests\Feature\Feature\Finance;

use App\Enums\Finance\TaxProfileType;
use App\Models\Customer;
use App\Models\Plan;
use App\Models\Subscription;
use App\Models\User;
use App\Models\Workspace;
use App\Models\WorkspaceFeatureFlag;
use App\Services\Finance\FinanceBootstrapService;
use App\Support\Tenancy\WorkspaceContext;
use Database\Seeders\FoundationSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Laravel\Sanctum\Sanctum;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

class FinanceFlutterDataParityTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Notification::fake();
        $this->seed(FoundationSeeder::class);
    }

    public function test_rich_customer_invoice_statement_and_search_keep_web_fields(): void
    {
        [$owner, $workspace] = $this->createWorkspaceOwner();
        Sanctum::actingAs($owner);
        $headers = $this->workspaceHeader($workspace);

        $customer = $this->withHeaders($headers)
            ->postJson('/api/finance/v1/customers', [
                'name' => 'PARITY CUSTOMER 001',
                'phone' => '0501112233',
                'whatsapp' => '0501112233',
                'email' => 'parity.customer@example.com',
                'party_type' => 'company',
                'vat_number' => '300111111111113',
                'commercial_registration' => '1010123456',
                'address' => 'الرياض',
                'building_number' => '1234',
                'street' => 'طريق الملك',
                'district' => 'العليا',
                'city' => 'الرياض',
                'postal_code' => '12345',
                'country_code' => 'SA',
                'additional_number' => '5678',
                'payment_terms' => 'صافي 14',
                'notes' => 'عميل تدقيق التكافؤ',
            ])
            ->assertCreated()
            ->assertJsonPath('data.name', 'PARITY CUSTOMER 001')
            ->assertJsonPath('data.whatsapp', '0501112233')
            ->assertJsonPath('data.district', 'العليا')
            ->assertJsonPath('data.building_number', '1234')
            ->assertJsonPath('data.vat_number', '300111111111113');

        $customerId = (int) $customer->json('data.id');

        $created = $this->withHeaders($headers)
            ->postJson('/api/finance/v1/sales-invoices', [
                'type' => 'sales',
                'customer_id' => $customerId,
                'issue_date' => now()->toDateString(),
                'due_date' => now()->addDays(14)->toDateString(),
                'notes' => 'ملاحظة عربية',
                'payment_terms' => '14 يوم',
                'invoice_status' => 'draft',
                'tax_profile_type' => TaxProfileType::Standard->value,
                'tax_rate' => 15,
                'items' => [
                    [
                        'product_name' => 'استشارة',
                        'description' => 'ساعة استشارة',
                        'unit' => 'ساعة',
                        'quantity' => 1,
                        'unit_price' => 100,
                        'discount' => 10,
                        'tax_rate' => 15,
                        'tax_type' => TaxProfileType::Standard->value,
                    ],
                    [
                        'product_name' => 'تنفيذ',
                        'unit' => 'بند',
                        'quantity' => 1,
                        'unit_price' => 100,
                        'discount' => 0,
                        'tax_rate' => 15,
                        'tax_type' => TaxProfileType::Standard->value,
                    ],
                ],
            ])
            ->assertCreated();

        $invoiceId = (int) $created->json('data.id');
        $this->assertNotSame('0.00', $created->json('data.discount'));
        $this->assertNotEmpty($created->json('data.taxable_amount'));

        $this->withHeaders($headers)
            ->postJson('/api/finance/v1/sales-invoices/'.$invoiceId.'/issue')
            ->assertOk()
            ->assertJsonPath('data.document_status', 'issued');

        $this->withHeaders($headers)
            ->postJson('/api/finance/v1/sales-invoices/'.$invoiceId.'/payments', [
                'payment_date' => now()->toDateString(),
                'amount' => 50,
                'method' => 'cash',
                'reference' => 'PARITY-PAY',
                'notes' => 'دفعة جزئية',
            ])
            ->assertOk()
            ->assertJsonPath('data.invoice.payment_status', 'partial');

        $detail = $this->withHeaders($headers)
            ->getJson('/api/finance/v1/sales-invoices/'.$invoiceId)
            ->assertOk()
            ->assertJsonPath('data.notes', 'ملاحظة عربية')
            ->assertJsonPath('data.payment_terms', '14 يوم');

        $this->assertGreaterThan(0, (float) $detail->json('data.discount'));
        $this->assertGreaterThan(0, (float) $detail->json('data.taxable_amount'));
        $this->assertSame('50.00', $detail->json('data.amount_paid'));
        $this->assertCount(2, $detail->json('data.lines'));
        $this->assertSame('ساعة', $detail->json('data.lines.0.unit'));
        $this->assertNotEmpty($detail->json('data.lines.0.taxable_amount'));
        $this->assertNotEmpty($detail->json('data.payments.0.id'));
        $this->assertSame('دفعة جزئية', $detail->json('data.payments.0.notes'));

        $from = now()->startOfMonth()->toDateString();
        $to = now()->endOfMonth()->toDateString();
        $statement = $this->withHeaders($headers)
            ->getJson('/api/finance/v1/statements?customer_id='.$customerId.'&from='.$from.'&to='.$to)
            ->assertOk()
            ->assertJsonPath('data.customer.name', 'PARITY CUSTOMER 001');

        $this->assertNotEmpty($statement->json('data.lines'));
        $invoiceLine = collect($statement->json('data.lines'))->firstWhere('kind', 'invoice');
        $paymentLine = collect($statement->json('data.lines'))->firstWhere('kind', 'payment');
        $this->assertNotNull($invoiceLine);
        $this->assertGreaterThan(0, (float) $invoiceLine['debit']);
        $this->assertSame($invoiceId, $invoiceLine['invoice_id']);
        $this->assertNotEmpty($invoiceLine['description']);
        $this->assertNotNull($paymentLine);
        $this->assertGreaterThan(0, (float) $paymentLine['credit']);

        $this->withHeaders($headers)
            ->postJson('/api/finance/v1/contracts', [
                'title' => 'عقد صيانة',
                'customer_id' => $customerId,
                'value' => 1200,
                'terms' => 'شروط عربية',
                'notes' => 'ملاحظات العقد',
            ])
            ->assertCreated()
            ->assertJsonPath('data.terms', 'شروط عربية')
            ->assertJsonPath('data.notes', 'ملاحظات العقد');

        $this->withHeaders($headers)
            ->postJson('/api/finance/v1/expenses', [
                'expense_date' => now()->toDateString(),
                'description' => 'إيجار المكتب',
                'amount' => 1000,
                'tax_rate' => 15,
                'payment_method' => 'bank_transfer',
                'is_recurring' => true,
                'status' => 'draft',
            ])
            ->assertCreated()
            ->assertJsonPath('data.is_recurring', true)
            ->assertJsonPath('data.payment_method', 'bank_transfer');

        $dashboard = $this->withHeaders($headers)
            ->getJson('/api/finance/v1/dashboard')
            ->assertOk();
        foreach ([
            'purchases',
            'output_vat',
            'input_vat',
            'net_vat',
            'cash_balance',
            'bank_balance',
            'active_contracts_count',
            'net_profit',
        ] as $card) {
            $this->assertArrayHasKey($card, $dashboard->json('data.cards'));
        }
        $this->assertNotEmpty($dashboard->json('data.recent_expenses'));

        $this->withHeaders($headers)
            ->getJson('/api/finance/v1/search?q='.urlencode('إيجار'))
            ->assertOk();
        $this->assertNotEmpty(
            $this->withHeaders($headers)->getJson('/api/finance/v1/search?q='.urlencode('إيجار'))->json('data.expenses')
        );

        $this->withHeaders($headers)
            ->getJson('/api/finance/v1/reports/inventory-valuation')
            ->assertOk()
            ->assertJsonPath('data.report', 'inventory-valuation');
        $this->assertArrayHasKey('inventory_valuation', $this->withHeaders($headers)
            ->getJson('/api/finance/v1/reports/inventory-valuation')
            ->json('data'));
    }

    public function test_customers_pagination_returns_more_than_first_page(): void
    {
        [$owner, $workspace] = $this->createWorkspaceOwner();
        Sanctum::actingAs($owner);
        $headers = $this->workspaceHeader($workspace);

        for ($i = 1; $i <= 26; $i++) {
            Customer::withoutGlobalScopes()->create([
                'workspace_id' => $workspace->id,
                'name' => 'Page Customer '.$i,
                'phone' => '05'.str_pad((string) $i, 8, '0', STR_PAD_LEFT),
                'email' => 'page'.$i.'@example.com',
            ]);
        }

        $page1 = $this->withHeaders($headers)
            ->getJson('/api/finance/v1/customers?per_page=25&page=1')
            ->assertOk();
        $this->assertCount(25, $page1->json('data'));
        $this->assertGreaterThanOrEqual(26, (int) $page1->json('meta.total'));
        $this->assertGreaterThanOrEqual(2, (int) $page1->json('meta.last_page'));

        $page2 = $this->withHeaders($headers)
            ->getJson('/api/finance/v1/customers?per_page=25&page=2')
            ->assertOk();
        $this->assertNotEmpty($page2->json('data'));
        $this->assertNotSame($page1->json('data.0.id'), $page2->json('data.0.id'));
    }

    /**
     * @return array{0: User, 1: Workspace}
     */
    private function createWorkspaceOwner(string $name = 'Finance Parity Workspace'): array
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

        foreach (['finance', 'pos', 'products', 'orders', 'customers', 'payments', 'payment_gateway'] as $feature) {
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
        app(PermissionRegistrar::class)->setPermissionsTeamId($workspace->id);

        return [$user, $workspace->fresh()];
    }

    /**
     * @return array<string, string>
     */
    private function workspaceHeader(Workspace $workspace): array
    {
        return ['X-Workspace-Id' => (string) $workspace->id];
    }
}
