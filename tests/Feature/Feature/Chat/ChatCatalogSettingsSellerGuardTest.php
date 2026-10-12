<?php

namespace Tests\Feature\Feature\Chat;

use App\Models\ChatCatalog;
use App\Models\Commerce\Seller;
use App\Models\Plan;
use App\Models\Subscription;
use App\Models\User;
use App\Models\Workspace;
use App\Services\Chat\ChatCatalogRootService;
use Database\Seeders\FoundationSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use InvalidArgumentException;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

class ChatCatalogSettingsSellerGuardTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    private Workspace $workspace;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(FoundationSeeder::class);

        $this->user = User::factory()->create();
        $this->workspace = Workspace::factory()->create([
            'owner_user_id' => $this->user->id,
            'type' => 'company',
            'slug' => 'hsn-qns0mn',
        ]);
        $this->workspace->users()->attach($this->user->id, [
            'membership_role' => 'owner',
            'status' => 'active',
            'joined_at' => now(),
        ]);

        $plan = Plan::query()->where('workspace_type', 'company')->where('is_active', true)->first();
        if ($plan) {
            Subscription::withoutGlobalScopes()->updateOrCreate(
                ['workspace_id' => $this->workspace->id],
                [
                    'workspace_id' => $this->workspace->id,
                    'plan_id' => $plan->id,
                    'status' => 'active',
                    'starts_at' => now()->subDay(),
                    'ends_at' => now()->addMonth(),
                ]
            );
        }

        $registrar = app(PermissionRegistrar::class);
        $registrar->setPermissionsTeamId($this->workspace->id);
        $this->user->givePermissionTo(['chat.catalog.view', 'chat.catalog.manage']);
        $registrar->setPermissionsTeamId(null);
    }

    public function test_null_seller_fields_save_without_a_commerce_seller(): void
    {
        $catalog = $this->save([
            'seller_public_display_name' => null,
            'seller_support_contact' => ['email' => null, 'phone' => null],
            'public_name' => 'كتالوج حسن',
        ]);

        $this->assertNull($catalog->commerce_seller_id);
        $this->assertSame('كتالوج حسن', $catalog->public_name);
        $this->assertSame(0, Seller::withoutGlobalScopes()->count());
    }

    public function test_blank_seller_contact_strings_save_without_a_commerce_seller(): void
    {
        $catalog = $this->save([
            'seller_public_display_name' => '',
            'seller_support_contact' => ['email' => '', 'phone' => ''],
        ]);

        $this->assertNull($catalog->commerce_seller_id);
        $this->assertSame(0, Seller::withoutGlobalScopes()->count());
    }

    public function test_mixed_empty_seller_contact_saves_without_a_commerce_seller(): void
    {
        $catalog = $this->save([
            'seller_support_contact' => ['email' => null, 'phone' => ''],
        ]);

        $this->assertNull($catalog->commerce_seller_id);
    }

    public function test_display_name_without_seller_is_rejected(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Seller profile settings require a workspace seller.');

        $this->save(['seller_public_display_name' => 'حسن']);
    }

    public function test_seller_email_without_seller_is_rejected(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Seller profile settings require a workspace seller.');

        $this->save([
            'seller_support_contact' => ['email' => 'seller@example.com'],
        ]);
    }

    public function test_seller_phone_without_seller_is_rejected(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Seller profile settings require a workspace seller.');

        $this->save([
            'seller_support_contact' => ['phone' => '+962790000000'],
        ]);
    }

    public function test_seller_profile_saves_when_workspace_seller_exists(): void
    {
        $seller = $this->inWorkspace($this->workspace, fn () => Seller::query()->create([
            'workspace_id' => $this->workspace->id,
            'name' => 'متجر حسن',
            'slug' => 'hasan-store',
        ]));

        $catalog = $this->save([
            'commerce_seller_id' => $seller->id,
            'seller_public_display_name' => 'حسن',
            'seller_support_contact' => ['email' => 'seller@example.com', 'phone' => null],
        ]);

        $this->assertSame($seller->id, $catalog->commerce_seller_id);
        $this->assertSame('حسن', $catalog->seller_public_display_name);
        $this->assertSame('seller@example.com', $catalog->seller_support_contact['email'] ?? null);
    }

    public function test_web_channel_save_with_empty_seller_fields_does_not_fail(): void
    {
        $this->actingAs($this->user)
            ->withSession(['current_workspace_id' => $this->workspace->id])
            ->put(route('workspace.chat-catalog.settings.update'), [
                'channel_visibility' => ['web' => true, 'chat' => true],
                'seller_public_display_name' => null,
                'seller_support_contact' => ['email' => null, 'phone' => null],
            ])
            ->assertRedirect()
            ->assertSessionHasNoErrors();

        $catalog = app(ChatCatalogRootService::class)->forWorkspace($this->workspace)->fresh();
        $this->assertTrue($catalog->channelEnabled('web'));
        $this->assertNull($catalog->commerce_seller_id);
        $this->assertSame(0, Seller::withoutGlobalScopes()->count());
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function save(array $payload): ChatCatalog
    {
        return $this->inWorkspace($this->workspace, function () use ($payload) {
            $roots = app(ChatCatalogRootService::class);

            return $roots->updateSettings($roots->forWorkspace($this->workspace), $payload);
        });
    }
}
