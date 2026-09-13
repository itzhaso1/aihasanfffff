<?php

namespace Tests\Feature\Feature\Finance;

use App\Models\Customer;
use App\Models\Finance\FinanceInvoice;
use App\Models\Finance\FinanceJournalEntry;
use App\Models\Finance\FinanceSetting;
use App\Models\Finance\IssuedDocumentSnapshot;
use App\Models\Plan;
use App\Models\Product;
use App\Models\Subscription;
use App\Models\User;
use App\Models\Workspace;
use App\Models\WorkspaceFeatureFlag;
use App\Services\Finance\CreditNoteService;
use App\Services\Finance\FinanceBootstrapService;
use App\Services\Finance\InvoiceService;
use App\Services\Finance\IssuedSnapshotBuilder;
use App\Support\Tenancy\WorkspaceContext;
use Database\Seeders\FoundationSeeder;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use RuntimeException;
use Tests\TestCase;

class Phase2IssuedSnapshotTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Notification::fake();
        $this->seed(FoundationSeeder::class);
    }

    public function test_issuing_finance_invoice_creates_exactly_one_snapshot(): void
    {
        [$owner, $workspace] = $this->createWorkspaceOwner();
        $customer = $this->makeCustomer($workspace, 'Snapshot Buyer', [
            'vat_number' => '300111111111113',
            'address' => 'Riyadh',
            'phone' => '0500000001',
            'email' => 'buyer@example.com',
        ]);
        $this->updateCompany($workspace, [
            'company_name' => 'Issued Co',
            'vat_number' => '310000000000003',
            'city' => 'Jeddah',
        ]);

        $invoice = $this->issueInvoice($workspace, $customer, (int) $owner->id);

        $snapshots = IssuedDocumentSnapshot::withoutGlobalScopes()
            ->where('source_type', IssuedDocumentSnapshot::SOURCE_FINANCE_INVOICE)
            ->where('source_id', $invoice->id)
            ->get();
        $this->assertCount(1, $snapshots);

        $snapshot = $snapshots->first();
        $this->assertSame($workspace->id, (int) $snapshot->workspace_id);
        $this->assertSame($invoice->invoice_number, $snapshot->document_number);
        $this->assertSame('SAR', $snapshot->currency);
        $this->assertSame('Issued Co', data_get($snapshot->payload, 'seller.company_name'));
        $this->assertSame('310000000000003', data_get($snapshot->payload, 'seller.vat_number'));
        $this->assertSame('Snapshot Buyer', data_get($snapshot->payload, 'buyer.name'));
        $this->assertSame('300111111111113', data_get($snapshot->payload, 'buyer.vat_number'));
        $this->assertCount(1, data_get($snapshot->payload, 'lines'));
        $this->assertSame('خدمة فوترة', data_get($snapshot->payload, 'lines.0.product_name'));
        $this->assertSame('15.00', data_get($snapshot->payload, 'tax.amount'));
        $this->assertSame('15.00', data_get($snapshot->payload, 'tax.rate'));
        $this->assertSame('100.00', data_get($snapshot->payload, 'totals.subtotal'));
        $this->assertSame('115.00', data_get($snapshot->payload, 'totals.total'));
        $this->assertSame('issued', data_get($snapshot->payload, 'document.status'));
        $this->assertSame($invoice->invoice_number, data_get($snapshot->payload, 'document.number'));
        $this->assertNotEmpty(data_get($snapshot->payload, 'tax.breakdown'));
    }

    public function test_customer_change_after_issue_does_not_change_snapshot(): void
    {
        [$owner, $workspace] = $this->createWorkspaceOwner();
        $customer = $this->makeCustomer($workspace, 'Frozen Buyer', ['vat_number' => '300222222222223']);
        $invoice = $this->issueInvoice($workspace, $customer, (int) $owner->id);
        $snapshot = $this->invoiceSnapshot($invoice);

        $customer->update(['name' => 'Changed Buyer', 'vat_number' => '399999999999993']);

        $fresh = $snapshot->fresh();
        $this->assertSame('Frozen Buyer', data_get($fresh->payload, 'buyer.name'));
        $this->assertSame('300222222222223', data_get($fresh->payload, 'buyer.vat_number'));
        $this->assertSame($snapshot->payload, $fresh->payload);
    }

    public function test_issued_invoice_mutation_is_rejected_and_snapshot_stays_frozen(): void
    {
        [$owner, $workspace] = $this->createWorkspaceOwner();
        $customer = $this->makeCustomer($workspace, 'Locked Buyer');
        $invoice = $this->issueInvoice($workspace, $customer, (int) $owner->id);
        $before = $this->invoiceSnapshot($invoice)->payload;

        try {
            $invoice->update(['subtotal' => 1, 'tax_amount' => 1, 'total' => 1]);
            $this->fail('Issued invoice accepted a forbidden financial update.');
        } catch (RuntimeException) {
            // expected
        }

        $this->assertSame($before, $this->invoiceSnapshot($invoice->fresh())->payload);
        $this->assertSame('115.00', data_get($before, 'totals.total'));
    }

    public function test_product_and_company_changes_after_issue_do_not_change_snapshot(): void
    {
        [$owner, $workspace] = $this->createWorkspaceOwner();
        $customer = $this->makeCustomer($workspace, 'Product Buyer');
        $product = Product::withoutGlobalScopes()->create([
            'workspace_id' => $workspace->id,
            'name' => 'Original Product',
            'slug' => 'original-product',
            'sku' => 'ORIG-1',
            'price' => 100,
            'currency' => 'SAR',
            'status' => 'active',
            'inventory_tracking' => false,
            'stock' => 0,
        ]);
        $this->updateCompany($workspace, ['company_name' => 'Original Co', 'vat_number' => '310000000000003']);

        $invoice = app(InvoiceService::class)->create($workspace, [
            'type' => 'sales',
            'customer_id' => $customer->id,
            'issue_date' => now()->toDateString(),
            'currency' => 'SAR',
            'invoice_status' => 'issued',
            'tax_profile_type' => 'standard',
            'tax_rate' => 15,
            'skip_inventory' => true,
            'items' => [[
                'product_id' => $product->id,
                'product_name' => 'Original Product',
                'quantity' => 1,
                'unit_price' => 100,
                'discount' => 0,
                'tax_rate' => 15,
                'tax_type' => 'standard',
            ]],
        ], (int) $owner->id);

        $snapshot = $this->invoiceSnapshot($invoice);
        $product->update(['name' => 'Renamed Product', 'price' => 9]);
        $this->updateCompany($workspace, ['company_name' => 'Tomorrow Co', 'vat_number' => '320000000000003']);

        $fresh = $snapshot->fresh();
        $this->assertSame('Original Product', data_get($fresh->payload, 'lines.0.product_name'));
        $this->assertSame('Original Co', data_get($fresh->payload, 'seller.company_name'));
        $this->assertSame('310000000000003', data_get($fresh->payload, 'seller.vat_number'));
    }

    public function test_snapshot_capture_is_idempotent(): void
    {
        [$owner, $workspace] = $this->createWorkspaceOwner();
        $customer = $this->makeCustomer($workspace, 'Idempotent Buyer');
        $invoice = $this->issueInvoice($workspace, $customer, (int) $owner->id);

        $first = app(IssuedSnapshotBuilder::class)->captureInvoice($invoice->fresh(['items', 'contract']));
        $second = app(IssuedSnapshotBuilder::class)->captureInvoice($invoice->fresh(['items', 'contract']));

        $this->assertSame($first->id, $second->id);
        $this->assertSame(1, IssuedDocumentSnapshot::withoutGlobalScopes()->where('source_id', $invoice->id)->count());
    }

    public function test_database_uniqueness_prevents_duplicate_source_snapshots(): void
    {
        [$owner, $workspace] = $this->createWorkspaceOwner();
        $customer = $this->makeCustomer($workspace, 'Unique Buyer');
        $invoice = $this->issueInvoice($workspace, $customer, (int) $owner->id);
        $existing = $this->invoiceSnapshot($invoice);

        $this->expectException(UniqueConstraintViolationException::class);
        IssuedDocumentSnapshot::withoutGlobalScopes()->create([
            'workspace_id' => $workspace->id,
            'source_type' => IssuedDocumentSnapshot::SOURCE_FINANCE_INVOICE,
            'source_id' => $invoice->id,
            'document_number' => $existing->document_number.'-DUP',
            'issue_date' => now()->toDateString(),
            'currency' => 'SAR',
            'payload' => ['document' => ['number' => 'dup']],
        ]);
    }

    public function test_credit_and_debit_notes_create_correct_snapshots(): void
    {
        [$owner, $workspace] = $this->createWorkspaceOwner();
        $customer = $this->makeCustomer($workspace, 'Note Buyer');
        $invoice = $this->issueInvoice($workspace, $customer, (int) $owner->id);

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

        $debit = app(CreditNoteService::class)->create($workspace, $invoice->fresh(), [
            'type' => 'debit',
            'reason' => 'رسوم إضافية',
            'issue_date' => now()->toDateString(),
            'status' => 'issued',
            'items' => [[
                'product_name' => 'رسوم',
                'quantity' => 1,
                'unit_price' => 10,
                'discount' => 0,
                'tax_rate' => 15,
            ]],
        ], (int) $owner->id);

        $creditSnapshot = IssuedDocumentSnapshot::withoutGlobalScopes()
            ->where('source_type', IssuedDocumentSnapshot::SOURCE_FINANCE_CREDIT_NOTE)
            ->where('source_id', $credit->id)
            ->firstOrFail();
        $debitSnapshot = IssuedDocumentSnapshot::withoutGlobalScopes()
            ->where('source_type', IssuedDocumentSnapshot::SOURCE_FINANCE_DEBIT_NOTE)
            ->where('source_id', $debit->id)
            ->firstOrFail();

        $this->assertSame('خصم تجاري', data_get($creditSnapshot->payload, 'document.reason'));
        $this->assertSame('credit', data_get($creditSnapshot->payload, 'document.type'));
        $this->assertSame($invoice->id, data_get($creditSnapshot->payload, 'reference.invoice_id'));
        $this->assertSame($invoice->invoice_number, data_get($creditSnapshot->payload, 'reference.invoice_number'));
        $this->assertSame('3.00', data_get($creditSnapshot->payload, 'tax.amount'));
        $this->assertSame('23.00', data_get($creditSnapshot->payload, 'totals.total'));

        $this->assertSame('debit', data_get($debitSnapshot->payload, 'document.type'));
        $this->assertSame($invoice->invoice_number, data_get($debitSnapshot->payload, 'reference.invoice_number'));
        $this->assertSame('1.50', data_get($debitSnapshot->payload, 'tax.amount'));
    }

    public function test_cross_workspace_snapshot_creation_is_rejected(): void
    {
        [$ownerA, $workspaceA] = $this->createWorkspaceOwner();
        $customer = $this->makeCustomer($workspaceA, 'Workspace A Buyer');
        $invoice = $this->issueInvoice($workspaceA, $customer, (int) $ownerA->id);
        $this->createWorkspaceOwner();

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Cross-workspace snapshot creation is not allowed.');
        app(IssuedSnapshotBuilder::class)->captureInvoice(
            FinanceInvoice::withoutGlobalScopes()->with('items')->findOrFail($invoice->id)
        );
    }

    public function test_snapshot_failure_rolls_back_issue(): void
    {
        [$owner, $workspace] = $this->createWorkspaceOwner();
        $customer = $this->makeCustomer($workspace, 'Failure Buyer');
        $draft = app(InvoiceService::class)->create($workspace, $this->invoicePayload($customer->id, 'draft'), (int) $owner->id);

        $this->app->bind(IssuedSnapshotBuilder::class, fn () => new class extends IssuedSnapshotBuilder
        {
            public function captureInvoice(FinanceInvoice $invoice): IssuedDocumentSnapshot
            {
                throw new RuntimeException('snapshot persist failed');
            }
        });

        try {
            app(InvoiceService::class)->issue($draft, (int) $owner->id);
            $this->fail('Issue succeeded despite snapshot failure.');
        } catch (RuntimeException $exception) {
            $this->assertSame('snapshot persist failed', $exception->getMessage());
        }

        $this->assertSame('draft', $draft->fresh()->invoice_status);
        $this->assertSame(0, IssuedDocumentSnapshot::withoutGlobalScopes()->count());
        $this->assertSame(0, FinanceJournalEntry::withoutGlobalScopes()
            ->where('reference_type', FinanceInvoice::class)
            ->where('reference_id', $draft->id)
            ->count());
    }

    public function test_snapshot_model_rejects_update_and_delete(): void
    {
        [$owner, $workspace] = $this->createWorkspaceOwner();
        $customer = $this->makeCustomer($workspace, 'Immutable Buyer');
        $invoice = $this->issueInvoice($workspace, $customer, (int) $owner->id);
        $snapshot = $this->invoiceSnapshot($invoice);

        try {
            $snapshot->update(['document_number' => 'FORGED']);
            $this->fail('Snapshot accepted an update.');
        } catch (RuntimeException $exception) {
            $this->assertStringContainsString('immutable', $exception->getMessage());
        }

        try {
            $snapshot->delete();
            $this->fail('Snapshot accepted a delete.');
        } catch (RuntimeException $exception) {
            $this->assertStringContainsString('immutable', $exception->getMessage());
        }

        $this->assertSame($invoice->invoice_number, $snapshot->fresh()->document_number);
        $this->assertSame(1, IssuedDocumentSnapshot::withoutGlobalScopes()->count());
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

        app(WorkspaceContext::class)->set($workspace);
        app(FinanceBootstrapService::class)->ensureWorkspaceFinanceSetup($workspace);

        return [$user, $workspace];
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
     * @param  array<string, mixed>  $attributes
     */
    private function updateCompany(Workspace $workspace, array $attributes): void
    {
        FinanceSetting::withoutGlobalScopes()
            ->where('workspace_id', $workspace->id)
            ->update($attributes);
    }

    /**
     * @return array<string, mixed>
     */
    private function invoicePayload(int $customerId, string $status = 'issued'): array
    {
        return [
            'type' => 'sales',
            'customer_id' => $customerId,
            'issue_date' => now()->toDateString(),
            'due_date' => now()->addDays(10)->toDateString(),
            'currency' => 'SAR',
            'invoice_status' => $status,
            'tax_profile_type' => 'standard',
            'tax_rate' => 15,
            'items' => [[
                'product_name' => 'خدمة فوترة',
                'quantity' => 1,
                'unit_price' => 100,
                'discount' => 0,
                'tax_rate' => 15,
                'tax_type' => 'standard',
            ]],
        ];
    }

    private function issueInvoice(Workspace $workspace, Customer $customer, int $actorId): FinanceInvoice
    {
        return app(InvoiceService::class)->create($workspace, $this->invoicePayload($customer->id), $actorId);
    }

    private function invoiceSnapshot(FinanceInvoice $invoice): IssuedDocumentSnapshot
    {
        return IssuedDocumentSnapshot::withoutGlobalScopes()
            ->where('source_type', IssuedDocumentSnapshot::SOURCE_FINANCE_INVOICE)
            ->where('source_id', $invoice->id)
            ->firstOrFail();
    }
}
