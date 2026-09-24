<?php

namespace Tests\Feature\Feature\Finance;

use App\Enums\Finance\TaxProfileType;
use App\Models\Customer;
use App\Models\Finance\FinanceExpense;
use App\Models\Finance\FinanceQuoteAttachment;
use App\Models\Plan;
use App\Models\Subscription;
use App\Models\User;
use App\Models\Workspace;
use App\Models\WorkspaceFeatureFlag;
use App\Services\Finance\FinanceBootstrapService;
use App\Support\Storage\PrivateDocumentDisk;
use App\Support\Tenancy\WorkspaceContext;
use Database\Seeders\FoundationSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Laravel\Sanctum\Sanctum;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

class FinanceAttachmentPrivacyTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(FoundationSeeder::class);
    }

    public function test_quote_attachment_is_stored_privately_and_not_publicly_accessible(): void
    {
        [$owner, $workspace, $headers, $quoteId, $attachmentId, $path] = $this->uploadQuoteAttachment();

        $this->assertTrue(Storage::disk(PrivateDocumentDisk::DISK)->exists($path));
        $this->assertFalse(Storage::disk(PrivateDocumentDisk::LEGACY_DISK)->exists($path));

        $publicGuess = $this->get('/storage/'.$path);
        $this->assertContains($publicGuess->status(), [403, 404]);
        $this->assertStringNotContainsString('%PDF', (string) $publicGuess->getContent());

        $this->withHeaders($headers)
            ->get('/api/finance/v1/quotes/'.$quoteId.'/attachments/'.$attachmentId)
            ->assertOk();

        [$otherOwner, $otherWorkspace] = $this->createWorkspaceOwner('Quote Privacy B');
        Sanctum::actingAs($otherOwner);
        $this->withHeaders($this->workspaceHeader($otherWorkspace))
            ->getJson('/api/finance/v1/quotes/'.$quoteId.'/attachments/'.$attachmentId)
            ->assertNotFound();

        Sanctum::actingAs($owner);
        $this->withHeaders($headers)
            ->deleteJson('/api/finance/v1/quotes/'.$quoteId.'/attachments/'.$attachmentId)
            ->assertOk()
            ->assertJsonPath('data.attachments', []);

        $this->assertFalse(Storage::disk(PrivateDocumentDisk::DISK)->exists($path));
        $this->assertFalse(Storage::disk(PrivateDocumentDisk::LEGACY_DISK)->exists($path));
    }

    public function test_quote_attachment_download_requires_authentication(): void
    {
        [, , , $quoteId, $attachmentId] = $this->uploadQuoteAttachment();

        $this->flushHeaders();
        $this->app['auth']->forgetGuards();

        $this->getJson('/api/finance/v1/quotes/'.$quoteId.'/attachments/'.$attachmentId)
            ->assertUnauthorized();
    }

    public function test_expense_attachment_is_stored_privately_and_not_publicly_accessible(): void
    {
        [$owner, $workspace, $headers, $expenseId, $path] = $this->createExpenseWithAttachment();

        $this->assertTrue(Storage::disk(PrivateDocumentDisk::DISK)->exists($path));
        $this->assertFalse(Storage::disk(PrivateDocumentDisk::LEGACY_DISK)->exists($path));

        $publicGuess = $this->get('/storage/'.$path);
        $this->assertContains($publicGuess->status(), [403, 404]);
        $this->assertStringNotContainsString('%PDF', (string) $publicGuess->getContent());

        $this->withHeaders($headers)
            ->get('/api/finance/v1/expenses/'.$expenseId.'/attachment')
            ->assertOk();

        [$otherOwner, $otherWorkspace] = $this->createWorkspaceOwner('Expense Privacy B');
        Sanctum::actingAs($otherOwner);
        $this->withHeaders($this->workspaceHeader($otherWorkspace))
            ->getJson('/api/finance/v1/expenses/'.$expenseId.'/attachment')
            ->assertNotFound();

        Sanctum::actingAs($owner);
        $this->withHeaders($headers)
            ->deleteJson('/api/finance/v1/expenses/'.$expenseId)
            ->assertOk();

        $this->assertFalse(Storage::disk(PrivateDocumentDisk::DISK)->exists($path));
        $this->assertFalse(Storage::disk(PrivateDocumentDisk::LEGACY_DISK)->exists($path));
    }

    public function test_expense_attachment_download_requires_authentication(): void
    {
        [, , , $expenseId] = $this->createExpenseWithAttachment();

        $this->flushHeaders();
        $this->app['auth']->forgetGuards();

        $this->getJson('/api/finance/v1/expenses/'.$expenseId.'/attachment')
            ->assertUnauthorized();
    }

    public function test_legacy_public_quote_attachment_remains_downloadable_via_authorized_endpoint(): void
    {
        [$owner, $workspace, $headers, $quoteId, $attachmentId, $path] = $this->uploadQuoteAttachment();

        Storage::disk(PrivateDocumentDisk::DISK)->delete($path);
        Storage::disk(PrivateDocumentDisk::LEGACY_DISK)->put($path, '%PDF-1.4 legacy quote attachment');

        $this->withHeaders($headers)
            ->get('/api/finance/v1/quotes/'.$quoteId.'/attachments/'.$attachmentId)
            ->assertOk();

        $publicGuess = $this->get('/storage/'.$path);
        $this->assertContains($publicGuess->status(), [403, 404]);
    }

    public function test_legacy_public_expense_attachment_remains_downloadable_via_authorized_endpoint(): void
    {
        [$owner, $workspace, $headers, $expenseId, $path] = $this->createExpenseWithAttachment();

        Storage::disk(PrivateDocumentDisk::DISK)->delete($path);
        Storage::disk(PrivateDocumentDisk::LEGACY_DISK)->put($path, '%PDF-1.4 legacy expense attachment');

        $this->withHeaders($headers)
            ->get('/api/finance/v1/expenses/'.$expenseId.'/attachment')
            ->assertOk();

        $publicGuess = $this->get('/storage/'.$path);
        $this->assertContains($publicGuess->status(), [403, 404]);
    }

    /**
     * @return array{0: User, 1: Workspace, 2: array<string, string>, 3: int, 4: int, 5: string}
     */
    private function uploadQuoteAttachment(): array
    {
        [$owner, $workspace] = $this->createWorkspaceOwner('Quote Privacy A');
        Sanctum::actingAs($owner);
        $headers = $this->workspaceHeader($workspace);
        $customer = $this->makeBuyer($workspace, 'Quote Privacy Customer');

        $quote = $this->withHeaders($headers)->postJson('/api/finance/v1/quotes', [
            'customer_id' => $customer->id,
            'issue_date' => now()->toDateString(),
            'expiry_date' => now()->addDays(10)->toDateString(),
            'currency' => 'SAR',
            'tax_profile_type' => TaxProfileType::Standard->value,
            'tax_rate' => 15,
            'items' => [[
                'product_name' => 'Consulting',
                'quantity' => 1,
                'unit_price' => 100,
                'discount' => 0,
                'tax_rate' => 15,
                'tax_type' => TaxProfileType::Standard->value,
            ]],
        ])->assertCreated();

        $quoteId = (int) $quote->json('data.id');
        $upload = $this->withHeaders($headers)->post('/api/finance/v1/quotes/'.$quoteId.'/attachments', [
            'attachments' => [UploadedFile::fake()->create('quote-scan.pdf', 14, 'application/pdf')],
        ])->assertOk();

        $attachmentId = (int) $upload->json('data.attachments.0.id');
        app(WorkspaceContext::class)->set($workspace);
        $attachment = FinanceQuoteAttachment::query()->findOrFail($attachmentId);

        return [$owner, $workspace, $headers, $quoteId, $attachmentId, (string) $attachment->file_path];
    }

    /**
     * @return array{0: User, 1: Workspace, 2: array<string, string>, 3: int, 4: string}
     */
    private function createExpenseWithAttachment(): array
    {
        [$owner, $workspace] = $this->createWorkspaceOwner('Expense Privacy A');
        Sanctum::actingAs($owner);
        $headers = $this->workspaceHeader($workspace);

        $created = $this->withHeaders($headers)->post('/api/finance/v1/expenses', [
            'expense_date' => now()->toDateString(),
            'description' => 'Receipt upload',
            'amount' => 100,
            'tax_rate' => 0,
            'status' => 'draft',
            'attachment_file' => UploadedFile::fake()->create('expense-receipt.pdf', 12, 'application/pdf'),
        ])->assertCreated();

        $expenseId = (int) $created->json('data.id');
        app(WorkspaceContext::class)->set($workspace);
        $expense = FinanceExpense::query()->findOrFail($expenseId);

        return [$owner, $workspace, $headers, $expenseId, (string) $expense->attachment_path];
    }

    /**
     * @return array{0: User, 1: Workspace}
     */
    private function createWorkspaceOwner(string $name): array
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

    private function makeBuyer(Workspace $workspace, string $name): Customer
    {
        app(WorkspaceContext::class)->set($workspace);

        return Customer::withoutGlobalScopes()->create([
            'workspace_id' => $workspace->id,
            'name' => $name,
            'phone' => '05'.random_int(10000000, 99999999),
            'email' => strtolower(str_replace(' ', '.', $name)).uniqid().'@example.com',
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
     * @return array<string, string>
     */
    private function workspaceHeader(Workspace $workspace): array
    {
        return [
            'X-Workspace-Id' => (string) $workspace->id,
            'Accept' => 'application/json',
        ];
    }
}
