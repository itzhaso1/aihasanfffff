<?php

namespace Tests\Feature\Feature\Finance;

use App\Enums\Finance\TaxProfileType;
use App\Models\Customer;
use App\Models\Finance\FinanceAiConversation;
use App\Models\Finance\FinanceAiMessage;
use App\Models\Finance\FinanceInvoice;
use App\Models\Plan;
use App\Models\Subscription;
use App\Models\User;
use App\Models\Workspace;
use App\Models\WorkspaceFeatureFlag;
use App\Services\Finance\FinanceBootstrapService;
use App\Services\Finance\InvoiceService;
use App\Support\Tenancy\WorkspaceContext;
use Database\Seeders\FoundationSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

class FinancialAssistantPhase6Test extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config([
            'finance_assistant.planner' => 'heuristic',
            'finance_assistant.agent_enabled' => false,
        ]);
        $this->seed(FoundationSeeder::class);
    }

    public function test_web_assistant_creates_conversation_and_shows_answer(): void
    {
        [$owner, $workspace] = $this->createWorkspaceOwner();
        $customer = $this->customer($workspace, 'Web Assistant Buyer');
        $this->issueSales($workspace, $customer, (int) $owner->id, '1500.00');

        $this->actingAs($owner)->withSession(['current_workspace_id' => $workspace->id])
            ->post(route('workspace.finance.assistant.ask'), ['question' => 'كم مبيعات هذا الشهر؟'])
            ->assertRedirect()
            ->assertSessionHasNoErrors();

        $conversationId = (int) FinanceAiConversation::queryForWorkspace((int) $workspace->id)->value('id');
        $this->assertGreaterThan(0, $conversationId);

        $this->actingAs($owner)->withSession(['current_workspace_id' => $workspace->id])
            ->get(route('workspace.finance.assistant.index', ['conversation_id' => $conversationId]))
            ->assertOk()
            ->assertSee('1500.00')
            ->assertSee('كم مبيعات هذا الشهر؟');

        $this->assertSame(2, FinanceAiMessage::queryForWorkspace((int) $workspace->id)->where('conversation_id', $conversationId)->count());
    }

    public function test_legacy_copilot_url_still_renders_unified_assistant(): void
    {
        [$owner, $workspace] = $this->createWorkspaceOwner();

        $this->actingAs($owner)->withSession(['current_workspace_id' => $workspace->id])
            ->get(route('workspace.finance.copilot.index'))
            ->assertOk()
            ->assertSee('المساعد المالي')
            ->assertSee('محادثة طبيعية');
    }

    public function test_legacy_copilot_api_delegates_to_unified_assistant(): void
    {
        [$owner, $workspace] = $this->createWorkspaceOwner();
        Sanctum::actingAs($owner);

        $response = $this->postJson('/api/finance/v1/copilot/ask', [
            'question' => 'كيف وضعي؟',
        ], $this->workspaceHeader($workspace))->assertOk();

        $this->assertTrue($response->json('success'));
        $this->assertNotEmpty($response->json('data.answer'));
        $this->assertNotNull($response->json('data.conversation_id'));
    }

    public function test_web_assistant_requires_ai_feature(): void
    {
        [$owner, $workspace] = $this->createWorkspaceOwner(enableAi: false);

        $this->actingAs($owner)->withSession(['current_workspace_id' => $workspace->id])
            ->get(route('workspace.finance.assistant.index'))
            ->assertForbidden();
    }

    private function issueSales(Workspace $workspace, Customer $customer, int $actorId, string $amount): FinanceInvoice
    {
        app(WorkspaceContext::class)->set($workspace);

        return app(InvoiceService::class)->create($workspace, [
            'type' => 'sales',
            'customer_id' => $customer->id,
            'issue_date' => now()->toDateString(),
            'due_date' => now()->addDays(10)->toDateString(),
            'currency' => 'SAR',
            'invoice_status' => 'issued',
            'tax_profile_type' => TaxProfileType::ZeroRated->value,
            'tax_rate' => 0,
            'items' => [[
                'product_name' => 'Assistant line',
                'quantity' => 1,
                'unit_price' => $amount,
                'discount' => 0,
                'tax_rate' => 0,
                'tax_type' => TaxProfileType::ZeroRated->value,
            ]],
        ], $actorId);
    }

    /**
     * @return array{0: User, 1: Workspace}
     */
    private function createWorkspaceOwner(bool $enableAi = true): array
    {
        $user = User::factory()->create();
        $workspace = Workspace::factory()->create([
            'owner_user_id' => $user->id,
            'type' => 'company',
            'name' => 'Phase6 Co',
        ]);
        $workspace->users()->attach($user->id, [
            'membership_role' => 'owner',
            'status' => 'active',
            'joined_at' => now(),
        ]);
        app(WorkspaceContext::class)->set($workspace);

        foreach (['finance', 'products', 'orders', 'customers', 'payments'] as $feature) {
            WorkspaceFeatureFlag::withoutGlobalScopes()->updateOrCreate(
                ['workspace_id' => $workspace->id, 'feature_key' => $feature],
                ['workspace_id' => $workspace->id, 'feature_key' => $feature, 'enabled' => true, 'source' => 'manual']
            );
        }

        WorkspaceFeatureFlag::withoutGlobalScopes()->updateOrCreate(
            ['workspace_id' => $workspace->id, 'feature_key' => 'ai'],
            [
                'workspace_id' => $workspace->id,
                'feature_key' => 'ai',
                'enabled' => $enableAi,
                'source' => 'manual',
            ]
        );

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

    private function customer(Workspace $workspace, string $name): Customer
    {
        app(WorkspaceContext::class)->set($workspace);

        return Customer::withoutGlobalScopes()->create([
            'workspace_id' => $workspace->id,
            'name' => $name,
            'phone' => '05'.random_int(10000000, 99999999),
            'email' => strtolower(str_replace(' ', '.', $name)).uniqid('', true).'@example.com',
        ]);
    }

    /**
     * @return array<string, string>
     */
    private function workspaceHeader(Workspace $workspace): array
    {
        return ['X-Workspace-Id' => (string) $workspace->id];
    }
}
