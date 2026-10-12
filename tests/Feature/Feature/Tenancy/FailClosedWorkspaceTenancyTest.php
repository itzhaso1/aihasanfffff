<?php

namespace Tests\Feature\Feature\Tenancy;

use App\Exceptions\MissingWorkspaceContextException;
use App\Jobs\CreatePaymentLink;
use App\Jobs\ProcessAIResponse;
use App\Jobs\UpdateAIUsage;
use App\Models\ChatCatalogItem;
use App\Models\PlatformAdmin;
use App\Models\User;
use App\Models\WebhookEvent;
use App\Models\Workspace;
use App\Services\AI\AiOutputValidator;
use App\Services\AI\AIService;
use App\Services\AI\Inbox\AiReplyEligibility;
use App\Services\Channel\Outbound\ChannelOutboundService;
use App\Services\Feature\FeatureAccessService;
use App\Services\Payment\PaymentService;
use App\Services\WhatsApp\WhatsAppService;
use App\Support\Tenancy\WorkspaceContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use RuntimeException;
use Tests\TestCase;

class FailClosedWorkspaceTenancyTest extends TestCase
{
    use RefreshDatabase;

    /**
     * This suite proves fail-closed isolation. Fixture writes must set context or an explicit workspace_id under a temporary fixture flag.
     */
    protected bool $allowTenantFixtureMutations = false;

    public function test_query_without_workspace_context_fails_closed(): void
    {
        $this->expectException(MissingWorkspaceContextException::class);

        ChatCatalogItem::query()->get();
    }

    public function test_create_without_workspace_context_fails_closed(): void
    {
        $workspace = Workspace::factory()->create();

        $this->expectException(MissingWorkspaceContextException::class);

        ChatCatalogItem::withoutGlobalScopes()->create([
            'workspace_id' => $workspace->id,
            'name' => 'No context',
            'slug' => 'no-context',
            'sku' => 'NO-CTX',
            'price' => 10,
            'currency' => 'SAR',
            'stock' => 1,
            'is_active' => true,
        ]);
    }

    public function test_update_without_workspace_context_fails_closed(): void
    {
        [$workspace] = $this->twoWorkspaces();
        $product = $this->productIn($workspace, 'Own');

        app(WorkspaceContext::class)->clear();

        $this->expectException(MissingWorkspaceContextException::class);

        $product->update(['name' => 'Hijack']);
    }

    public function test_delete_without_workspace_context_fails_closed(): void
    {
        [$workspace] = $this->twoWorkspaces();
        $product = $this->productIn($workspace, 'Own');

        app(WorkspaceContext::class)->clear();

        $this->expectException(MissingWorkspaceContextException::class);

        $product->delete();
    }

    public function test_tenant_a_cannot_query_tenant_b_rows(): void
    {
        [$workspaceA, $workspaceB] = $this->twoWorkspaces();
        $this->productIn($workspaceA, 'A Product');
        $foreign = $this->productIn($workspaceB, 'B Secret');

        $visible = app(WorkspaceContext::class)->runFor($workspaceA, fn () => ChatCatalogItem::query()->pluck('id')->all());

        $this->assertCount(1, $visible);
        $this->assertNotContains($foreign->id, $visible);
    }

    public function test_tenant_a_cannot_update_tenant_b_row(): void
    {
        [$workspaceA, $workspaceB] = $this->twoWorkspaces();
        $foreign = $this->productIn($workspaceB, 'B Secret');

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Cross-workspace write attempt detected.');

        app(WorkspaceContext::class)->runFor($workspaceA, function () use ($foreign): void {
            $foreign->update(['name' => 'Stolen']);
        });
    }

    public function test_tenant_a_cannot_delete_tenant_b_row(): void
    {
        [$workspaceA, $workspaceB] = $this->twoWorkspaces();
        $foreign = $this->productIn($workspaceB, 'B Secret');

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Cross-workspace write attempt detected.');

        app(WorkspaceContext::class)->runFor($workspaceA, function () use ($foreign): void {
            $foreign->delete();
        });
    }

    public function test_tenant_a_cannot_create_row_for_tenant_b(): void
    {
        [$workspaceA, $workspaceB] = $this->twoWorkspaces();

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Cross-workspace write attempt detected.');

        app(WorkspaceContext::class)->runFor($workspaceA, function () use ($workspaceB): void {
            ChatCatalogItem::query()->create([
                'workspace_id' => $workspaceB->id,
                'name' => 'Planted',
                'slug' => 'planted-b',
                'sku' => 'PLANT-B',
                'price' => 1,
                'currency' => 'SAR',
                'stock' => 1,
                'is_active' => true,
            ]);
        });
    }

    public function test_correct_workspace_context_scopes_reads_and_writes(): void
    {
        [$workspaceA, $workspaceB] = $this->twoWorkspaces();
        $this->productIn($workspaceA, 'A Product');
        $this->productIn($workspaceB, 'B Secret');

        $created = app(WorkspaceContext::class)->runFor($workspaceA, function (): ChatCatalogItem {
            $this->assertSame(1, ChatCatalogItem::query()->count());

            return ChatCatalogItem::query()->create([
                'name' => 'A Second',
                'slug' => 'a-second',
                'sku' => 'A-2',
                'price' => 5,
                'currency' => 'SAR',
                'stock' => 2,
                'is_active' => true,
            ]);
        });

        $this->assertSame($workspaceA->id, (int) $created->workspace_id);

        $aCount = app(WorkspaceContext::class)->runFor($workspaceA, fn () => ChatCatalogItem::query()->count());
        $bCount = app(WorkspaceContext::class)->runFor($workspaceB, fn () => ChatCatalogItem::query()->count());

        $this->assertSame(2, $aCount);
        $this->assertSame(1, $bCount);
    }

    public function test_explicit_query_for_workspace_does_not_use_ambient_context(): void
    {
        [$workspaceA, $workspaceB] = $this->twoWorkspaces();
        $this->productIn($workspaceA, 'A Product');
        $this->productIn($workspaceB, 'B Secret');

        app(WorkspaceContext::class)->clear();

        $this->assertSame(1, ChatCatalogItem::queryForWorkspace($workspaceB->id)->count());
        $this->assertSame(1, ChatCatalogItem::queryForWorkspace($workspaceA->id)->count());
    }

    public function test_platform_admin_explicit_bypass_allows_cross_tenant_panel_only(): void
    {
        [$workspaceA, $workspaceB] = $this->twoWorkspaces();
        $this->productIn($workspaceA, 'A Product');
        $this->productIn($workspaceB, 'B Secret');

        $admin = PlatformAdmin::factory()->create([
            'email' => 'platform-tenancy@example.com',
            'password' => 'password',
        ]);

        $this->actingAs($admin, 'platform_admin');

        $this->getJson('/platform/dashboard')->assertOk();

        $this->assertNull(app(WorkspaceContext::class)->workspaceId());
        $this->assertFalse(app(WorkspaceContext::class)->isBypassingTenantScope());

        $this->expectException(MissingWorkspaceContextException::class);
        ChatCatalogItem::query()->get();
    }

    public function test_missing_context_is_not_treated_as_platform_admin_access(): void
    {
        [$workspaceA] = $this->twoWorkspaces();
        $this->productIn($workspaceA, 'A Product');

        $this->assertFalse(app(WorkspaceContext::class)->isBypassingTenantScope());
        $this->assertNull(app(WorkspaceContext::class)->workspaceId());

        $this->expectException(MissingWorkspaceContextException::class);
        ChatCatalogItem::query()->count();
    }

    public function test_job_restores_workspace_context_from_workspace_id(): void
    {
        [$workspace] = $this->twoWorkspaces();
        $this->productIn($workspace, 'Queued');

        $count = app(WorkspaceContext::class)->runForId($workspace->id, fn () => ChatCatalogItem::query()->count());

        $this->assertSame(1, $count);
        $this->assertNull(app(WorkspaceContext::class)->workspaceId());
    }

    public function test_job_without_workspace_context_fails_closed(): void
    {
        [$workspace] = $this->twoWorkspaces();
        $this->productIn($workspace, 'Queued');

        $job = new class
        {
            public function handle(): int
            {
                return ChatCatalogItem::query()->count();
            }
        };

        $this->expectException(MissingWorkspaceContextException::class);
        $job->handle();
    }

    public function test_existing_jobs_do_not_query_all_tenants_when_row_missing(): void
    {
        [$workspace] = $this->twoWorkspaces();
        $this->productIn($workspace, 'Queued');

        (new ProcessAIResponse(999_999, 999_999))->handle(
            app(AIService::class),
            app(ChannelOutboundService::class),
            app(AiReplyEligibility::class),
            app(AiOutputValidator::class),
        );

        (new CreatePaymentLink(999_999))->handle(app(PaymentService::class));
        (new UpdateAIUsage(999_999, 0))->handle(app(FeatureAccessService::class));

        $this->assertNull(app(WorkspaceContext::class)->workspaceId());
        $this->expectException(MissingWorkspaceContextException::class);
        ChatCatalogItem::query()->get();
    }

    public function test_whatsapp_webhook_does_not_fallback_to_any_workspace(): void
    {
        [$workspace] = $this->twoWorkspaces();
        $this->productIn($workspace, 'WA');

        app(WhatsAppService::class)->processWebhook([
            'entry' => [[
                'id' => 'evt_unknown_phone',
                'changes' => [[
                    'value' => [
                        'metadata' => ['phone_number_id' => 'not-a-real-phone'],
                        'messages' => [['id' => 'wamid.1', 'from' => '966500000000', 'text' => ['body' => 'hi']]],
                    ],
                ]],
            ]],
        ], []);

        $this->assertSame(0, WebhookEvent::withoutGlobalScopes()->count());
        $this->assertNull(app(WorkspaceContext::class)->workspaceId());
    }

    public function test_payment_webhook_without_bound_workspace_does_not_write(): void
    {
        app(PaymentService::class)->processWebhook(
            providerName: 'local',
            headers: [],
            payload: ['id' => 'evt_unbound'],
        );

        $this->assertSame(0, WebhookEvent::withoutGlobalScopes()->count());
        $this->assertNull(app(WorkspaceContext::class)->workspaceId());
    }

    /**
     * @return array{0: Workspace, 1: Workspace}
     */
    private function twoWorkspaces(): array
    {
        $userA = User::factory()->create();
        $userB = User::factory()->create();

        $workspaceA = Workspace::factory()->create(['owner_user_id' => $userA->id, 'type' => 'company']);
        $workspaceB = Workspace::factory()->create(['owner_user_id' => $userB->id, 'type' => 'company']);

        return [$workspaceA, $workspaceB];
    }

    private function productIn(Workspace $workspace, string $name): ChatCatalogItem
    {
        return app(WorkspaceContext::class)->runFor($workspace, function () use ($name): ChatCatalogItem {
            return ChatCatalogItem::query()->create([
                'name' => $name,
                'slug' => strtolower(str_replace(' ', '-', $name)).'-'.uniqid(),
                'sku' => strtoupper(substr(md5($name.uniqid()), 0, 10)),
                'price' => 10,
                'currency' => 'SAR',
                'stock' => 1,
                'is_active' => true,
            ]);
        });
    }
}
