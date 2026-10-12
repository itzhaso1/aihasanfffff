<?php

declare(strict_types=1);

namespace Tests\Feature\Worker;

use App\Enums\WorkerEngagementStatus;
use App\Models\ChatCatalogItem;
use App\Models\PlatformAdmin;
use App\Models\User;
use App\Models\Worker\WorkerContractRate;
use App\Models\Worker\WorkerEngagement;
use App\Models\Worker\WorkerProfile;
use App\Models\Workspace;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class WorkerContractPricingTest extends TestCase
{
    use RefreshDatabase;

    protected bool $bypassIndependentWorkerWebBoundary = true;

    public function test_platform_admin_can_create_a_worker_contract_rate(): void
    {
        $admin = PlatformAdmin::factory()->create();
        [, $profile] = $this->createVerifiedWorker();

        $this->actingAs($admin, 'platform_admin')
            ->post(route('platform.worker-contract-rates.store'), [
                'worker_profile_id' => $profile->id,
                'duration_value' => 1,
                'duration_unit' => 'week',
                'amount' => '600.00',
                'currency' => 'sar',
            ])
            ->assertRedirect();

        $this->assertDatabaseHas('worker_contract_rates', [
            'worker_profile_id' => $profile->id,
            'duration_value' => 1,
            'duration_unit' => 'week',
            'amount_minor' => 60000,
            'currency' => 'SAR',
            'is_active' => true,
            'created_by_platform_admin_id' => $admin->id,
        ]);
    }

    public function test_platform_admin_creates_a_future_rate_without_changing_used_amounts(): void
    {
        $admin = PlatformAdmin::factory()->create();
        [, $profile] = $this->createVerifiedWorker();
        $this->actingAs($admin, 'platform_admin')
            ->post(route('platform.worker-contract-rates.store'), [
                'worker_profile_id' => $profile->id,
                'duration_value' => 1,
                'duration_unit' => 'week',
                'amount' => '600.00',
                'currency' => 'SAR',
            ])
            ->assertRedirect();

        $original = WorkerContractRate::query()->firstOrFail();

        $this->actingAs($admin, 'platform_admin')
            ->post(route('platform.worker-contract-rates.store'), [
                'worker_profile_id' => $profile->id,
                'duration_value' => 1,
                'duration_unit' => 'week',
                'amount' => '700.00',
                'currency' => 'SAR',
            ])
            ->assertRedirect();

        $original->refresh();
        $latest = WorkerContractRate::query()->latest('id')->firstOrFail();

        $this->assertFalse($original->is_active);
        $this->assertSame(60000, $original->amount_minor);
        $this->assertTrue($latest->is_active);
        $this->assertSame(70000, $latest->amount_minor);
        $this->assertSame($profile->id, $latest->worker_profile_id);
    }

    public function test_company_cannot_create_a_worker_contract_rate(): void
    {
        [$owner] = $this->createCompany();
        [, $profile] = $this->createVerifiedWorker();

        $this->actingAs($owner)
            ->post(route('platform.worker-contract-rates.store'), [
                'worker_profile_id' => $profile->id,
                'duration_value' => 1,
                'duration_unit' => 'day',
                'amount' => '100.00',
                'currency' => 'SAR',
            ])
            ->assertRedirect(route('platform.login'));

        $this->assertDatabaseCount('worker_contract_rates', 0);
    }

    public function test_worker_cannot_create_a_worker_contract_rate(): void
    {
        [$worker, $profile] = $this->createVerifiedWorker();

        $this->actingAs($worker)
            ->post(route('platform.worker-contract-rates.store'), [
                'worker_profile_id' => $profile->id,
                'duration_value' => 1,
                'duration_unit' => 'day',
                'amount' => '100.00',
                'currency' => 'SAR',
            ])
            ->assertRedirect(route('platform.login'));

        $this->assertDatabaseCount('worker_contract_rates', 0);
    }

    public function test_company_cannot_disable_a_worker_contract_rate(): void
    {
        [$owner] = $this->createCompany();
        [, $profile] = $this->createVerifiedWorker();
        $rate = $this->createRate($profile, 1, 'day', 10000);

        $this->actingAs($owner)
            ->post(route('platform.worker-contract-rates.disable', $rate))
            ->assertRedirect(route('platform.login'));

        $this->assertTrue($rate->fresh()->is_active);
    }

    public function test_worker_cannot_disable_a_worker_contract_rate(): void
    {
        [$worker, $profile] = $this->createVerifiedWorker();
        $rate = $this->createRate($profile, 1, 'day', 10000);

        $this->actingAs($worker)
            ->post(route('platform.worker-contract-rates.disable', $rate))
            ->assertRedirect(route('platform.login'));

        $this->assertTrue($rate->fresh()->is_active);
    }

    #[DataProvider('supportedRates')]
    public function test_offer_creation_snapshots_the_backend_contract_rate(
        int $durationValue,
        string $durationUnit,
        int $amountMinor,
        string $amount,
    ): void {
        [$owner, $workspace] = $this->createCompany();
        [, $profile] = $this->createVerifiedWorker();
        $rate = $this->createRate($profile, $durationValue, $durationUnit, $amountMinor);

        $this->companyRequest($owner, $workspace)
            ->post(route('workspace.worker-engagements.store'), [
                'worker_profile_id' => $profile->id,
                'starts_at' => '2026-10-01',
                'duration_value' => $durationValue,
                'duration_unit' => $durationUnit,
            ])
            ->assertRedirect();

        $engagement = WorkerEngagement::withoutGlobalScopes()
            ->where('worker_profile_id', $profile->id)
            ->firstOrFail();

        $this->assertSame($amountMinor, $engagement->offered_contract_amount_minor);
        $this->assertSame('SAR', $engagement->offered_contract_currency);
        $this->assertSame($rate->id, $engagement->offered_contract_rate_id);
        $this->assertNull($engagement->agreed_contract_amount_minor);
        $this->assertSame($amount, $engagement->contractPriceLabel());
    }

    /**
     * @return array<string, array{int, string, int, string}>
     */
    public static function supportedRates(): array
    {
        return [
            'day' => [1, 'day', 10000, '100.00 SAR'],
            'week' => [1, 'week', 60000, '600.00 SAR'],
            'month' => [1, 'month', 200000, '2000.00 SAR'],
        ];
    }

    public function test_client_cannot_force_a_different_contract_amount(): void
    {
        [$owner, $workspace] = $this->createCompany();
        [, $profile] = $this->createVerifiedWorker();
        $this->createRate($profile, 1, 'week', 60000);

        $this->companyRequest($owner, $workspace)
            ->post(route('workspace.worker-engagements.store'), [
                'worker_profile_id' => $profile->id,
                'starts_at' => '2026-10-01',
                'duration_value' => 1,
                'duration_unit' => 'week',
                'amount' => '1.00',
                'amount_minor' => 100,
                'offered_contract_amount_minor' => 100,
            ])
            ->assertSessionHasErrors([
                'amount',
                'amount_minor',
                'offered_contract_amount_minor',
            ]);

        $this->assertDatabaseCount('worker_engagements', 0);
    }

    public function test_accepting_an_offer_locks_the_offered_snapshot_without_rereading_the_current_rate(): void
    {
        [$owner, $workspace] = $this->createCompany();
        [$worker, $profile] = $this->createVerifiedWorker();
        $this->createRate($profile, 1, 'week', 60000);

        $this->companyRequest($owner, $workspace)
            ->post(route('workspace.worker-engagements.store'), [
                'worker_profile_id' => $profile->id,
                'starts_at' => '2026-10-01',
                'duration_value' => 1,
                'duration_unit' => 'week',
            ])
            ->assertRedirect();

        $engagement = WorkerEngagement::withoutGlobalScopes()->firstOrFail();
        $this->createRate($profile, 1, 'week', 70000);

        $this->actingAs($worker)
            ->post(route('worker.engagements.accept', $engagement->id))
            ->assertRedirect();

        $engagement->refresh();
        $this->assertSame(WorkerEngagementStatus::Accepted, $engagement->status);
        $this->assertSame(60000, $engagement->offered_contract_amount_minor);
        $this->assertSame(60000, $engagement->agreed_contract_amount_minor);
        $this->assertSame('SAR', $engagement->agreed_contract_currency);
        $this->assertNotNull($engagement->agreed_contract_at);
    }

    public function test_changing_the_platform_rate_does_not_change_an_existing_engagement(): void
    {
        [$owner, $workspace] = $this->createCompany();
        [, $profile] = $this->createVerifiedWorker();
        $this->createRate($profile, 1, 'week', 60000);

        $this->companyRequest($owner, $workspace)
            ->post(route('workspace.worker-engagements.store'), [
                'worker_profile_id' => $profile->id,
                'starts_at' => '2026-10-01',
                'duration_value' => 1,
                'duration_unit' => 'week',
            ])
            ->assertRedirect();

        $this->createRate($profile, 1, 'week', 70000);

        $engagement = WorkerEngagement::withoutGlobalScopes()->firstOrFail();
        $this->assertSame(60000, $engagement->fresh()->offered_contract_amount_minor);
    }

    public function test_custom_duration_without_an_exact_rate_is_rejected(): void
    {
        [$owner, $workspace] = $this->createCompany();
        [, $profile] = $this->createVerifiedWorker();
        $this->createRate($profile, 1, 'month', 200000);
        $this->createRate($profile, 1, 'day', 10000);

        $this->companyRequest($owner, $workspace)
            ->post(route('workspace.worker-engagements.store'), [
                'worker_profile_id' => $profile->id,
                'starts_at' => '2026-10-01',
                'duration_value' => 3,
                'duration_unit' => 'month',
            ])
            ->assertSessionHasErrors([
                'engagement' => 'لا يوجد سعر منصة صالح لهذه المدة.',
            ]);

        $this->assertDatabaseCount('worker_engagements', 0);
    }

    public function test_product_prices_do_not_change_the_contract_price(): void
    {
        [$owner, $workspace] = $this->createCompany();
        [, $profile] = $this->createVerifiedWorker();
        $this->createRate($profile, 1, 'day', 10000);
        $product = $this->createProduct($workspace, 'Catalog Item', '45.00');

        $this->companyRequest($owner, $workspace)
            ->post(route('workspace.worker-engagements.store'), [
                'worker_profile_id' => $profile->id,
                'starts_at' => '2026-10-01',
                'duration_value' => 1,
                'duration_unit' => 'day',
                'products' => [[
                    'id' => $product->id,
                    'minimum_selling_price' => '40.00',
                    'maximum_selling_price' => '50.00',
                ]],
            ])
            ->assertRedirect();

        $engagement = WorkerEngagement::withoutGlobalScopes()->firstOrFail();
        $this->assertSame(10000, $engagement->offered_contract_amount_minor);
        $this->assertDatabaseHas('worker_engagement_products', [
            'worker_engagement_id' => $engagement->id,
            'chat_catalog_item_id' => $product->id,
            'minimum_selling_price' => '40.00',
            'maximum_selling_price' => '50.00',
        ]);
        $this->assertDatabaseHas('chat_catalog_items', [
            'id' => $product->id,
            'price' => '45.00',
        ]);
    }

    public function test_workspace_isolation_hides_another_company_engagement(): void
    {
        [$ownerA, $workspaceA] = $this->createCompany('Company A');
        [$ownerB, $workspaceB] = $this->createCompany('Company B');
        [, $profile] = $this->createVerifiedWorker();
        $this->createRate($profile, 1, 'day', 10000);

        $this->companyRequest($ownerA, $workspaceA)
            ->post(route('workspace.worker-engagements.store'), [
                'worker_profile_id' => $profile->id,
                'starts_at' => '2026-10-01',
                'duration_value' => 1,
                'duration_unit' => 'day',
            ])
            ->assertRedirect();

        $engagement = WorkerEngagement::withoutGlobalScopes()->firstOrFail();

        $this->companyRequest($ownerB, $workspaceB)
            ->get(route('workspace.worker-engagements.show', $engagement))
            ->assertNotFound();
    }

    public function test_worker_isolation_hides_another_workers_engagement_and_rate(): void
    {
        [$owner, $workspace] = $this->createCompany();
        [$workerA, $profileA] = $this->createVerifiedWorker();
        [$workerB, $profileB] = $this->createVerifiedWorker();
        $this->createRate($profileA, 1, 'week', 60000);

        $this->companyRequest($owner, $workspace)
            ->post(route('workspace.worker-engagements.store'), [
                'worker_profile_id' => $profileA->id,
                'starts_at' => '2026-10-01',
                'duration_value' => 1,
                'duration_unit' => 'week',
            ])
            ->assertRedirect();

        $engagement = WorkerEngagement::withoutGlobalScopes()->firstOrFail();

        Sanctum::actingAs($workerB);
        $this->getJson(route('mobile.v1.worker.engagements.show', $engagement))
            ->assertNotFound();

        Sanctum::actingAs($workerA);
        $this->getJson(route('mobile.v1.worker.engagements.show', $engagement))
            ->assertOk()
            ->assertJsonPath('data.offered_contract_amount_minor', 60000)
            ->assertJsonPath('data.offered_contract_currency', 'SAR');

        $this->assertDatabaseMissing('worker_contract_rates', [
            'worker_profile_id' => $profileB->id,
        ]);
    }

    public function test_legacy_engagements_without_a_contract_price_remain_valid(): void
    {
        [$owner, $workspace] = $this->createCompany();
        [$worker, $profile] = $this->createVerifiedWorker();

        $this->companyRequest($owner, $workspace)
            ->post(route('workspace.worker-engagements.store'), [
                'worker_profile_id' => $profile->id,
                'job_title' => 'Legacy Offer',
            ])
            ->assertRedirect();

        $engagement = WorkerEngagement::withoutGlobalScopes()->firstOrFail();
        $this->assertNull($engagement->offered_contract_amount_minor);
        $this->assertNull($engagement->offered_contract_currency);

        $this->actingAs($worker)
            ->post(route('worker.engagements.accept', $engagement->id))
            ->assertRedirect();

        $engagement->refresh();
        $this->assertSame(WorkerEngagementStatus::Accepted, $engagement->status);
        $this->assertNull($engagement->agreed_contract_amount_minor);
        $this->assertNull($engagement->contractPriceLabel());
    }

    public function test_company_api_reads_the_backend_rate_and_rejects_a_manual_amount(): void
    {
        [$owner, $workspace] = $this->createCompany();
        [, $profile] = $this->createVerifiedWorker();
        $this->createRate($profile, 1, 'week', 60000);

        Sanctum::actingAs($owner);
        $this->withHeader('X-Workspace-Id', (string) $workspace->id)
            ->getJson('/api/mobile/v1/company/worker-discovery/'.$profile->id.'/contract-price?duration_value=1&duration_unit=week')
            ->assertOk()
            ->assertJsonPath('data.amount_minor', 60000)
            ->assertJsonPath('data.currency', 'SAR');

        $this->withHeader('X-Workspace-Id', (string) $workspace->id)
            ->postJson('/api/mobile/v1/company/worker-discovery/'.$profile->id.'/engagements', [
                'starts_at' => '2026-10-01',
                'duration_value' => 1,
                'duration_unit' => 'week',
                'amount' => '1.00',
            ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('amount');
    }

    public function test_platform_admin_can_disable_an_active_rate(): void
    {
        $admin = PlatformAdmin::factory()->create();
        [, $profile] = $this->createVerifiedWorker();
        $rate = $this->createRate($profile, 1, 'day', 10000);

        $this->actingAs($admin, 'platform_admin')
            ->from(route('platform.worker-contract-rates.index'))
            ->post(route('platform.worker-contract-rates.disable', $rate))
            ->assertRedirect();

        $this->assertFalse($rate->fresh()->is_active);
    }

    private function createRate(
        WorkerProfile $profile,
        int $durationValue,
        string $durationUnit,
        int $amountMinor,
    ): WorkerContractRate {
        return WorkerContractRate::factory()->create([
            'worker_profile_id' => $profile->id,
            'duration_value' => $durationValue,
            'duration_unit' => $durationUnit,
            'amount_minor' => $amountMinor,
            'currency' => 'SAR',
            'is_active' => true,
            'effective_from' => now()->subMinute(),
        ]);
    }

    /**
     * @return array{0: User, 1: Workspace}
     */
    private function createCompany(string $name = 'Pricing Workspace'): array
    {
        $owner = User::factory()->create();
        $workspace = Workspace::factory()->create([
            'owner_user_id' => $owner->id,
            'type' => 'company',
            'name' => $name,
        ]);
        $workspace->users()->attach($owner->id, [
            'membership_role' => 'owner',
            'status' => 'active',
            'joined_at' => now(),
        ]);

        return [$owner, $workspace];
    }

    /**
     * @return array{0: User, 1: WorkerProfile}
     */
    private function createVerifiedWorker(): array
    {
        $user = User::factory()->create();
        $profile = WorkerProfile::query()->create([
            'user_id' => $user->id,
            'display_name' => $user->name,
            'country_code' => WorkerProfile::COUNTRY_JORDAN,
            'nationality' => 'Jordanian',
            'verification_status' => WorkerProfile::VERIFICATION_APPROVED,
            'lifecycle_status' => WorkerProfile::LIFECYCLE_VERIFIED,
            'availability_status' => WorkerProfile::AVAILABILITY_AVAILABLE,
            'professional_summary' => 'Independent sales specialist.',
            'sales_experience_level' => 'senior',
        ]);
        $profile->discoverySettings()->create([
            'is_discoverable' => true,
        ]);

        return [$user, $profile];
    }

    private function createProduct(Workspace $workspace, string $name, string $price): ChatCatalogItem
    {
        return ChatCatalogItem::withoutGlobalScopes()->create([
            'workspace_id' => $workspace->id,
            'name' => $name,
            'slug' => str($name)->slug().'-'.$workspace->id.'-'.uniqid(),
            'description' => 'Product description.',
            'price' => $price,
            'currency' => 'SAR',
            'is_active' => true,
            'is_visible' => true,
            'publication_status' => 'published',
        ]);
    }

    private function companyRequest(User $user, Workspace $workspace): static
    {
        return $this->actingAs($user)
            ->withSession(['current_workspace_id' => $workspace->id]);
    }
}
