<?php

namespace Tests\Feature\Feature\Chat;

use App\Models\ChatCatalogProductTemplate;
use App\Models\Plan;
use App\Models\Subscription;
use App\Models\User;
use App\Models\Workspace;
use App\Services\Chat\ChatCatalogRetrievalService;
use App\Services\Chat\ChatCatalogRootService;
use App\Services\Chat\ChatCatalogService;
use App\Services\Chat\ProductTemplateService;
use Database\Seeders\FoundationSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

class ChatCatalogProductTemplateTest extends TestCase
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
            'name' => 'Template Catalog WS',
        ]);
        $this->workspace->users()->attach($this->user->id, [
            'membership_role' => 'owner',
            'status' => 'active',
            'joined_at' => now(),
        ]);

        $plan = Plan::query()
            ->where('workspace_type', 'company')
            ->where('is_active', true)
            ->first();
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

    public function test_web_product_create_form_shows_template_selector_and_persists_assignment(): void
    {
        $template = ChatCatalogProductTemplate::query()
            ->where('slug', 'general')
            ->where('is_system', true)
            ->firstOrFail();

        $this->actingAs($this->user)
            ->withSession(['current_workspace_id' => $this->workspace->id])
            ->get(route('workspace.chat-catalog.create'))
            ->assertOk()
            ->assertDontSee('قالب بيانات المنتج', false);

        $this->actingAs($this->user)
            ->withSession(['current_workspace_id' => $this->workspace->id])
            ->post(route('workspace.chat-catalog.store'), [
                'name' => 'Web Template Item',
                'sku' => 'WEB-TPL-1',
                'price' => '25.00',
                'currency' => 'SAR',
                'classification' => 'physical',
                'inventory_policy' => 'finite_stock',
                'product_template_id' => $template->id,
                'structured_attributes' => [
                    ['attribute_key' => 'brand', 'attribute_value' => 'Acme', 'value_type' => 'string'],
                ],
            ])
            ->assertRedirect(route('workspace.chat-catalog.index'));

        $this->assertDatabaseHas('chat_catalog_items', [
            'workspace_id' => $this->workspace->id,
            'sku' => 'WEB-TPL-1',
            'product_template_id' => $template->id,
        ]);
    }

    public function test_system_templates_are_seeded_and_listable_via_mobile_api(): void
    {
        $this->enableWorkspaceFeature($this->workspace, 'products');

        $response = $this->actingAsMobile($this->user, $this->workspace)
            ->getJson('/api/mobile/v1/catalog/product-templates');

        $response->assertOk();
        $slugs = collect($response->json('data'))->pluck('slug')->all();
        $this->assertContains('general', $slugs);
        $this->assertContains('digital', $slugs);
        $this->assertContains('fashion-lite', $slugs);

        $general = collect($response->json('data'))->firstWhere('slug', 'general');
        $this->assertTrue($general['is_system']);
        $this->assertSame(1, $general['schema_version']);
        $this->assertNotEmpty($general['fields']);
        $fieldKeys = collect($general['fields'])->pluck('key')->all();
        $this->assertContains('brand', $fieldKeys);
        $this->assertContains('price', $fieldKeys);
    }

    public function test_workspace_cannot_view_another_workspaces_custom_template(): void
    {
        $other = Workspace::factory()->create(['type' => 'company']);
        $foreign = app(ProductTemplateService::class)->createWorkspaceTemplate($other, [
            'name' => 'Foreign Template',
            'fields' => [
                [
                    'field_key' => 'notes',
                    'field_type' => 'text',
                    'level' => 'product',
                ],
            ],
        ]);

        $this->enableWorkspaceFeature($this->workspace, 'products');

        $this->actingAsMobile($this->user, $this->workspace)
            ->getJson('/api/mobile/v1/catalog/product-templates/'.$foreign->id)
            ->assertStatus(422);
    }

    public function test_system_template_cannot_be_updated_by_workspace_user(): void
    {
        $system = ChatCatalogProductTemplate::query()
            ->where('slug', 'general')
            ->where('is_system', true)
            ->firstOrFail();

        $this->actingAs($this->user)
            ->withSession(['current_workspace_id' => $this->workspace->id]);

        $this->assertFalse($this->user->can('update', $system));
    }

    public function test_assign_system_template_snapshots_schema_version(): void
    {
        $this->enableWorkspaceFeature($this->workspace, 'products');

        $template = ChatCatalogProductTemplate::query()
            ->where('slug', 'digital')
            ->where('is_system', true)
            ->firstOrFail();

        $item = $this->inWorkspace($this->workspace, fn () => app(ChatCatalogService::class)->createItem($this->workspace, [
            'name' => 'Digital SKU',
            'sku' => 'DIG-1',
            'price' => '10.00',
            'currency' => 'SAR',
            'classification' => 'digital',
            'inventory_policy' => 'unlimited',
        ]));

        $this->actingAsMobile($this->user, $this->workspace)
            ->putJson('/api/mobile/v1/catalog/'.$item->id.'/product-template', [
                'product_template_id' => $template->id,
            ])
            ->assertOk()
            ->assertJsonPath('data.product_template_id', $template->id)
            ->assertJsonPath('data.product_template_schema_version', 1);

        $this->assertDatabaseHas('chat_catalog_items', [
            'id' => $item->id,
            'product_template_id' => $template->id,
            'product_template_schema_version' => 1,
        ]);
    }

    public function test_template_validation_rejects_invalid_select_and_accepts_valid_region(): void
    {
        $template = ChatCatalogProductTemplate::query()
            ->where('slug', 'digital')
            ->where('is_system', true)
            ->firstOrFail();

        $item = $this->inWorkspace($this->workspace, fn () => app(ChatCatalogService::class)->createItem($this->workspace, [
            'name' => 'Region Product',
            'sku' => 'REG-1',
            'price' => '5.00',
            'currency' => 'SAR',
            'classification' => 'digital',
            'inventory_policy' => 'unlimited',
            'product_template_id' => $template->id,
        ]));

        $this->expectException(ValidationException::class);
        $this->inWorkspace($this->workspace, fn () => app(ChatCatalogService::class)->updateItem($item, [
            'structured_attributes' => [
                ['attribute_key' => 'region', 'attribute_value' => 'invalid-region'],
            ],
        ]));
    }

    public function test_legacy_item_without_template_still_updates_without_template_requirements(): void
    {
        $item = $this->inWorkspace($this->workspace, fn () => app(ChatCatalogService::class)->createItem($this->workspace, [
            'name' => 'Legacy Item',
            'sku' => 'LEG-1',
            'price' => '12.00',
            'currency' => 'SAR',
            'structured_attributes' => [
                ['attribute_key' => 'custom_key', 'attribute_value' => 'custom value'],
            ],
        ]));

        $this->assertNull($item->product_template_id);

        $updated = $this->inWorkspace($this->workspace, fn () => app(ChatCatalogService::class)->updateItem($item, [
            'name' => 'Legacy Item Updated',
            'structured_attributes' => [
                ['attribute_key' => 'custom_key', 'attribute_value' => 'updated'],
            ],
        ]));

        $this->assertSame('Legacy Item Updated', $updated->name);
        $this->inWorkspace($this->workspace, function () use ($item): void {
            $this->assertTrue(
                $item->fresh(['structuredAttributes'])->structuredAttributes
                    ->contains(fn ($row): bool => $row->attribute_key === 'custom_key' && $row->attribute_value === 'updated'),
            );
        });
    }

    public function test_customer_visible_false_attribute_is_hidden_from_ai_safe_retrieval_row(): void
    {
        $templateService = app(ProductTemplateService::class);
        $template = $templateService->createWorkspaceTemplate($this->workspace, [
            'name' => 'Visibility Template',
            'fields' => [
                [
                    'field_key' => 'public_note',
                    'field_type' => 'text',
                    'level' => 'product',
                    'customer_visible' => true,
                ],
                [
                    'field_key' => 'internal_note',
                    'field_type' => 'text',
                    'level' => 'product',
                    'customer_visible' => false,
                ],
            ],
        ]);

        $roots = app(ChatCatalogRootService::class);
        $catalog = $roots->publish($roots->forWorkspace($this->workspace));

        $item = $this->inWorkspace($this->workspace, fn () => app(ChatCatalogService::class)->createItem($this->workspace, [
            'name' => 'Visibility Item',
            'sku' => 'VIS-1',
            'price' => '20.00',
            'currency' => 'SAR',
            'product_template_id' => $template->id,
            'structured_attributes' => [
                ['attribute_key' => 'public_note', 'attribute_value' => 'hello customer'],
                ['attribute_key' => 'internal_note', 'attribute_value' => 'secret supplier cost'],
            ],
        ]));

        $retrieval = app(ChatCatalogRetrievalService::class);
        $rows = $retrieval->searchForPrompt($this->workspace, 'Visibility Item');
        $this->assertNotEmpty($rows);
        $attributes = $rows[0]['attributes'] ?? [];
        $this->assertArrayHasKey('public_note', $attributes);
        $this->assertArrayNotHasKey('internal_note', $attributes);
    }

    public function test_workspace_can_clone_system_template(): void
    {
        $this->enableWorkspaceFeature($this->workspace, 'products');

        $system = ChatCatalogProductTemplate::query()
            ->where('slug', 'fashion-lite')
            ->where('is_system', true)
            ->firstOrFail();

        $response = $this->actingAsMobile($this->user, $this->workspace)
            ->postJson('/api/mobile/v1/catalog/product-templates/'.$system->id.'/clone', [
                'name' => 'Fashion Copy',
            ]);

        $response->assertCreated();
        $this->assertFalse($response->json('data.is_system'));
        $this->assertSame($system->id, $response->json('data.source_template_id'));
        $this->assertSame((int) $this->workspace->id, ChatCatalogProductTemplate::query()->find($response->json('data.id'))->workspace_id);
    }

    public function test_variant_level_template_field_validates_on_update(): void
    {
        $template = ChatCatalogProductTemplate::query()
            ->where('slug', 'fashion-lite')
            ->where('is_system', true)
            ->firstOrFail();

        $item = $this->inWorkspace($this->workspace, fn () => app(ChatCatalogService::class)->createItem($this->workspace, [
            'name' => 'Fashion Item',
            'sku' => 'FASH-1',
            'price' => '99.00',
            'currency' => 'SAR',
            'product_template_id' => $template->id,
            'variants' => [
                [
                    'name' => 'Default',
                    'sku' => 'FASH-1-D',
                    'price' => '99.00',
                    'currency' => 'SAR',
                    'structured_attributes' => [
                        ['attribute_key' => 'size', 'attribute_value' => 'M'],
                    ],
                ],
            ],
        ]));

        $this->expectException(ValidationException::class);
        $this->inWorkspace($this->workspace, fn () => app(ChatCatalogService::class)->updateItem($item->fresh(['variants']), [
            'variants' => [
                [
                    'id' => $item->variants()->first()->id,
                    'name' => 'Default',
                    'sku' => 'FASH-1-D',
                    'price' => '99.00',
                    'currency' => 'SAR',
                    'structured_attributes' => [
                        ['attribute_key' => 'size', 'attribute_value' => 'INVALID'],
                    ],
                ],
            ],
        ]));
    }

    private function actingAsMobile(User $user, Workspace $workspace): static
    {
        $this->flushHeaders();

        $token = $user->createToken('mobile-product-templates');
        $token->accessToken->forceFill(['workspace_id' => $workspace->id])->save();

        return $this->actingAs($user, 'sanctum')
            ->withToken($token->plainTextToken)
            ->withHeader('X-Workspace-Id', (string) $workspace->id)
            ->withHeader('Accept', 'application/json');
    }
}
