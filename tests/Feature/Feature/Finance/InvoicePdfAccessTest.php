<?php

namespace Tests\Feature\Feature\Finance;

use App\Enums\Finance\TaxProfileType;
use App\Models\Customer;
use App\Models\Finance\FinanceInvoice;
use App\Models\Finance\FinanceInvoiceAttachment;
use App\Models\Plan;
use App\Models\Subscription;
use App\Models\User;
use App\Models\Workspace;
use App\Models\WorkspaceFeatureFlag;
use App\Services\Finance\FinanceBootstrapService;
use App\Services\Finance\InvoicePdfAccessService;
use App\Services\Finance\InvoiceService;
use App\Support\Storage\PrivateDocumentDisk;
use App\Support\Tenancy\WorkspaceContext;
use Barryvdh\DomPDF\Facade\Pdf;
use Database\Seeders\FoundationSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Storage;
use Laravel\Sanctum\Sanctum;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

class InvoicePdfAccessTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Notification::fake();
        Storage::fake('public');
        Storage::fake('local');
        $this->seed(FoundationSeeder::class);

        if (! class_exists(Pdf::class)) {
            $this->markTestSkipped('DomPDF package is not available in current test runtime.');
        }
    }

    public function test_web_pdf_opens_from_authorized_invoice_page_token(): void
    {
        [$user, $workspace, $invoice] = $this->issuedSalesInvoice();

        $show = $this->actingAs($user)
            ->withSession(['current_workspace_id' => $workspace->id])
            ->get(route('workspace.finance.invoices.show', $invoice))
            ->assertOk();

        $html = $show->getContent();
        $this->assertStringContainsString('download_token=', $html);
        $this->assertStringContainsString((string) $invoice->id, $html);

        $pdfUrl = app(InvoicePdfAccessService::class)->webUrl($invoice, $user);
        $response = $this->actingAs($user)
            ->withSession(['current_workspace_id' => $workspace->id])
            ->get($pdfUrl);

        $response->assertOk();
        $this->assertStringContainsString('application/pdf', (string) $response->headers->get('content-type'));
        $this->assertStringNotContainsString(
            (string) $invoice->invoice_number,
            (string) $response->headers->get('content-disposition')
        );
    }

    public function test_web_pdf_rejects_unsigned_url_and_swapped_invoice_id(): void
    {
        [$user, $workspace, $first] = $this->issuedSalesInvoice('First Customer');
        $second = $this->issueSales($workspace, $this->makeBuyer($workspace, 'Second Customer'), (int) $user->id);
        $queryForFirst = app(InvoicePdfAccessService::class)->query($first, $user);

        $this->actingAs($user)
            ->withSession(['current_workspace_id' => $workspace->id])
            ->get(route('workspace.finance.invoices.pdf', $first))
            ->assertNotFound();

        $this->actingAs($user)
            ->withSession(['current_workspace_id' => $workspace->id])
            ->get(route('workspace.finance.invoices.pdf', ['invoice' => $second] + $queryForFirst))
            ->assertNotFound();

        foreach ([$first->invoice_number, $second->invoice_number] as $invoiceNumber) {
            $guess = $this->get('/storage/invoice-'.$invoiceNumber.'.pdf');
            $this->assertContains($guess->status(), [403, 404]);
            $this->assertStringNotContainsString('%PDF', (string) $guess->getContent());
        }
    }

    public function test_web_pdf_does_not_leak_foreign_workspace_invoice(): void
    {
        [$userA, $workspaceA, $invoiceA] = $this->issuedSalesInvoice('Alpha Customer');
        [$userB, $workspaceB, $invoiceB] = $this->issuedSalesInvoice('Beta Customer');
        $queryA = app(InvoicePdfAccessService::class)->query($invoiceA, $userA);
        $queryB = app(InvoicePdfAccessService::class)->query($invoiceB, $userB);

        $this->actingAs($userA)
            ->withSession(['current_workspace_id' => $workspaceA->id])
            ->get(route('workspace.finance.invoices.pdf', ['invoice' => $invoiceB] + $queryB))
            ->assertNotFound();

        $this->actingAs($userA)
            ->withSession(['current_workspace_id' => $workspaceA->id])
            ->get(route('workspace.finance.invoices.pdf', ['invoice' => $invoiceB] + $queryA))
            ->assertNotFound();
    }

    public function test_api_pdf_requires_grant_issued_for_that_invoice_and_user(): void
    {
        [$user, $workspace, $first] = $this->issuedSalesInvoice('Api First');
        $second = $this->issueSales($workspace, $this->makeBuyer($workspace, 'Api Second'), (int) $user->id);

        Sanctum::actingAs($user);
        $headers = ['X-Workspace-Id' => (string) $workspace->id];

        $detail = $this->withHeaders($headers)
            ->getJson('/api/finance/v1/sales-invoices/'.$first->id)
            ->assertOk();

        $grant = $detail->json('data.pdf_download');
        $this->assertIsArray($grant);
        $this->assertNotEmpty($grant['token'] ?? null);
        $this->assertNotEmpty($grant['file_name'] ?? null);
        $this->assertStringNotContainsString((string) $first->invoice_number, (string) $grant['file_name']);

        $authorized = $this->withHeaders($headers)
            ->get('/api/finance/v1/sales-invoices/'.$first->id.'/pdf?'.http_build_query([
                'download_token' => $grant['token'],
                'expires' => $grant['expires'],
            ]));
        $authorized->assertOk();
        $this->assertStringContainsString('application/pdf', (string) $authorized->headers->get('content-type'));

        $this->withHeaders($headers)
            ->get('/api/finance/v1/sales-invoices/'.$first->id.'/pdf')
            ->assertNotFound();

        $this->withHeaders($headers)
            ->get('/api/finance/v1/sales-invoices/'.$second->id.'/pdf?'.http_build_query([
                'download_token' => $grant['token'],
                'expires' => $grant['expires'],
            ]))
            ->assertNotFound();
    }

    public function test_invoice_attachments_and_emailed_pdfs_are_not_publicly_downloadable(): void
    {
        [$user, $workspace, $invoice] = $this->issuedSalesInvoice('Attachment Customer', draft: true);

        $this->actingAs($user)
            ->withSession(['current_workspace_id' => $workspace->id])
            ->post(route('workspace.finance.invoices.attachments.store', $invoice), [
                'attachments' => [UploadedFile::fake()->create('scan.pdf', 20, 'application/pdf')],
            ])
            ->assertRedirect();

        app(WorkspaceContext::class)->set($workspace);
        $attachment = FinanceInvoiceAttachment::withoutGlobalScopes()
            ->where('invoice_id', $invoice->id)
            ->firstOrFail();
        $this->assertTrue(Storage::disk(PrivateDocumentDisk::DISK)->exists($attachment->file_path));
        $this->assertFalse(Storage::disk(PrivateDocumentDisk::LEGACY_DISK)->exists($attachment->file_path));
        $publicGuess = $this->get('/storage/'.$attachment->file_path);
        $this->assertContains($publicGuess->status(), [403, 404]);
        $this->assertStringNotContainsString('%PDF', (string) $publicGuess->getContent());

        $this->actingAs($user)
            ->withSession(['current_workspace_id' => $workspace->id])
            ->get(route('workspace.finance.invoices.attachments.download', [$invoice, $attachment]))
            ->assertOk();
    }

    /**
     * @return array{0: User, 1: Workspace, 2: FinanceInvoice}
     */
    private function issuedSalesInvoice(string $customerName = 'PDF Customer', bool $draft = false): array
    {
        [$user, $workspace] = $this->createWorkspaceOwner();
        $customer = $this->makeBuyer($workspace, $customerName);
        $invoice = $this->issueSales(
            $workspace,
            $customer,
            (int) $user->id,
            $draft ? 'draft' : 'issued',
        );

        return [$user, $workspace, $invoice];
    }

    /**
     * @return array{0: User, 1: Workspace}
     */
    private function createWorkspaceOwner(): array
    {
        $user = User::factory()->create();
        $workspace = Workspace::factory()->create([
            'owner_user_id' => $user->id,
            'type' => 'company',
            'name' => 'Invoice PDF Workspace',
        ]);
        $workspace->users()->attach($user->id, [
            'membership_role' => 'owner',
            'status' => 'active',
            'joined_at' => now(),
        ]);
        app(WorkspaceContext::class)->set($workspace);

        foreach (['finance', 'products', 'orders', 'customers'] as $feature) {
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
        return Customer::withoutGlobalScopes()->create([
            'workspace_id' => $workspace->id,
            'name' => $name,
            'phone' => '05'.random_int(10000000, 99999999),
            'email' => strtolower(str_replace(' ', '.', $name)).uniqid().'@example.com',
        ]);
    }

    private function issueSales(Workspace $workspace, Customer $customer, int $actorId, string $status = 'issued'): FinanceInvoice
    {
        app(WorkspaceContext::class)->set($workspace);

        return app(InvoiceService::class)->create($workspace, [
            'type' => 'sales',
            'customer_id' => $customer->id,
            'issue_date' => now()->toDateString(),
            'due_date' => now()->addDays(10)->toDateString(),
            'currency' => 'SAR',
            'invoice_status' => $status,
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
        ], $actorId);
    }
}
