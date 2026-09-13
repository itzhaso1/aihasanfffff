<?php

namespace Tests\Feature\Feature\Finance;

use App\Models\AuditLog;
use App\Models\Customer;
use App\Models\EmailLog;
use App\Models\EmailMessage;
use App\Models\Finance\FinanceDocumentDelivery;
use App\Models\Finance\FinanceInvoice;
use App\Models\Finance\FinanceInvoicePayment;
use App\Models\Finance\FinanceJournalEntry;
use App\Models\Finance\FinanceQuote;
use App\Models\Finance\IssuedDocumentSnapshot;
use App\Models\Plan;
use App\Models\Subscription;
use App\Models\User;
use App\Models\WhatsAppOutboundMessage;
use App\Models\Workspace;
use App\Models\WorkspaceFeatureFlag;
use App\Services\Email\CentralEmailService;
use App\Services\Finance\QuoteService;
use App\Services\WhatsApp\WhatsAppOutboundService;
use Database\Seeders\FoundationSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Storage;
use Mockery;
use RuntimeException;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

class FinancePhaseCSendQuoteTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Notification::fake();
        Storage::fake('public');
        $this->seed(FoundationSeeder::class);
    }

    public function test_owner_can_send_quote_email_with_pdf_and_delivery_record(): void
    {
        [$user, $workspace, $quote, $customer] = $this->issuedQuote();
        $payloads = [];
        $this->fakeSuccessfulMailer($payloads);
        $this->forbidWhatsApp();

        $this->actingAs($user)
            ->withSession(['current_workspace_id' => $workspace->id])
            ->post(route('workspace.finance.quotes.send', $quote), [
                'email' => $customer->email,
                'phone' => '0500000000',
                'subject' => 'عرض سعر رقم '.$quote->quote_number,
                'message' => 'السلام عليكم، نرفق عرض السعر.',
                'attach_pdf' => '1',
            ])
            ->assertRedirect(route('workspace.finance.quotes.show', $quote))
            ->assertSessionHas('success');

        $this->assertCount(1, $payloads);
        $sent = $payloads[0];
        $this->assertSame('quote_email', $sent['template']);
        $this->assertSame([$customer->email], $sent['to']);
        $this->assertStringContainsString($quote->quote_number, (string) $sent['subject']);
        $this->assertStringContainsString($customer->name, implode("\n", $sent['data']['lines'] ?? []));
        $this->assertStringContainsString($quote->quote_number, implode("\n", $sent['data']['lines'] ?? []));
        $this->assertStringContainsString(number_format((float) $quote->total, 2), implode("\n", $sent['data']['lines'] ?? []));
        $this->assertNotEmpty($sent['attachments']);
        $this->assertSame('quote-'.$quote->quote_number.'.pdf', $sent['attachments'][0]['name']);
        $this->assertSame('application/pdf', $sent['attachments'][0]['mime']);
        $storedPath = $sent['attachments'][0]['storage_path'];
        $this->assertTrue(Storage::disk('public')->exists($storedPath));
        $this->assertStringStartsWith('%PDF', (string) Storage::disk('public')->get($storedPath));
        $this->assertStringContainsString((string) $workspace->id, $storedPath);
        $this->assertStringContainsString($quote->quote_number, $storedPath);

        $delivery = FinanceDocumentDelivery::withoutGlobalScopes()->firstOrFail();
        $this->assertSame('quote', $delivery->document_type);
        $this->assertSame($quote->id, $delivery->document_id);
        $this->assertSame('email', $delivery->channel);
        $this->assertSame($customer->email, $delivery->recipient);
        $this->assertSame('0500000000', $delivery->recipient_phone);
        $this->assertSame('sent', $delivery->status);
        $this->assertNotNull($delivery->sent_at);
        $this->assertNotNull($delivery->provider_message_id);
        $this->assertSame('issued', $quote->fresh()->status);
        $this->assertSame('115.00', (string) $quote->fresh()->total);

        $this->assertTrue(
            AuditLog::withoutGlobalScopes()
                ->where('workspace_id', $workspace->id)
                ->where('action', 'quote_sent')
                ->where('entity_id', $quote->id)
                ->where('entity_type', FinanceQuote::class)
                ->exists()
        );

        $this->assertSame(0, FinanceInvoice::withoutGlobalScopes()->count());
        $this->assertSame(0, FinanceInvoicePayment::withoutGlobalScopes()->count());
        $this->assertSame(0, FinanceJournalEntry::withoutGlobalScopes()->count());
        $this->assertSame(0, IssuedDocumentSnapshot::withoutGlobalScopes()->count());
        $this->assertSame(0, EmailMessage::withoutGlobalScopes()->count());
        $this->assertSame(0, WhatsAppOutboundMessage::withoutGlobalScopes()->count());
    }

    public function test_agent_without_quotes_send_cannot_send(): void
    {
        [$owner, $workspace, $quote] = $this->issuedQuote();
        $this->fakeSuccessfulMailer();
        $editor = $this->attachStaff($workspace, ['quotes.view', 'quotes.edit']);

        $this->actingAs($editor)
            ->withSession(['current_workspace_id' => $workspace->id])
            ->post(route('workspace.finance.quotes.send', $quote), [
                'email' => 'agent@example.com',
                'attach_pdf' => '1',
            ])
            ->assertForbidden();

        $this->assertSame(0, FinanceDocumentDelivery::withoutGlobalScopes()->count());
        $this->assertSame('issued', $quote->fresh()->status);
    }

    public function test_agent_with_quotes_send_can_send(): void
    {
        [$owner, $workspace, $quote, $customer] = $this->issuedQuote();
        $this->fakeSuccessfulMailer();
        $sender = $this->attachStaff($workspace, ['quotes.view', 'quotes.send']);

        $this->actingAs($sender)
            ->withSession(['current_workspace_id' => $workspace->id])
            ->post(route('workspace.finance.quotes.send', $quote), [
                'email' => $customer->email,
                'attach_pdf' => '1',
            ])
            ->assertRedirect();

        $this->assertSame(1, FinanceDocumentDelivery::withoutGlobalScopes()->count());
    }

    public function test_workspace_a_cannot_send_workspace_b_quote(): void
    {
        [$userA, $workspaceA] = $this->createWorkspaceOwner('company');
        [, $workspaceB, $quoteB] = $this->issuedQuote();
        $this->fakeSuccessfulMailer();

        $this->actingAs($userA)
            ->withSession(['current_workspace_id' => $workspaceA->id])
            ->post(route('workspace.finance.quotes.send', $quoteB), [
                'email' => 'a@example.com',
                'attach_pdf' => '1',
            ])
            ->assertNotFound();

        $this->assertSame(0, FinanceDocumentDelivery::withoutGlobalScopes()->count());
    }

    public function test_missing_and_invalid_email_prevent_send(): void
    {
        [$user, $workspace, $quote] = $this->issuedQuote();
        $this->fakeSuccessfulMailer();

        $this->actingAs($user)
            ->withSession(['current_workspace_id' => $workspace->id])
            ->from(route('workspace.finance.quotes.show', $quote))
            ->post(route('workspace.finance.quotes.send', $quote), [
                'email' => '',
                'attach_pdf' => '1',
            ])
            ->assertRedirect()
            ->assertSessionHasErrors(['email']);

        $this->actingAs($user)
            ->withSession(['current_workspace_id' => $workspace->id])
            ->from(route('workspace.finance.quotes.show', $quote))
            ->post(route('workspace.finance.quotes.send', $quote), [
                'email' => 'not-an-email',
                'attach_pdf' => '1',
            ])
            ->assertRedirect()
            ->assertSessionHasErrors(['email']);

        $this->assertSame(0, FinanceDocumentDelivery::withoutGlobalScopes()->count());
        $this->assertSame('115.00', (string) $quote->fresh()->total);
    }

    public function test_multiple_sends_create_independent_delivery_records(): void
    {
        [$user, $workspace, $quote, $customer] = $this->issuedQuote();
        $this->fakeSuccessfulMailer();

        $this->actingAs($user)->withSession(['current_workspace_id' => $workspace->id])
            ->post(route('workspace.finance.quotes.send', $quote), [
                'email' => $customer->email,
                'attach_pdf' => '1',
            ])
            ->assertRedirect();
        $this->actingAs($user)->withSession(['current_workspace_id' => $workspace->id])
            ->post(route('workspace.finance.quotes.send', $quote), [
                'email' => $customer->email,
                'attach_pdf' => '1',
            ])
            ->assertRedirect();

        $deliveries = FinanceDocumentDelivery::withoutGlobalScopes()->orderBy('id')->get();
        $this->assertCount(2, $deliveries);
        $this->assertNotSame($deliveries[0]->id, $deliveries[1]->id);
        $this->assertTrue($deliveries->every(fn (FinanceDocumentDelivery $row): bool => $row->status === 'sent'));
        $this->assertSame('issued', $quote->fresh()->status);
    }

    public function test_failed_email_is_recorded_and_does_not_change_quote_financials(): void
    {
        [$user, $workspace, $quote, $customer] = $this->issuedQuote();
        $mailer = Mockery::mock(CentralEmailService::class);
        $mailer->shouldReceive('send')->once()->andThrow(new RuntimeException('تعذر إرسال البريد حاليًا، يرجى المحاولة لاحقًا.'));
        $this->app->instance(CentralEmailService::class, $mailer);

        $before = $quote->only(['subtotal', 'discount', 'tax_amount', 'total', 'status', 'customer_id']);

        $this->actingAs($user)
            ->withSession(['current_workspace_id' => $workspace->id])
            ->from(route('workspace.finance.quotes.show', $quote))
            ->post(route('workspace.finance.quotes.send', $quote), [
                'email' => $customer->email,
                'attach_pdf' => '1',
            ])
            ->assertRedirect()
            ->assertSessionHas('error');

        $quote->refresh();
        $this->assertSame($before, $quote->only(['subtotal', 'discount', 'tax_amount', 'total', 'status', 'customer_id']));

        $delivery = FinanceDocumentDelivery::withoutGlobalScopes()->firstOrFail();
        $this->assertSame('failed', $delivery->status);
        $this->assertSame($customer->email, $delivery->recipient);
        $this->assertNull($delivery->sent_at);
        $this->assertNotNull($delivery->error);
        $this->assertStringNotContainsString('stack', strtolower((string) $delivery->error));
        $this->assertSame(0, FinanceInvoice::withoutGlobalScopes()->count());
        $this->assertSame(0, FinanceJournalEntry::withoutGlobalScopes()->count());
        $this->assertFalse(
            AuditLog::withoutGlobalScopes()->where('action', 'quote_sent')->exists()
        );
    }

    public function test_draft_and_cancelled_quotes_cannot_be_sent(): void
    {
        [$user, $workspace] = $this->createWorkspaceOwner('company');
        $customer = $this->makeCustomer($workspace, 'Draft Buyer');
        $draft = app(QuoteService::class)->create($workspace, $this->servicePayload($customer->id), (int) $user->id);
        $this->fakeSuccessfulMailer();

        $this->actingAs($user)->withSession(['current_workspace_id' => $workspace->id])
            ->post(route('workspace.finance.quotes.send', $draft), [
                'email' => $customer->email,
                'attach_pdf' => '1',
            ])
            ->assertRedirect()
            ->assertSessionHas('error');

        $issued = app(QuoteService::class)->issue($draft, (int) $user->id);
        app(QuoteService::class)->cancel($issued);

        $this->actingAs($user)->withSession(['current_workspace_id' => $workspace->id])
            ->post(route('workspace.finance.quotes.send', $issued), [
                'email' => $customer->email,
                'attach_pdf' => '1',
            ])
            ->assertRedirect()
            ->assertSessionHas('error');

        $this->assertSame(0, FinanceDocumentDelivery::withoutGlobalScopes()->count());
    }

    public function test_send_route_is_post_only_and_show_page_exposes_send_ui(): void
    {
        [$user, $workspace, $quote, $customer] = $this->issuedQuote();
        $route = Route::getRoutes()->getByName('workspace.finance.quotes.send');
        $this->assertNotNull($route);
        $this->assertContains('POST', $route->methods());
        $this->assertNotContains('GET', $route->methods());
        $this->assertTrue(Route::has('workspace.finance.quotes.convert'));
        $this->assertTrue(Route::has('workspace.finance.quotes.accept'));
        $this->assertTrue(Route::has('workspace.finance.quotes.reject'));

        $this->actingAs($user)->withSession(['current_workspace_id' => $workspace->id])
            ->get(route('workspace.finance.quotes.show', $quote))
            ->assertOk()
            ->assertSee('إرسال عرض السعر')
            ->assertSee($customer->email)
            ->assertSee('سجل الإرسال')
            ->assertSee('إرفاق ملف PDF')
            ->assertSee('قبول العرض')
            ->assertSee('رفض العرض')
            ->assertDontSee('WhatsApp', false);
    }

    /**
     * @param  array<int, array<string, mixed>>  $payloads
     */
    private function fakeSuccessfulMailer(array &$payloads = []): void
    {
        $mailer = Mockery::mock(CentralEmailService::class);
        $mailer->shouldReceive('send')->andReturnUsing(function (array $payload) use (&$payloads): EmailLog {
            $payloads[] = $payload;
            $to = $payload['to'] ?? [];
            $recipient = is_array($to) ? implode(', ', $to) : (string) $to;

            return EmailLog::query()->create([
                'workspace_id' => $payload['workspace_id'] ?? null,
                'template' => $payload['template'] ?? 'quote_email',
                'recipient' => $recipient,
                'subject' => $payload['subject'] ?? null,
                'status' => 'sent',
                'provider' => 'test',
                'provider_message_id' => 'msg-'.uniqid(),
                'sent_at' => now(),
                'meta' => $payload['meta'] ?? null,
            ]);
        });
        $this->app->instance(CentralEmailService::class, $mailer);
    }

    private function forbidWhatsApp(): void
    {
        $whatsapp = Mockery::mock(WhatsAppOutboundService::class);
        $whatsapp->shouldNotReceive('sendText');
        $this->app->instance(WhatsAppOutboundService::class, $whatsapp);
    }

    /**
     * @return array{0: User, 1: Workspace, 2: FinanceQuote, 3: Customer}
     */
    private function issuedQuote(): array
    {
        [$user, $workspace] = $this->createWorkspaceOwner('company');
        $customer = $this->makeCustomer($workspace, 'Send Quote Customer');
        $quote = app(QuoteService::class)->create($workspace, $this->servicePayload($customer->id), (int) $user->id);
        $quote = app(QuoteService::class)->issue($quote, (int) $user->id);

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
}
