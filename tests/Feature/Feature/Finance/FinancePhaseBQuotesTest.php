<?php

namespace Tests\Feature\Feature\Finance;

use App\Models\Customer;
use App\Models\Finance\FinanceInvoice;
use App\Models\Finance\FinanceInvoicePayment;
use App\Models\Finance\FinanceJournalEntry;
use App\Models\Finance\FinanceQuote;
use App\Models\Finance\FinanceSetting;
use App\Models\Finance\IssuedDocumentSnapshot;
use App\Models\Plan;
use App\Models\Product;
use App\Models\Subscription;
use App\Models\User;
use App\Models\Workspace;
use App\Models\WorkspaceFeatureFlag;
use App\Services\Finance\QuoteService;
use Database\Seeders\FoundationSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Route;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

class FinancePhaseBQuotesTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Notification::fake();
        $this->seed(FoundationSeeder::class);
    }

    public function test_create_and_update_draft_quote(): void
    {
        [$user, $workspace] = $this->createWorkspaceOwner('company');
        $customer = $this->makeCustomer($workspace, 'Quote Customer');

        $this->actingAs($user)
            ->withSession(['current_workspace_id' => $workspace->id])
            ->post(route('workspace.finance.quotes.store'), $this->quotePayload($customer->id, [
                'status' => 'draft',
                'unit_price' => 100,
            ]))
            ->assertRedirect();

        $quote = FinanceQuote::withoutGlobalScopes()->firstOrFail();
        $this->assertSame('draft', $quote->status);
        $this->assertNotSame('', $quote->quote_number);
        $this->assertNull($quote->issued_at);

        $this->actingAs($user)
            ->withSession(['current_workspace_id' => $workspace->id])
            ->put(route('workspace.finance.quotes.update', $quote), $this->quotePayload($customer->id, [
                'status' => 'draft',
                'unit_price' => 80,
                'terms' => 'عرض ساري 15 يوماً',
            ]))
            ->assertRedirect();

        $quote->refresh();
        $this->assertSame('draft', $quote->status);
        $this->assertSame('80.00', (string) $quote->subtotal);
        $this->assertSame('عرض ساري 15 يوماً', $quote->terms);
    }

    public function test_issue_quote_locks_financials_and_does_not_touch_accounting_or_zatca(): void
    {
        [$user, $workspace] = $this->createWorkspaceOwner('company');
        $customer = $this->makeCustomer($workspace, 'Issued Quote Customer', [
            'vat_number' => '310000000000003',
            'commercial_registration' => '1010000000',
        ]);
        FinanceSetting::withoutGlobalScopes()->updateOrCreate(
            ['workspace_id' => $workspace->id],
            ['workspace_id' => $workspace->id, 'company_name' => 'شركة الاختبار', 'vat_number' => '300000000000003', 'currency' => 'SAR']
        );

        $this->actingAs($user)
            ->withSession(['current_workspace_id' => $workspace->id])
            ->post(route('workspace.finance.quotes.store'), $this->quotePayload($customer->id, [
                'status' => 'draft',
            ]))
            ->assertRedirect();

        $quote = FinanceQuote::withoutGlobalScopes()->firstOrFail();
        $this->actingAs($user)
            ->withSession(['current_workspace_id' => $workspace->id])
            ->post(route('workspace.finance.quotes.issue', $quote))
            ->assertRedirect();

        $quote->refresh();
        $this->assertSame('issued', $quote->status);
        $this->assertSame('pending', $quote->outcome);
        $this->assertNotNull($quote->issued_at);
        $this->assertSame('شركة الاختبار', data_get($quote->company_snapshot, 'company_name'));
        $this->assertSame('310000000000003', data_get($quote->recipient_snapshot, 'vat_number'));
        $this->assertSame(0, FinanceJournalEntry::withoutGlobalScopes()->count());
        $this->assertSame(0, FinanceInvoice::withoutGlobalScopes()->count());
        $this->assertSame(0, FinanceInvoicePayment::withoutGlobalScopes()->count());
        $this->assertSame(0, IssuedDocumentSnapshot::withoutGlobalScopes()->count());

        $this->actingAs($user)
            ->withSession(['current_workspace_id' => $workspace->id])
            ->put(route('workspace.finance.quotes.update', $quote), $this->quotePayload($customer->id, [
                'unit_price' => 999,
            ]))
            ->assertRedirect();

        $quote->refresh();
        $this->assertSame('100.00', (string) $quote->subtotal);
        $this->assertSame('issued', $quote->status);
    }

    public function test_quote_numbering_is_workspace_scoped(): void
    {
        [$userA, $workspaceA] = $this->createWorkspaceOwner('company');
        [$userB, $workspaceB] = $this->createWorkspaceOwner('company');
        $customerA = $this->makeCustomer($workspaceA, 'A Buyer');
        $customerB = $this->makeCustomer($workspaceB, 'B Buyer');

        $this->actingAs($userA)->withSession(['current_workspace_id' => $workspaceA->id])
            ->post(route('workspace.finance.quotes.store'), $this->quotePayload($customerA->id))
            ->assertRedirect();
        $this->actingAs($userA)->withSession(['current_workspace_id' => $workspaceA->id])
            ->post(route('workspace.finance.quotes.store'), $this->quotePayload($customerA->id))
            ->assertRedirect();
        $this->actingAs($userB)->withSession(['current_workspace_id' => $workspaceB->id])
            ->post(route('workspace.finance.quotes.store'), $this->quotePayload($customerB->id))
            ->assertRedirect();

        $numbersA = FinanceQuote::withoutGlobalScopes()
            ->where('workspace_id', $workspaceA->id)
            ->orderBy('id')
            ->pluck('quote_number')
            ->all();
        $numbersB = FinanceQuote::withoutGlobalScopes()
            ->where('workspace_id', $workspaceB->id)
            ->orderBy('id')
            ->pluck('quote_number')
            ->all();
        $this->assertCount(2, $numbersA);
        $this->assertCount(1, $numbersB);
        $this->assertNotSame($numbersA[0], $numbersA[1]);
        $this->assertSame($numbersA[0], $numbersB[0]);
        $this->assertMatchesRegularExpression('/^Q-\d{4}-\d{4}$/', $numbersA[0]);
        $this->assertDoesNotMatchRegularExpression('/^INV-/', $numbersA[0]);

        $settingsA = FinanceSetting::withoutGlobalScopes()->where('workspace_id', $workspaceA->id)->firstOrFail();
        $this->assertSame(1, (int) $settingsA->next_invoice_sequence);
        $this->assertSame(3, (int) $settingsA->next_quote_sequence);
    }

    public function test_workspace_a_cannot_access_workspace_b_quote(): void
    {
        [$userA, $workspaceA] = $this->createWorkspaceOwner('company');
        [$userB, $workspaceB] = $this->createWorkspaceOwner('company');
        $customerB = $this->makeCustomer($workspaceB, 'Secret Buyer');
        $this->actingAs($userB)->withSession(['current_workspace_id' => $workspaceB->id])
            ->post(route('workspace.finance.quotes.store'), $this->quotePayload($customerB->id))
            ->assertRedirect();
        $quoteB = FinanceQuote::withoutGlobalScopes()->where('workspace_id', $workspaceB->id)->firstOrFail();

        $this->actingAs($userA)->withSession(['current_workspace_id' => $workspaceA->id])
            ->get(route('workspace.finance.quotes.show', $quoteB))
            ->assertNotFound();
        $this->actingAs($userA)->withSession(['current_workspace_id' => $workspaceA->id])
            ->post(route('workspace.finance.quotes.issue', $quoteB))
            ->assertNotFound();
    }

    public function test_free_text_and_catalog_lines_with_server_side_totals(): void
    {
        [$user, $workspace] = $this->createWorkspaceOwner('company');
        $customer = $this->makeCustomer($workspace, 'Isolation Buyer');
        $product = Product::withoutGlobalScopes()->create([
            'workspace_id' => $workspace->id,
            'name' => 'خدمة كتالوج',
            'slug' => 'catalog-quote',
            'sku' => 'QCAT-1',
            'price' => 10,
            'currency' => 'SAR',
            'status' => 'active',
            'inventory_tracking' => false,
            'stock' => 0,
        ]);

        $this->actingAs($user)
            ->withSession(['current_workspace_id' => $workspace->id])
            ->post(route('workspace.finance.quotes.store'), $this->quotePayload($customer->id, [
                'items' => [
                    [
                        'product_id' => null,
                        'description' => 'عزل أسطح',
                        'unit' => 'متر',
                        'quantity' => 500,
                        'unit_price' => 45,
                        'discount' => 500,
                        'tax_rate' => 15,
                        'tax_type' => 'standard',
                        'total' => 999999,
                        'tax_amount' => 1,
                    ],
                    [
                        'product_id' => null,
                        'description' => 'تنظيف أسطح',
                        'unit' => 'متر',
                        'quantity' => 500,
                        'unit_price' => 23,
                        'discount' => 0,
                        'tax_rate' => 15,
                        'tax_type' => 'standard',
                    ],
                    [
                        'product_id' => $product->id,
                        'product_name' => 'خدمة كتالوج',
                        'unit' => 'ساعة',
                        'quantity' => 2,
                        'unit_price' => 10,
                        'discount' => 0,
                        'tax_rate' => 15,
                        'tax_type' => 'standard',
                    ],
                    [
                        'description' => 'بند صفري',
                        'unit' => 'قطعة',
                        'quantity' => 1,
                        'unit_price' => 50,
                        'discount' => 0,
                        'tax_rate' => 0,
                        'tax_type' => 'zero_rated',
                    ],
                    [
                        'description' => 'بند معفى',
                        'unit' => 'قطعة',
                        'quantity' => 1,
                        'unit_price' => 40,
                        'discount' => 0,
                        'tax_rate' => 0,
                        'tax_type' => 'exempt',
                    ],
                    [
                        'description' => 'بند خارج النطاق',
                        'unit' => 'قطعة',
                        'quantity' => 1,
                        'unit_price' => 30,
                        'discount' => 0,
                        'tax_rate' => 0,
                        'tax_type' => 'out_of_scope',
                    ],
                ],
            ]))
            ->assertRedirect();

        $quote = FinanceQuote::withoutGlobalScopes()->firstOrFail();
        $this->assertCount(6, $quote->items);
        $roof = $quote->items->firstWhere('description', 'عزل أسطح');
        $this->assertNull($roof->product_id);
        $this->assertSame('متر', $roof->unit);
        $this->assertSame('500.000', (string) $roof->quantity);
        $this->assertSame('45.00', (string) $roof->unit_price);
        $this->assertSame('500.00', (string) $roof->discount);
        $this->assertSame('22000.00', (string) $roof->taxable_amount);
        $this->assertSame('3300.00', (string) $roof->tax_amount);
        $this->assertSame('25300.00', (string) $roof->total);
        $catalog = $quote->items->firstWhere('product_id', $product->id);
        $this->assertSame('ساعة', $catalog->unit);
        $zero = $quote->items->firstWhere('description', 'بند صفري');
        $this->assertSame('zero_rated', $zero->tax_profile_type);
        $this->assertSame('0.00', (string) $zero->tax_amount);
        $exempt = $quote->items->firstWhere('description', 'بند معفى');
        $this->assertSame('exempt', $exempt->tax_profile_type);
        $this->assertSame('0.00', (string) $exempt->tax_amount);
        $outOfScope = $quote->items->firstWhere('description', 'بند خارج النطاق');
        $this->assertSame('out_of_scope', $outOfScope->tax_profile_type);
        $this->assertSame('0.00', (string) $outOfScope->tax_amount);

        $this->assertSame('34140.00', (string) $quote->subtotal);
        $this->assertSame('500.00', (string) $quote->discount);
        $this->assertSame('33640.00', (string) $quote->taxable_amount);
        $this->assertSame('5028.00', (string) $quote->tax_amount);
        $this->assertSame('38668.00', (string) $quote->total);
    }

    public function test_quote_pdf_shows_free_text_unit_customer_and_company_snapshot(): void
    {
        [$user, $workspace] = $this->createWorkspaceOwner('company');
        $customer = $this->makeCustomer($workspace, 'عميل العزل', [
            'vat_number' => '311111111111113',
        ]);
        $this->actingAs($user)->withSession(['current_workspace_id' => $workspace->id])
            ->get(route('workspace.finance.settings.index'));
        FinanceSetting::withoutGlobalScopes()->where('workspace_id', $workspace->id)
            ->update(['company_name' => 'شركة العزل الوطنية', 'vat_number' => '300111111111113']);

        $this->actingAs($user)
            ->withSession(['current_workspace_id' => $workspace->id])
            ->post(route('workspace.finance.quotes.store'), $this->quotePayload($customer->id, [
                'status' => 'issued',
                'items' => [[
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

        $quote = FinanceQuote::withoutGlobalScopes()->firstOrFail();
        $html = view('workspace.finance.quotes.pdf', [
            'quote' => $quote->load(['items', 'customer']),
            'setting' => null,
            'companySnapshot' => $quote->company_snapshot,
            'recipientSnapshot' => $quote->recipient_snapshot,
            'pdfSnapshot' => $quote->pdf_snapshot,
            'snapshotsAuthoritative' => true,
            'logoDataUri' => null,
        ])->render();

        $this->assertStringContainsString('عزل أسطح', $html);
        $this->assertStringContainsString('متر', $html);
        $this->assertStringContainsString('500.000', $html);
        $this->assertStringContainsString('45.00', $html);
        $this->assertStringContainsString('22,500.00', $html);
        $this->assertStringContainsString('عميل العزل', $html);
        $this->assertStringContainsString('311111111111113', $html);
        $this->assertStringContainsString('شركة العزل الوطنية', $html);
        $this->assertStringContainsString($quote->quote_number, $html);
        $this->assertStringContainsString('عرض سعر', $html);
        $this->assertStringNotContainsString('حالة الدفع', $html);

        $this->actingAs($user)
            ->withSession(['current_workspace_id' => $workspace->id])
            ->get(route('workspace.finance.quotes.create'))
            ->assertOk()
            ->assertSee('بند حر')
            ->assertSee('عروض الأسعار');

        $pdfResponse = $this->actingAs($user)
            ->withSession(['current_workspace_id' => $workspace->id])
            ->get(route('workspace.finance.quotes.pdf', $quote));
        $pdfResponse->assertOk();
        $this->assertStringContainsString('application/pdf', (string) $pdfResponse->headers->get('content-type'));
    }

    public function test_issued_quote_can_be_cancelled_but_not_deleted(): void
    {
        [$user, $workspace] = $this->createWorkspaceOwner('company');
        $customer = $this->makeCustomer($workspace, 'Cancel Customer');
        $quote = app(QuoteService::class)->create($workspace, $this->servicePayload($customer->id), (int) $user->id);
        $quote = app(QuoteService::class)->issue($quote, (int) $user->id);

        $this->actingAs($user)
            ->withSession(['current_workspace_id' => $workspace->id])
            ->delete(route('workspace.finance.quotes.destroy', $quote))
            ->assertRedirect();

        $this->assertNotNull(FinanceQuote::withoutGlobalScopes()->find($quote->id));

        $this->actingAs($user)
            ->withSession(['current_workspace_id' => $workspace->id])
            ->post(route('workspace.finance.quotes.cancel', $quote))
            ->assertRedirect();

        $quote->refresh();
        $this->assertSame('cancelled', $quote->status);
        $this->assertNotNull($quote->cancelled_at);
        $this->assertSame(0, FinanceJournalEntry::withoutGlobalScopes()->count());
        $this->assertTrue(Route::has('workspace.finance.quotes.convert'));
        $this->assertTrue(Route::has('workspace.finance.quotes.accept'));
        $this->assertTrue(Route::has('workspace.finance.quotes.reject'));
    }

    public function test_quotes_edit_cannot_issue_without_quotes_issue_permission(): void
    {
        [$owner, $workspace] = $this->createWorkspaceOwner('company');
        $customer = $this->makeCustomer($workspace, 'Perm Customer');
        $quote = app(QuoteService::class)->create($workspace, $this->servicePayload($customer->id), (int) $owner->id);
        $editor = $this->attachStaff($workspace, ['quotes.view', 'quotes.edit', 'quotes.create']);

        $this->actingAs($editor)
            ->withSession(['current_workspace_id' => $workspace->id])
            ->post(route('workspace.finance.quotes.issue', $quote))
            ->assertForbidden();

        $this->assertSame('draft', $quote->fresh()->status);
    }

    public function test_quote_navigation_is_under_finance_sales(): void
    {
        [$user, $workspace] = $this->createWorkspaceOwner('company');
        $this->actingAs($user)->withSession(['current_workspace_id' => $workspace->id])
            ->get(route('workspace.finance.quotes.index'))
            ->assertOk()
            ->assertSee('عروض الأسعار')
            ->assertSee(route('workspace.finance.invoices.index'), false);
    }

    /**
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    private function quotePayload(int $customerId, array $overrides = []): array
    {
        $items = $overrides['items'] ?? [[
            'product_name' => 'خدمة عرض',
            'description' => 'بند اختبار',
            'quantity' => 1,
            'unit_price' => $overrides['unit_price'] ?? 100,
            'discount' => $overrides['discount'] ?? 0,
            'tax_rate' => $overrides['tax_rate'] ?? 15,
            'tax_type' => $overrides['tax_type'] ?? 'standard',
        ]];

        $payload = [
            'customer_id' => $customerId,
            'issue_date' => $overrides['issue_date'] ?? now()->toDateString(),
            'expiry_date' => $overrides['expiry_date'] ?? now()->addDays(30)->toDateString(),
            'currency' => 'SAR',
            'status' => $overrides['status'] ?? 'draft',
            'tax_profile_type' => $overrides['tax_profile_type'] ?? 'standard',
            'tax_rate' => $overrides['tax_rate'] ?? 15,
            'notes' => $overrides['notes'] ?? 'ملاحظات العرض',
            'terms' => $overrides['terms'] ?? 'الشروط',
            'items_json' => json_encode($items),
        ];

        unset($overrides['items'], $overrides['unit_price'], $overrides['discount'], $overrides['tax_type']);

        return array_merge($payload, $overrides);
    }

    /**
     * @return array<string, mixed>
     */
    private function servicePayload(int $customerId): array
    {
        $payload = $this->quotePayload($customerId, ['status' => 'draft']);
        $payload['items'] = json_decode($payload['items_json'], true);

        return $payload;
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
     * @param  array<int, string>  $permissions
     */
    private function attachStaff(Workspace $workspace, array $permissions): User
    {
        $user = User::factory()->create();
        $workspace->users()->attach($user->id, [
            'membership_role' => 'agent',
            'status' => 'active',
            'joined_at' => now(),
        ]);
        app(PermissionRegistrar::class)->setPermissionsTeamId($workspace->id);
        $user->givePermissionTo($permissions);

        return $user;
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
