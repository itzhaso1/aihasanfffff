<?php

namespace Tests\Feature\Feature\Finance;

use App\Models\AuditLog;
use App\Models\Contract\Contract;
use App\Models\Contract\ContractAttachment;
use App\Models\Customer;
use App\Models\Finance\FinanceBillingSchedule;
use App\Models\Finance\FinanceInvoice;
use App\Models\Plan;
use App\Models\Subscription;
use App\Models\User;
use App\Models\Workspace;
use App\Models\WorkspaceFeatureFlag;
use App\Notifications\ContractLifecycleNotification;
use App\Services\Contracts\ContractBalanceService;
use App\Services\Contracts\ContractService;
use App\Services\Finance\BillingScheduleService;
use App\Services\Finance\FinanceBootstrapService;
use App\Services\Finance\InvoicePaymentService;
use App\Services\Finance\InvoiceService;
use App\Support\Tenancy\WorkspaceContext;
use Database\Seeders\FoundationSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Storage;
use RuntimeException;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

class ContractLifecycleTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Notification::fake();
        $this->seed(FoundationSeeder::class);
    }

    public function test_create_contract_assigns_unique_number_and_tax_aware_totals(): void
    {
        [$owner, $workspace] = $this->createWorkspaceOwner();
        $customer = $this->makeCustomer($workspace, 'Acme');

        $contract = $this->inWorkspace($workspace, function () use ($workspace, $owner, $customer): Contract {
            return app(ContractService::class)->create($workspace, [
                'title' => 'عقد تطوير',
                'customer_id' => $customer->id,
                'currency' => 'SAR',
                'start_date' => now()->toDateString(),
                'end_date' => now()->addYear()->toDateString(),
                'items' => [[
                    'title' => 'خدمة تطوير',
                    'item_kind' => 'service',
                    'quantity' => 1,
                    'unit_price' => 5000,
                    'discount' => 0,
                    'tax_rate' => 15,
                    'tax_type' => 'standard',
                ]],
            ], (int) $owner->id);
        });

        $this->assertSame('draft', $contract->status);
        $this->assertMatchesRegularExpression('/^CTR-\d{6}$/', $contract->contract_number);
        $this->assertSame('5750.00', number_format((float) $contract->value, 2, '.', ''));
        $this->assertSame('5750.00', number_format((float) $contract->items->first()->total, 2, '.', ''));
        $this->assertTrue(
            AuditLog::withoutGlobalScopes()
                ->where('action', 'contract_created')
                ->where('entity_id', $contract->id)
                ->exists()
        );
    }

    public function test_draft_contract_can_be_updated_before_activation(): void
    {
        [$owner, $workspace] = $this->createWorkspaceOwner();
        $contract = $this->makeDraftContract($workspace, $owner);

        $updated = $this->inWorkspace($workspace, function () use ($contract): Contract {
            return app(ContractService::class)->update($contract, [
                'title' => 'عقد محدّث',
                'value' => 100,
                'items' => [['title' => 'بند', 'quantity' => 2, 'unit_price' => 100, 'tax_rate' => 0]],
            ]);
        });

        $this->assertSame('عقد محدّث', $updated->title);
        $this->assertSame('200.00', number_format((float) $updated->value, 2, '.', ''));
    }

    public function test_activate_cancel_expire_and_renew_lifecycle(): void
    {
        [$owner, $workspace] = $this->createWorkspaceOwner();
        $contract = $this->makeDraftContract($workspace, $owner, [
            'end_date' => now()->addMonth()->toDateString(),
        ]);

        $activated = $this->inWorkspace($workspace, fn () => app(ContractService::class)->activate($contract, (int) $owner->id));
        $this->assertSame('open', $activated->status);
        $this->assertNotNull($activated->activated_at);

        $expired = $this->inWorkspace($workspace, fn () => app(ContractService::class)->expire($activated));
        $this->assertSame('expired', $expired->status);

        $renewal = $this->inWorkspace($workspace, function () use ($expired, $owner): Contract {
            return app(ContractService::class)->renew($expired, [
                'start_date' => now()->toDateString(),
                'end_date' => now()->addYear()->toDateString(),
                'value' => 400,
                'copy_schedules' => false,
            ], (int) $owner->id);
        });

        $this->assertSame($expired->id, (int) $renewal->parent_contract_id);
        $this->assertSame('draft', $renewal->status);
        $this->assertSame('renewed', $expired->fresh()->status);
        $this->assertSame($expired->end_date?->toDateString(), $expired->fresh()->end_date?->toDateString());
        $this->assertNotSame($expired->contract_number, $renewal->contract_number);
    }

    public function test_cancel_blocks_new_invoices_and_delete_with_history_is_rejected(): void
    {
        [$owner, $workspace] = $this->createWorkspaceOwner();
        [$contract, $schedule] = $this->openContractWithSchedule($workspace, $owner, 200, 1);

        $this->inWorkspace($workspace, function () use ($contract, $schedule, $owner): void {
            app(BillingScheduleService::class)->createInvoiceFromSchedule($schedule, (int) $owner->id, true);
            app(ContractService::class)->cancel($contract->fresh());
        });

        $this->assertSame('cancelled', $contract->fresh()->status);
        $this->assertNull($this->inWorkspace($workspace, fn () => app(BillingScheduleService::class)->generateOne($schedule->fresh())));

        $this->expectException(RuntimeException::class);
        $this->inWorkspace($workspace, fn () => app(ContractService::class)->delete($contract->fresh()));
    }

    public function test_payment_schedule_cannot_overrun_contract_value(): void
    {
        [$owner, $workspace] = $this->createWorkspaceOwner();
        [$contract] = $this->openContractWithSchedule($workspace, $owner, 1000, 1);

        $this->expectException(RuntimeException::class);
        $this->inWorkspace($workspace, function () use ($contract, $owner): void {
            app(BillingScheduleService::class)->createFromContract($contract, [
                'title' => 'دفعة زائدة',
                'frequency' => 'installment',
                'total_occurrences' => 1,
                'amount' => 1,
                'start_date' => now()->toDateString(),
            ], (int) $owner->id);
        });
    }

    public function test_invoice_from_schedule_is_idempotent_and_recalculates_totals(): void
    {
        [$owner, $workspace] = $this->createWorkspaceOwner();
        [$contract, $schedule] = $this->openContractWithSchedule($workspace, $owner, 1150, 1);

        $invoice = $this->inWorkspace($workspace, function () use ($schedule, $owner) {
            return app(BillingScheduleService::class)->createInvoiceFromSchedule($schedule, (int) $owner->id, true);
        });

        $this->assertSame('draft', $invoice->invoice_status);
        $this->assertSame($contract->id, (int) $invoice->contract_id);
        $this->assertSame($schedule->id, (int) $invoice->billing_schedule_id);
        $this->assertSame($contract->customer_id, (int) $invoice->customer_id);
        $this->assertGreaterThan(0, (float) $invoice->total);

        $first = $this->inWorkspace($workspace, fn () => app(BillingScheduleService::class)->generateDueInvoices((int) $workspace->id));
        $second = $this->inWorkspace($workspace, fn () => app(BillingScheduleService::class)->generateDueInvoices((int) $workspace->id));
        $this->assertContains($first, [0, 1]);
        $this->assertSame(0, $second);
        $this->assertSame(1, FinanceInvoice::withoutGlobalScopes()->where('billing_schedule_id', $schedule->id)->count());
    }

    public function test_partial_and_full_payment_update_contract_balances(): void
    {
        [$owner, $workspace] = $this->createWorkspaceOwner();
        [$contract, $schedule] = $this->openContractWithSchedule($workspace, $owner, 200, 1);

        $summary = $this->inWorkspace($workspace, function () use ($contract, $schedule, $owner) {
            $invoice = app(BillingScheduleService::class)->createInvoiceFromSchedule($schedule, (int) $owner->id, true);
            app(InvoiceService::class)->issue($invoice, (int) $owner->id);
            app(InvoicePaymentService::class)->recordPayment($invoice->fresh(), [
                'amount' => 50,
                'method' => 'cash',
                'payment_date' => now()->toDateString(),
            ], (int) $owner->id);

            $partial = app(ContractBalanceService::class)->summarize($contract->fresh()->load(['invoices.payments']));
            $this->assertSame(50.0, $partial['paid']);
            $this->assertGreaterThan(0, $partial['outstanding']);

            app(InvoicePaymentService::class)->recordPayment($invoice->fresh(), [
                'amount' => (float) $invoice->fresh()->amount_due,
                'method' => 'cash',
                'payment_date' => now()->toDateString(),
            ], (int) $owner->id);

            return app(ContractBalanceService::class)->summarize($contract->fresh()->load(['invoices.payments']));
        });

        $this->assertEquals(0, $summary['outstanding']);
        $this->assertEquals($summary['invoiced'], $summary['paid']);
        $this->assertEquals(0, $summary['unbilled']);
    }

    public function test_expiry_job_notifies_and_blocks_billing_without_rewriting_dates(): void
    {
        [$owner, $workspace] = $this->createWorkspaceOwner();
        $endDate = now()->subDay()->toDateString();
        [$contract] = $this->openContractWithSchedule($workspace, $owner, 300, 1, $endDate);

        $this->inWorkspace($workspace, function () use ($workspace): void {
            app(ContractService::class)->processLifecycle((int) $workspace->id);
        });

        $fresh = $contract->fresh();
        $this->assertSame('expired', $fresh->status);
        $this->assertSame($endDate, $fresh->end_date?->toDateString());
        Notification::assertSentTo($owner, ContractLifecycleNotification::class);
    }

    public function test_ending_soon_notification_is_sent_once(): void
    {
        [$owner, $workspace] = $this->createWorkspaceOwner();
        [$contract] = $this->openContractWithSchedule($workspace, $owner, 300, 1, now()->addDays(7)->toDateString());

        $this->inWorkspace($workspace, function () use ($workspace): void {
            $service = app(ContractService::class);
            $first = $service->processLifecycle((int) $workspace->id);
            $second = $service->processLifecycle((int) $workspace->id);
            $this->assertSame(1, $first['ending_soon']);
            $this->assertSame(0, $second['ending_soon']);
        });

        $this->assertNotNull($contract->fresh()->ending_soon_notified_at);
    }

    public function test_staff_without_manage_permission_cannot_activate(): void
    {
        [$owner, $workspace] = $this->createWorkspaceOwner();
        $contract = $this->makeDraftContract($workspace, $owner);
        $staff = $this->attachStaff($workspace, ['contracts.view', 'finance.view']);

        $this->actingAs($staff)->withSession(['current_workspace_id' => $workspace->id])
            ->post(route('workspace.finance.contracts.activate', $contract))
            ->assertForbidden();
    }

    public function test_cross_workspace_contract_access_is_not_found(): void
    {
        [$owner, $workspace] = $this->createWorkspaceOwner();
        [$stranger] = $this->createWorkspaceOwner();
        $contract = $this->makeDraftContract($workspace, $owner);

        $this->actingAs($stranger)->withSession(['current_workspace_id' => $this->otherWorkspaceId($stranger)])
            ->get(route('workspace.finance.contracts.show', $contract))
            ->assertNotFound();
    }

    public function test_attachment_download_is_workspace_scoped_and_private(): void
    {
        Storage::fake('local');
        [$owner, $workspace] = $this->createWorkspaceOwner();
        [$stranger] = $this->createWorkspaceOwner();
        $contract = $this->makeDraftContract($workspace, $owner);

        $this->inWorkspace($workspace, function () use ($contract, $owner): void {
            app(ContractService::class)->storeAttachments($contract, [
                UploadedFile::fake()->create('contract.pdf', 20, 'application/pdf'),
            ], (int) $owner->id);
        });

        $attachment = ContractAttachment::withoutGlobalScopes()->where('contract_id', $contract->id)->firstOrFail();
        $this->assertSame('local', $attachment->disk);

        $this->actingAs($owner)->withSession(['current_workspace_id' => $workspace->id])
            ->get(route('workspace.finance.contracts.attachments.download', [$contract, $attachment]))
            ->assertOk();

        $this->actingAs($stranger)->withSession(['current_workspace_id' => $this->otherWorkspaceId($stranger)])
            ->get(route('workspace.finance.contracts.attachments.download', [$contract, $attachment]))
            ->assertNotFound();
    }

    public function test_concurrent_numbering_does_not_collide(): void
    {
        [$owner, $workspace] = $this->createWorkspaceOwner();

        $first = $this->inWorkspace($workspace, fn () => $this->makeDraftContract($workspace, $owner, ['title' => 'A']));
        $second = $this->inWorkspace($workspace, fn () => $this->makeDraftContract($workspace, $owner, ['title' => 'B']));

        $this->assertNotSame($first->contract_number, $second->contract_number);
    }

    /**
     * @return array{0: User, 1: Workspace}
     */
    private function createWorkspaceOwner(): array
    {
        app(WorkspaceContext::class)->clear();
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
        foreach (['finance', 'products', 'orders', 'customers'] as $feature) {
            WorkspaceFeatureFlag::withoutGlobalScopes()->updateOrCreate(
                ['workspace_id' => $workspace->id, 'feature_key' => $feature],
                ['workspace_id' => $workspace->id, 'feature_key' => $feature, 'enabled' => true, 'source' => 'manual']
            );
        }
        $plan = Plan::query()->where('is_active', true)->first();
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
        $this->inWorkspace($workspace, function () use ($workspace): void {
            app(PermissionRegistrar::class)->setPermissionsTeamId($workspace->id);
            app(FinanceBootstrapService::class)->ensureWorkspaceFinanceSetup($workspace);
        });
        app(WorkspaceContext::class)->clear();

        return [$user, $workspace];
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

    private function makeCustomer(Workspace $workspace, string $name): Customer
    {
        return Customer::withoutGlobalScopes()->create([
            'workspace_id' => $workspace->id,
            'name' => $name,
            'phone' => '05'.random_int(10000000, 99999999),
            'email' => strtolower(str_replace(' ', '.', $name)).'@example.com',
        ]);
    }

    /**
     * @param  array<string, mixed>  $overrides
     */
    private function makeDraftContract(Workspace $workspace, User $owner, array $overrides = []): Contract
    {
        $customer = $this->makeCustomer($workspace, 'Customer '.uniqid());

        return $this->inWorkspace($workspace, function () use ($workspace, $owner, $customer, $overrides): Contract {
            return app(ContractService::class)->create($workspace, array_merge([
                'title' => $overrides['title'] ?? 'عقد خدمات',
                'customer_id' => $customer->id,
                'currency' => 'SAR',
                'start_date' => now()->toDateString(),
                'items' => [[
                    'title' => 'خدمة',
                    'quantity' => 1,
                    'unit_price' => 200,
                    'tax_rate' => 0,
                ]],
            ], $overrides), (int) $owner->id);
        });
    }

    /**
     * @return array{0: Contract, 1: FinanceBillingSchedule}
     */
    private function openContractWithSchedule(Workspace $workspace, User $owner, float $value = 230, int $occurrences = 2, ?string $endDate = null): array
    {
        $contract = $this->makeDraftContract($workspace, $owner, [
            'items' => [['title' => 'خدمة شهرية', 'quantity' => 1, 'unit_price' => $value, 'tax_rate' => 0]],
            'end_date' => $endDate,
        ]);
        $contract = $this->inWorkspace($workspace, fn () => app(ContractService::class)->activate($contract, (int) $owner->id));
        $schedule = $this->inWorkspace($workspace, function () use ($contract, $owner, $occurrences) {
            return app(BillingScheduleService::class)->createFromContract($contract, [
                'title' => 'قسط',
                'frequency' => 'installment',
                'total_occurrences' => $occurrences,
                'start_date' => now()->toDateString(),
                'status' => FinanceBillingSchedule::STATUS_ACTIVE,
            ], (int) $owner->id);
        });

        return [$contract, $schedule];
    }

    private function otherWorkspaceId(User $user): int
    {
        return (int) Workspace::withoutGlobalScopes()->where('owner_user_id', $user->id)->value('id');
    }
}
