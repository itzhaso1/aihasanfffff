<?php

namespace Tests\Feature\Feature\Finance;

use App\Models\AuditLog;
use App\Models\Customer;
use App\Models\EmailMessage;
use App\Models\Finance\FinanceInvoice;
use App\Models\Finance\FinanceInvoicePayment;
use App\Models\Finance\FinanceJournalEntry;
use App\Models\Finance\FinanceQuote;
use App\Models\Finance\IssuedDocumentSnapshot;
use App\Models\Payment;
use App\Models\Plan;
use App\Models\Product;
use App\Models\Subscription;
use App\Models\User;
use App\Models\WhatsAppOutboundMessage;
use App\Models\Workspace;
use App\Models\WorkspaceFeatureFlag;
use App\Services\Finance\FinanceBootstrapService;
use App\Services\Finance\QuoteService;
use Database\Seeders\FoundationSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Schema;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

class FinancePhaseDQuoteOutcomeTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Notification::fake();
        $this->seed(FoundationSeeder::class);
    }

    public function test_issued_quote_starts_pending(): void
    {
        [$user, $workspace, $quote] = $this->issuedQuote();

        $this->assertSame('issued', $quote->status);
        $this->assertSame('pending', $quote->outcome);
        $this->assertTrue($quote->isPendingOutcome());
        $this->assertNull($quote->accepted_at);
        $this->assertNull($quote->converted_invoice_id);
        $this->assertSame(0, FinanceInvoice::withoutGlobalScopes()->count());
        $this->assertSame(0, FinanceJournalEntry::withoutGlobalScopes()->count());
        $this->assertNonFinancialQuote($workspace, $quote);
    }

    public function test_authorized_user_can_accept_issued_quote_without_creating_invoice(): void
    {
        [$user, $workspace, $quote] = $this->issuedQuote();

        $this->actingAs($user)
            ->withSession(['current_workspace_id' => $workspace->id])
            ->post(route('workspace.finance.quotes.accept', $quote))
            ->assertRedirect(route('workspace.finance.quotes.show', $quote))
            ->assertSessionHas('success');

        $quote->refresh();
        $this->assertSame('issued', $quote->status);
        $this->assertSame('accepted', $quote->outcome);
        $this->assertNotNull($quote->accepted_at);
        $this->assertSame($user->id, $quote->accepted_by);
        $this->assertNull($quote->converted_invoice_id);
        $this->assertSame(0, FinanceInvoice::withoutGlobalScopes()->count());
        $this->assertSame(0, FinanceJournalEntry::withoutGlobalScopes()->count());
        $this->assertAuditExists($workspace, $quote, 'quote_accepted');
        $this->assertNonFinancialQuote($workspace, $quote);
    }

    public function test_unauthorized_user_gets_403_on_accept_reject_and_convert(): void
    {
        [$owner, $workspace, $quote] = $this->issuedQuote();
        $agent = $this->attachStaff($workspace, ['quotes.view', 'quotes.edit', 'quotes.issue', 'quotes.send']);

        $this->actingAs($agent)
            ->withSession(['current_workspace_id' => $workspace->id])
            ->post(route('workspace.finance.quotes.accept', $quote))
            ->assertForbidden();

        $this->actingAs($agent)
            ->withSession(['current_workspace_id' => $workspace->id])
            ->post(route('workspace.finance.quotes.reject', $quote), ['rejection_reason' => 'no'])
            ->assertForbidden();

        app(QuoteService::class)->accept($quote, (int) $owner->id);

        $this->actingAs($agent)
            ->withSession(['current_workspace_id' => $workspace->id])
            ->post(route('workspace.finance.quotes.convert', $quote))
            ->assertForbidden();

        $quote->refresh();
        $this->assertSame('accepted', $quote->outcome);
        $this->assertNull($quote->converted_invoice_id);
        $this->assertSame(0, FinanceInvoice::withoutGlobalScopes()->count());
    }

    public function test_draft_cannot_be_accepted(): void
    {
        [$user, $workspace] = $this->createWorkspaceOwner('company');
        $customer = $this->makeCustomer($workspace, 'Draft Outcome Customer');
        $quote = app(QuoteService::class)->create($workspace, $this->servicePayload($customer->id), (int) $user->id);

        $this->actingAs($user)
            ->withSession(['current_workspace_id' => $workspace->id])
            ->from(route('workspace.finance.quotes.show', $quote))
            ->post(route('workspace.finance.quotes.accept', $quote))
            ->assertRedirect()
            ->assertSessionHas('error');

        $quote->refresh();
        $this->assertSame('draft', $quote->status);
        $this->assertSame('pending', $quote->outcome);
        $this->assertNull($quote->accepted_at);
        $this->assertSame(0, FinanceInvoice::withoutGlobalScopes()->count());
    }

    public function test_cancelled_cannot_be_accepted(): void
    {
        [$user, $workspace, $quote] = $this->issuedQuote();
        app(QuoteService::class)->cancel($quote);

        $this->actingAs($user)
            ->withSession(['current_workspace_id' => $workspace->id])
            ->from(route('workspace.finance.quotes.show', $quote))
            ->post(route('workspace.finance.quotes.accept', $quote))
            ->assertRedirect()
            ->assertSessionHas('error');

        $quote->refresh();
        $this->assertSame('cancelled', $quote->status);
        $this->assertSame('pending', $quote->outcome);
        $this->assertNull($quote->accepted_at);
    }

    public function test_authorized_user_can_reject_issued_quote_and_reason_persists(): void
    {
        [$user, $workspace, $quote] = $this->issuedQuote();

        $this->actingAs($user)
            ->withSession(['current_workspace_id' => $workspace->id])
            ->post(route('workspace.finance.quotes.reject', $quote), [
                'rejection_reason' => 'السعر غير مناسب',
            ])
            ->assertRedirect(route('workspace.finance.quotes.show', $quote));

        $quote->refresh();
        $this->assertSame('issued', $quote->status);
        $this->assertSame('rejected', $quote->outcome);
        $this->assertSame('السعر غير مناسب', $quote->rejection_reason);
        $this->assertNotNull($quote->rejected_at);
        $this->assertSame($user->id, $quote->rejected_by);
        $this->assertAuditExists($workspace, $quote, 'quote_rejected');

        $this->actingAs($user)
            ->withSession(['current_workspace_id' => $workspace->id])
            ->get(route('workspace.finance.quotes.show', $quote))
            ->assertOk()
            ->assertSee('مرفوض')
            ->assertSee('السعر غير مناسب')
            ->assertDontSee('تحويل إلى فاتورة');

        $this->assertNonFinancialQuote($workspace, $quote);
    }

    public function test_accepted_cannot_be_rejected_and_rejected_cannot_be_accepted_or_converted(): void
    {
        [$user, $workspace, $accepted] = $this->issuedQuote('Accept Lock Customer');
        app(QuoteService::class)->accept($accepted, (int) $user->id);

        $this->actingAs($user)
            ->withSession(['current_workspace_id' => $workspace->id])
            ->from(route('workspace.finance.quotes.show', $accepted))
            ->post(route('workspace.finance.quotes.reject', $accepted), ['rejection_reason' => 'late'])
            ->assertRedirect()
            ->assertSessionHas('error');

        $accepted->refresh();
        $this->assertSame('accepted', $accepted->outcome);
        $this->assertNull($accepted->rejected_at);

        [$userB, $workspaceB, $rejected] = $this->issuedQuote('Reject Lock Customer');
        app(QuoteService::class)->reject($rejected, (int) $userB->id, 'لا');

        $this->actingAs($userB)
            ->withSession(['current_workspace_id' => $workspaceB->id])
            ->from(route('workspace.finance.quotes.show', $rejected))
            ->post(route('workspace.finance.quotes.accept', $rejected))
            ->assertRedirect()
            ->assertSessionHas('error');

        $this->actingAs($userB)
            ->withSession(['current_workspace_id' => $workspaceB->id])
            ->from(route('workspace.finance.quotes.show', $rejected))
            ->post(route('workspace.finance.quotes.convert', $rejected))
            ->assertRedirect()
            ->assertSessionHas('error');

        $rejected->refresh();
        $this->assertSame('rejected', $rejected->outcome);
        $this->assertNull($rejected->converted_invoice_id);
        $this->assertSame(0, FinanceInvoice::withoutGlobalScopes()->where('workspace_id', $workspaceB->id)->count());
    }

    public function test_accepted_quote_converts_to_draft_invoice_copying_commercial_data(): void
    {
        [$user, $workspace] = $this->createWorkspaceOwner('company');
        $customer = $this->makeCustomer($workspace, 'Convert Customer', [
            'vat_number' => '310000000000003',
        ]);
        $product = Product::withoutGlobalScopes()->create([
            'workspace_id' => $workspace->id,
            'name' => 'خدمة كتالوج',
            'slug' => 'phase-d-catalog',
            'sku' => 'QD-1',
            'price' => 80,
            'currency' => 'SAR',
            'status' => 'active',
            'inventory_tracking' => false,
            'stock' => 0,
        ]);

        $quote = app(QuoteService::class)->create($workspace, [
            'customer_id' => $customer->id,
            'issue_date' => now()->toDateString(),
            'expiry_date' => now()->addDays(20)->toDateString(),
            'currency' => 'SAR',
            'status' => 'draft',
            'tax_profile_type' => 'standard',
            'tax_rate' => 15,
            'notes' => 'ملاحظات العرض',
            'terms' => 'شروط الدفع 14 يوماً',
            'items' => [
                [
                    'product_id' => $product->id,
                    'product_name' => 'خدمة كتالوج',
                    'description' => 'بند منتج',
                    'unit' => 'ساعة',
                    'quantity' => 2,
                    'unit_price' => 80,
                    'discount' => 10,
                    'tax_rate' => 15,
                    'tax_type' => 'standard',
                    'total' => 999999,
                    'tax_amount' => 1,
                ],
                [
                    'product_id' => null,
                    'description' => 'عزل أسطح',
                    'unit' => 'متر',
                    'quantity' => 10,
                    'unit_price' => 45,
                    'discount' => 0,
                    'tax_rate' => 15,
                    'tax_type' => 'standard',
                    'total' => 1,
                    'tax_amount' => 1,
                ],
            ],
        ], (int) $user->id);
        $quote = app(QuoteService::class)->issue($quote, (int) $user->id);
        $quote = app(QuoteService::class)->accept($quote, (int) $user->id);

        $this->actingAs($user)
            ->withSession(['current_workspace_id' => $workspace->id])
            ->post(route('workspace.finance.quotes.convert', $quote), [
                'total' => 1,
                'subtotal' => 1,
                'tax_amount' => 1,
                'invoice_number' => $quote->quote_number,
            ])
            ->assertRedirect(route('workspace.finance.quotes.show', $quote))
            ->assertSessionHas('success');

        $quote->refresh();
        $this->assertSame('converted', $quote->outcome);
        $this->assertNotNull($quote->converted_invoice_id);
        $this->assertNotNull($quote->converted_at);
        $this->assertSame($user->id, $quote->converted_by);
        $this->assertSame('issued', $quote->status);

        $invoice = FinanceInvoice::withoutGlobalScopes()->findOrFail($quote->converted_invoice_id);
        $this->assertSame((int) $workspace->id, (int) $invoice->workspace_id);
        $this->assertSame((int) $quote->workspace_id, (int) $invoice->workspace_id);
        $this->assertSame((int) $customer->id, (int) $invoice->customer_id);
        $this->assertSame('SAR', $invoice->currency);
        $this->assertTrue($invoice->isDraft());
        $this->assertNotSame($quote->quote_number, $invoice->invoice_number);
        $this->assertStringNotContainsString('Q-', $invoice->invoice_number);
        $this->assertSame('شروط الدفع 14 يوماً', $invoice->payment_terms);
        $this->assertStringContainsString('ملاحظات العرض', (string) $invoice->notes);
        $this->assertStringContainsString($quote->quote_number, (string) $invoice->notes);

        $invoice->load('items');
        $this->assertCount(2, $invoice->items);

        $catalog = $invoice->items->firstWhere('product_id', $product->id);
        $this->assertNotNull($catalog);
        $this->assertSame('خدمة كتالوج', $catalog->product_name);
        $this->assertSame('ساعة', $catalog->unit);
        $this->assertSame('2.000', (string) $catalog->quantity);
        $this->assertSame('80.00', (string) $catalog->unit_price);
        $this->assertSame('10.00', (string) $catalog->discount);
        $this->assertSame('15.00', (string) $catalog->tax_rate);
        $this->assertSame('standard', $catalog->tax_profile_type);

        $freeText = $invoice->items->firstWhere('product_id', null);
        $this->assertNotNull($freeText);
        $this->assertNull($freeText->product_id);
        $this->assertSame('عزل أسطح', $freeText->product_name);
        $this->assertSame('متر', $freeText->unit);
        $this->assertSame('10.000', (string) $freeText->quantity);
        $this->assertSame('45.00', (string) $freeText->unit_price);

        $this->assertSame((string) $quote->subtotal, (string) $invoice->subtotal);
        $this->assertSame((string) $quote->discount, (string) $invoice->discount);
        $this->assertSame((string) $quote->tax_amount, (string) $invoice->tax_amount);
        $this->assertSame((string) $quote->total, (string) $invoice->total);
        $this->assertNotSame('1.00', (string) $invoice->total);

        $this->assertSame(0, FinanceJournalEntry::withoutGlobalScopes()->count());
        $this->assertSame(0, IssuedDocumentSnapshot::withoutGlobalScopes()->count());
        $this->assertSame(0, FinanceInvoicePayment::withoutGlobalScopes()->count());
        $this->assertSame(0, Payment::withoutGlobalScopes()->count());
        $this->assertAuditExists($workspace, $quote, 'quote_converted');
        $this->assertNonFinancialQuote($workspace, $quote);

        $this->actingAs($user)
            ->withSession(['current_workspace_id' => $workspace->id])
            ->get(route('workspace.finance.quotes.show', $quote))
            ->assertOk()
            ->assertSee('محوّل إلى فاتورة')
            ->assertSee($invoice->invoice_number)
            ->assertDontSee('تحويل إلى فاتورة');
    }

    public function test_converting_twice_does_not_create_a_second_invoice_or_audit(): void
    {
        [$user, $workspace, $quote] = $this->acceptedQuote();

        $this->actingAs($user)
            ->withSession(['current_workspace_id' => $workspace->id])
            ->post(route('workspace.finance.quotes.convert', $quote))
            ->assertRedirect();

        $quote->refresh();
        $invoiceId = $quote->converted_invoice_id;
        $this->assertNotNull($invoiceId);
        $this->assertSame(1, AuditLog::withoutGlobalScopes()->where('action', 'quote_converted')->where('entity_id', $quote->id)->count());

        $stale = FinanceQuote::withoutGlobalScopes()->findOrFail($quote->id);
        $stale->outcome = 'accepted';
        $stale->converted_invoice_id = null;

        $again = app(QuoteService::class)->convert($stale, (int) $user->id);
        $this->assertSame($invoiceId, $again->converted_invoice_id);

        $this->actingAs($user)
            ->withSession(['current_workspace_id' => $workspace->id])
            ->post(route('workspace.finance.quotes.convert', $quote))
            ->assertRedirect();

        $this->assertSame(1, FinanceInvoice::withoutGlobalScopes()->count());
        $this->assertSame(1, FinanceInvoice::withoutGlobalScopes()->whereKey($invoiceId)->count());
        $this->assertSame(1, AuditLog::withoutGlobalScopes()->where('action', 'quote_converted')->where('entity_id', $quote->id)->count());
        $this->assertSame(0, FinanceJournalEntry::withoutGlobalScopes()->count());
        $this->assertSame(0, IssuedDocumentSnapshot::withoutGlobalScopes()->count());

        $uniqueIndexes = collect(Schema::getIndexes('finance_quotes'))
            ->filter(fn (array $index) => ($index['unique'] ?? false) === true)
            ->pluck('columns')
            ->flatten()
            ->all();
        $this->assertContains('converted_invoice_id', $uniqueIndexes);
    }

    public function test_conversion_does_not_bypass_invoice_issue_service_and_issue_still_works(): void
    {
        [$user, $workspace, $quote] = $this->acceptedQuote();
        app(FinanceBootstrapService::class)->ensureWorkspaceFinanceSetup($workspace);

        $converted = app(QuoteService::class)->convert($quote, (int) $user->id);
        $invoice = FinanceInvoice::withoutGlobalScopes()->findOrFail($converted->converted_invoice_id);

        $this->assertTrue($invoice->isDraft());
        $this->assertSame(0, FinanceJournalEntry::withoutGlobalScopes()->count());
        $this->assertSame(0, IssuedDocumentSnapshot::withoutGlobalScopes()->count());

        $this->actingAs($user)
            ->withSession(['current_workspace_id' => $workspace->id])
            ->post(route('workspace.finance.invoices.issue', $invoice))
            ->assertRedirect();

        $invoice->refresh();
        $this->assertTrue($invoice->isIssued());
        $this->assertSame(1, FinanceJournalEntry::withoutGlobalScopes()->where('reference_type', FinanceInvoice::class)->where('reference_id', $invoice->id)->count());
        $this->assertGreaterThan(0, IssuedDocumentSnapshot::withoutGlobalScopes()->count());
        $this->assertSame(0, FinanceInvoicePayment::withoutGlobalScopes()->count());
        $this->assertSame(0, Payment::withoutGlobalScopes()->count());

        $quote->refresh();
        $this->assertSame('converted', $quote->outcome);
        $this->assertSame('issued', $quote->status);
        $this->assertSame(0, FinanceJournalEntry::withoutGlobalScopes()->where('reference_type', FinanceQuote::class)->count());
    }

    public function test_cross_workspace_quote_access_returns_404(): void
    {
        [$userA, $workspaceA] = $this->createWorkspaceOwner('company');
        [, , $quoteB] = $this->issuedQuote('Foreign Quote Customer');

        $this->actingAs($userA)
            ->withSession(['current_workspace_id' => $workspaceA->id])
            ->post(route('workspace.finance.quotes.accept', $quoteB))
            ->assertNotFound();

        $this->actingAs($userA)
            ->withSession(['current_workspace_id' => $workspaceA->id])
            ->post(route('workspace.finance.quotes.convert', $quoteB))
            ->assertNotFound();

        $quoteB->refresh();
        $this->assertSame('pending', $quoteB->outcome);
        $this->assertNull($quoteB->converted_invoice_id);
    }

    public function test_expired_quote_cannot_be_accepted_or_converted(): void
    {
        [$user, $workspace] = $this->createWorkspaceOwner('company');
        $customer = $this->makeCustomer($workspace, 'Expired Quote Customer');
        $quote = app(QuoteService::class)->create($workspace, array_merge($this->servicePayload($customer->id), [
            'issue_date' => now()->subDays(10)->toDateString(),
            'expiry_date' => now()->subDay()->toDateString(),
        ]), (int) $user->id);
        $quote = app(QuoteService::class)->issue($quote, (int) $user->id);

        $this->actingAs($user)
            ->withSession(['current_workspace_id' => $workspace->id])
            ->from(route('workspace.finance.quotes.show', $quote))
            ->post(route('workspace.finance.quotes.accept', $quote))
            ->assertRedirect()
            ->assertSessionHas('error');

        $quote->refresh();
        $this->assertSame('pending', $quote->outcome);

        $this->actingAs($user)
            ->withSession(['current_workspace_id' => $workspace->id])
            ->post(route('workspace.finance.quotes.reject', $quote), ['rejection_reason' => 'انتهت الصلاحية'])
            ->assertRedirect()
            ->assertSessionHas('success');

        $quote->refresh();
        $this->assertSame('rejected', $quote->outcome);
        $this->assertSame('انتهت الصلاحية', $quote->rejection_reason);
        $this->assertSame(0, FinanceInvoice::withoutGlobalScopes()->count());
    }

    public function test_show_page_actions_follow_outcome_rules(): void
    {
        [$user, $workspace, $quote] = $this->issuedQuote();

        $accept = Route::getRoutes()->getByName('workspace.finance.quotes.accept');
        $reject = Route::getRoutes()->getByName('workspace.finance.quotes.reject');
        $convert = Route::getRoutes()->getByName('workspace.finance.quotes.convert');
        $this->assertContains('POST', $accept->methods());
        $this->assertNotContains('GET', $accept->methods());
        $this->assertContains('POST', $reject->methods());
        $this->assertContains('POST', $convert->methods());

        $this->actingAs($user)->withSession(['current_workspace_id' => $workspace->id])
            ->get(route('workspace.finance.quotes.show', $quote))
            ->assertOk()
            ->assertSee('قبول العرض')
            ->assertSee('رفض العرض')
            ->assertDontSee('تحويل إلى فاتورة')
            ->assertDontSee('WhatsApp', false);

        app(QuoteService::class)->accept($quote, (int) $user->id);

        $this->actingAs($user)->withSession(['current_workspace_id' => $workspace->id])
            ->get(route('workspace.finance.quotes.show', $quote))
            ->assertOk()
            ->assertSee('مقبول')
            ->assertSee('تحويل إلى فاتورة')
            ->assertDontSee('رفض العرض');
    }

    /**
     * @return array{0: User, 1: Workspace, 2: FinanceQuote, 3: Customer}
     */
    private function issuedQuote(string $customerName = 'Phase D Customer'): array
    {
        [$user, $workspace] = $this->createWorkspaceOwner('company');
        $customer = $this->makeCustomer($workspace, $customerName);
        $quote = app(QuoteService::class)->create($workspace, $this->servicePayload($customer->id), (int) $user->id);
        $quote = app(QuoteService::class)->issue($quote, (int) $user->id);

        return [$user, $workspace, $quote, $customer];
    }

    /**
     * @return array{0: User, 1: Workspace, 2: FinanceQuote, 3: Customer}
     */
    private function acceptedQuote(string $customerName = 'Accepted Convert Customer'): array
    {
        [$user, $workspace, $quote, $customer] = $this->issuedQuote($customerName);
        $quote = app(QuoteService::class)->accept($quote, (int) $user->id);

        return [$user, $workspace, $quote, $customer];
    }

    /**
     * @return array<string, mixed>
     */
    private function servicePayload(int $customerId): array
    {
        return [
            'customer_id' => $customerId,
            'issue_date' => now()->toDateString(),
            'expiry_date' => now()->addDays(30)->toDateString(),
            'currency' => 'SAR',
            'status' => 'draft',
            'tax_profile_type' => 'standard',
            'tax_rate' => 15,
            'notes' => 'ملاحظات',
            'terms' => 'شروط',
            'items' => [[
                'product_name' => 'خدمة عرض',
                'description' => 'بند اختبار',
                'unit' => 'قطعة',
                'quantity' => 1,
                'unit_price' => 100,
                'discount' => 0,
                'tax_rate' => 15,
                'tax_type' => 'standard',
            ]],
        ];
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

    private function assertAuditExists(Workspace $workspace, FinanceQuote $quote, string $action): void
    {
        $this->assertTrue(
            AuditLog::withoutGlobalScopes()
                ->where('workspace_id', $workspace->id)
                ->where('action', $action)
                ->where('entity_id', $quote->id)
                ->where('entity_type', FinanceQuote::class)
                ->exists()
        );
    }

    private function assertNonFinancialQuote(Workspace $workspace, FinanceQuote $quote): void
    {
        $this->assertSame(0, FinanceInvoicePayment::withoutGlobalScopes()->count());
        $this->assertSame(0, Payment::withoutGlobalScopes()->count());
        $this->assertSame(0, WhatsAppOutboundMessage::withoutGlobalScopes()->count());
        $this->assertSame(0, EmailMessage::withoutGlobalScopes()->count());
        $this->assertSame(0, FinanceJournalEntry::withoutGlobalScopes()->where('reference_type', FinanceQuote::class)->count());
        $this->assertNotNull($quote->fresh());
        $this->assertSame((int) $workspace->id, (int) $quote->workspace_id);
    }
}
