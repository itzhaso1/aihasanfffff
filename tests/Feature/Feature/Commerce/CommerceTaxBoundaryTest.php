<?php

namespace Tests\Feature\Feature\Commerce;

use App\Enums\ChatCatalog\ChatCatalogInventoryPolicy;
use App\Enums\ChatCatalog\ChatCatalogOperatingStatus;
use App\Enums\WorkerEngagementStatus;
use App\Models\ChatCatalogItem;
use App\Models\Commerce\Seller;
use App\Models\User;
use App\Models\Worker\WorkerEngagement;
use App\Models\Worker\WorkerProfile;
use App\Models\Workspace;
use App\Services\Chat\ChatCatalogRootService;
use App\Services\Commerce\CommerceCartService;
use App\Services\Commerce\CommerceOrderService;
use App\Services\Commerce\CommerceWorkspaceProfileService;
use Database\Seeders\FoundationSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class CommerceTaxBoundaryTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(FoundationSeeder::class);
    }

    public function test_tax_exclusive_order_uses_chat_setting_and_keeps_snapshot(): void
    {
        [$owner, $workspace, $item] = $this->commerceWorkspace();
        $this->inWorkspace($workspace, function () use ($workspace): void {
            app(CommerceWorkspaceProfileService::class)->updateDefaultTaxRate($workspace, '8.25');
        });

        $order = $this->inWorkspace($workspace, function () use ($workspace, $item) {
            $carts = app(CommerceCartService::class);
            $cart = $carts->addItem($workspace, $carts->create($workspace, currency: 'SAR'), $item->id, null, 3);

            return app(CommerceOrderService::class)->createDraftFromCart($workspace, $cart, 'tax-boundary-0001');
        });

        $this->assertSame('30.00', $order->subtotal);
        $this->assertSame('2.48', $order->tax_amount);
        $this->assertSame('32.48', $order->total_amount);
        $this->assertSame('8.25', $order->tax_rate);
        $this->assertSame('8.25', $order->items->first()->tax_snapshot['tax_rate']);
        $this->assertNull($order->finance_invoice_id);
    }

    public function test_tax_falls_back_to_fifteen_when_no_chat_setting_exists(): void
    {
        [$owner, $workspace, $item] = $this->commerceWorkspace();

        $order = $this->inWorkspace($workspace, function () use ($workspace, $item) {
            $carts = app(CommerceCartService::class);
            $cart = $carts->addItem($workspace, $carts->create($workspace, currency: 'SAR'), $item->id, null, 1);

            return app(CommerceOrderService::class)->createDraftFromCart($workspace, $cart, 'tax-boundary-0002');
        });

        $this->assertSame('10.00', $order->subtotal);
        $this->assertSame('1.50', $order->tax_amount);
        $this->assertSame('11.50', $order->total_amount);
        $this->assertSame('15.00', $order->tax_rate);
    }

    public function test_owner_can_update_chat_tax_and_member_cannot(): void
    {
        [$owner, $workspace] = $this->companyWorkspace();
        $member = User::factory()->create();
        $workspace->users()->attach($member->id, [
            'membership_role' => 'member',
            'status' => 'active',
            'joined_at' => now(),
        ]);
        $this->enableWorkspaceFeature($workspace, 'orders');

        $this->actingAs($owner)
            ->withSession(['current_workspace_id' => $workspace->id])
            ->from(route('workspace.commerce.orders.index'))
            ->put(route('workspace.commerce.tax-setting.update'), [
                'default_tax_rate' => '8.25',
            ])
            ->assertRedirect(route('workspace.commerce.orders.index'));

        $this->assertSame('8.25', $this->inWorkspace(
            $workspace,
            fn () => app(CommerceWorkspaceProfileService::class)->defaultTaxRate($workspace),
        ));

        $this->actingAs($member)
            ->withSession(['current_workspace_id' => $workspace->id])
            ->from(route('workspace.commerce.orders.index'))
            ->put(route('workspace.commerce.tax-setting.update'), [
                'default_tax_rate' => '20.00',
            ])
            ->assertForbidden();

        $this->assertSame('8.25', $this->inWorkspace(
            $workspace,
            fn () => app(CommerceWorkspaceProfileService::class)->defaultTaxRate($workspace),
        ));
    }

    public function test_independent_worker_cannot_change_chat_tax(): void
    {
        [$owner, $workspace, $item] = $this->commerceWorkspace();
        $this->inWorkspace($workspace, function () use ($workspace): void {
            app(CommerceWorkspaceProfileService::class)->updateDefaultTaxRate($workspace, '8.25');
        });

        $worker = User::factory()->create();
        WorkerProfile::query()->create([
            'user_id' => $worker->id,
            'display_name' => 'Tax Boundary Worker',
            'country_code' => WorkerProfile::COUNTRY_JORDAN,
            'nationality' => 'Jordanian',
            'verification_status' => WorkerProfile::VERIFICATION_APPROVED,
            'lifecycle_status' => WorkerProfile::LIFECYCLE_VERIFIED,
            'availability_status' => WorkerProfile::AVAILABILITY_AVAILABLE,
            'professional_summary' => 'Independent worker.',
            'sales_experience_level' => 'senior',
        ]);

        $this->actingAs($worker)
            ->withSession(['current_workspace_id' => $workspace->id])
            ->from(route('worker.inbox.home'))
            ->put(route('workspace.commerce.tax-setting.update'), [
                'default_tax_rate' => '20.00',
            ])
            ->assertRedirect(route('worker.inbox.home'));

        $this->actingAs($worker)
            ->withSession(['current_workspace_id' => $workspace->id])
            ->putJson(route('workspace.commerce.tax-setting.update'), [
                'default_tax_rate' => '20.00',
            ])
            ->assertForbidden();

        $this->assertSame('8.25', $this->inWorkspace(
            $workspace,
            fn () => app(CommerceWorkspaceProfileService::class)->defaultTaxRate($workspace),
        ));
        $this->assertSame('10.00', $item->fresh()->price);
        $this->assertSame($owner->id, $workspace->fresh()->owner_user_id);
    }

    public function test_chat_tax_change_does_not_mutate_catalog_or_worker_contract_pricing(): void
    {
        [$owner, $workspace, $item] = $this->commerceWorkspace();
        $worker = User::factory()->create();
        $profile = WorkerProfile::query()->create([
            'user_id' => $worker->id,
            'display_name' => 'Catalog Boundary Worker',
            'country_code' => WorkerProfile::COUNTRY_JORDAN,
            'nationality' => 'Jordanian',
            'verification_status' => WorkerProfile::VERIFICATION_APPROVED,
            'lifecycle_status' => WorkerProfile::LIFECYCLE_VERIFIED,
            'availability_status' => WorkerProfile::AVAILABILITY_AVAILABLE,
            'professional_summary' => 'Independent worker.',
            'sales_experience_level' => 'senior',
        ]);
        $engagement = WorkerEngagement::withoutGlobalScopes()->create([
            'workspace_id' => $workspace->id,
            'worker_profile_id' => $profile->id,
            'status' => WorkerEngagementStatus::Active,
            'job_title' => 'Catalog isolation role',
            'created_by' => $owner->id,
            'active_at' => now(),
            'offered_contract_amount_minor' => 60000,
            'offered_contract_currency' => 'SAR',
            'agreed_contract_amount_minor' => 60000,
            'agreed_contract_currency' => 'SAR',
        ]);

        $this->actingAs($owner)
            ->withSession(['current_workspace_id' => $workspace->id])
            ->from(route('workspace.commerce.orders.index'))
            ->put(route('workspace.commerce.tax-setting.update'), [
                'default_tax_rate' => '8.25',
            ])
            ->assertRedirect(route('workspace.commerce.orders.index'));

        $this->assertSame('10.00', $item->fresh()->price);
        $this->assertSame('SAR', $item->fresh()->currency);
        $this->assertSame(60000, $engagement->fresh()->agreed_contract_amount_minor);
        $this->assertSame('SAR', $engagement->fresh()->agreed_contract_currency);
        $this->assertSame('8.25', $this->inWorkspace(
            $workspace,
            fn () => app(CommerceWorkspaceProfileService::class)->defaultTaxRate($workspace),
        ));
    }

    public function test_commerce_tax_source_does_not_import_finance_tables_or_services(): void
    {
        $resolver = file_get_contents(app_path('Services/Commerce/ChatCommerceTaxRateResolver.php'));
        $pricing = file_get_contents(app_path('Services/Commerce/CommercePricingService.php'));

        $this->assertIsString($resolver);
        $this->assertIsString($pricing);
        foreach ([$resolver, $pricing] as $source) {
            $this->assertStringNotContainsString('finance_tax_rates', $source);
            $this->assertStringNotContainsString('finance_settings', $source);
            $this->assertStringNotContainsString('App\\Services\\Finance', $source);
            $this->assertStringNotContainsString('App\\Models\\Finance', $source);
            $this->assertStringNotContainsString('Finance\\Tax\\TaxRateResolver', $source);
        }
    }

    /**
     * @return array{0: User, 1: Workspace}
     */
    private function companyWorkspace(): array
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

        return [$user, $workspace];
    }

    /**
     * @return array{0: User, 1: Workspace, 2: ChatCatalogItem}
     */
    private function commerceWorkspace(): array
    {
        [$user, $workspace] = $this->companyWorkspace();
        $this->enableWorkspaceFeature($workspace, 'products');
        $this->enableWorkspaceFeature($workspace, 'orders');
        $seller = Seller::factory()->create(['workspace_id' => $workspace->id]);

        $item = $this->inWorkspace($workspace, function () use ($workspace, $seller): ChatCatalogItem {
            $catalog = app(ChatCatalogRootService::class)->forWorkspace($workspace);
            $catalog->update([
                'commerce_seller_id' => $seller->id,
                'status' => ChatCatalogOperatingStatus::Published,
                'published_at' => now(),
                'default_currency' => 'SAR',
                'channel_visibility' => ['chat' => true],
            ]);

            return ChatCatalogItem::factory()->create([
                'workspace_id' => $workspace->id,
                'commerce_seller_id' => $seller->id,
                'name' => 'Tax catalog item',
                'price' => '10.00',
                'currency' => 'SAR',
                'classification' => 'physical',
                'inventory_policy' => ChatCatalogInventoryPolicy::FiniteStock,
                'stock' => 10,
                'is_active' => true,
                'is_visible' => true,
                'publication_status' => 'published',
                'published_at' => now(),
                'tax_display_behavior' => 'tax_exclusive',
            ]);
        });

        return [$user, $workspace, $item];
    }
}
