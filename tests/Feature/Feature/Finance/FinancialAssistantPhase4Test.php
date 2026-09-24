<?php

namespace Tests\Feature\Feature\Finance;

use App\Enums\Finance\TaxProfileType;
use App\Models\Contract\Contract;
use App\Models\Customer;
use App\Models\Finance\FinanceAiConversation;
use App\Models\Finance\FinanceBillingSchedule;
use App\Models\Finance\FinanceInvoice;
use App\Models\Finance\FinanceSupplier;
use App\Models\Plan;
use App\Models\Subscription;
use App\Models\User;
use App\Models\Workspace;
use App\Models\WorkspaceFeatureFlag;
use App\Services\Contracts\ContractService;
use App\Services\Finance\Assistant\Conversation\ConversationEntityState;
use App\Services\Finance\Assistant\Conversation\ConversationEntityTracker;
use App\Services\Finance\Assistant\Conversation\ConversationFollowUpResolver;
use App\Services\Finance\BillingScheduleService;
use App\Services\Finance\FinanceBootstrapService;
use App\Services\Finance\InvoiceService;
use App\Support\Tenancy\WorkspaceContext;
use Database\Seeders\FoundationSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\RateLimiter;
use Laravel\Sanctum\Sanctum;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

class FinancialAssistantPhase4Test extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config([
            'finance_assistant.planner' => 'heuristic',
            'finance_assistant.agent_enabled' => false,
        ]);
        RateLimiter::clear('mobile-ai');
        $this->seed(FoundationSeeder::class);
    }

    public function test_compare_follow_up_uses_conversation_period_context(): void
    {
        [$owner, $workspace] = $this->createWorkspaceOwner();
        $customer = $this->customer($workspace, 'Compare Buyer');
        $this->issueSales($workspace, $customer, (int) $owner->id, '1200.00');
        Sanctum::actingAs($owner);

        $first = $this->postJson('/api/finance/v1/assistant/ask', [
            'question' => 'كم مبيعاتنا هذا الشهر؟',
            'locale' => 'ar',
        ], $this->workspaceHeader($workspace))->assertOk();

        $conversationId = (int) $first->json('data.conversation_id');
        $this->assertContains('sales.period', $first->json('data.tools_used'));

        $second = $this->postJson('/api/finance/v1/assistant/ask', [
            'question' => 'وقارن',
            'conversation_id' => $conversationId,
            'locale' => 'ar',
        ], $this->workspaceHeader($workspace))->assertOk();

        $this->assertContains('compare.periods', $second->json('data.tools_used'));
    }

    public function test_customer_balance_follow_up_uses_stored_customer_slot(): void
    {
        [$owner, $workspace] = $this->createWorkspaceOwner();
        $customer = $this->customer($workspace, 'Follow Up Ahmed');
        $this->issueSales($workspace, $customer, (int) $owner->id, '800.00');
        Sanctum::actingAs($owner);

        $first = $this->postJson('/api/finance/v1/assistant/ask', [
            'question' => 'رصيد عميل Follow Up Ahmed',
            'locale' => 'ar',
        ], $this->workspaceHeader($workspace))->assertOk();

        $conversationId = (int) $first->json('data.conversation_id');
        $this->assertContains('customers.balance', $first->json('data.tools_used'));

        $second = $this->postJson('/api/finance/v1/assistant/ask', [
            'question' => 'عليه فواتير؟',
            'conversation_id' => $conversationId,
            'locale' => 'ar',
        ], $this->workspaceHeader($workspace))->assertOk();

        $this->assertContains('invoices.by_customer', $second->json('data.tools_used'));
    }

    public function test_context_entities_persist_after_turn(): void
    {
        [$owner, $workspace] = $this->createWorkspaceOwner();
        $customer = $this->customer($workspace, 'Persisted Slot Client');
        $this->issueSales($workspace, $customer, (int) $owner->id, '500.00');
        Sanctum::actingAs($owner);

        $response = $this->postJson('/api/finance/v1/assistant/ask', [
            'question' => 'كم عليه Persisted Slot Client؟',
            'locale' => 'ar',
        ], $this->workspaceHeader($workspace))->assertOk();

        $conversation = FinanceAiConversation::queryForWorkspace((int) $workspace->id)
            ->findOrFail((int) $response->json('data.conversation_id'));

        $this->assertIsArray($conversation->context_entities);
        $this->assertSame('Persisted Slot Client', $conversation->context_entities['customer_name'] ?? null);
        $this->assertSame(ConversationEntityState::FOCUS_CUSTOMER, $conversation->context_entities['entity_focus'] ?? null);
    }

    public function test_ambiguous_name_returns_clarification_without_tools(): void
    {
        [$owner, $workspace] = $this->createWorkspaceOwner();
        $this->customer($workspace, 'Shared Name Co');
        $this->supplier($workspace, 'Shared Name Co');
        Sanctum::actingAs($owner);

        $response = $this->postJson('/api/finance/v1/assistant/ask', [
            'question' => 'Shared Name Co',
            'locale' => 'ar',
        ], $this->workspaceHeader($workspace))->assertOk();

        $this->assertSame('entity_ambiguity', $response->json('data.intent'));
        $this->assertSame([], $response->json('data.tools_used'));
        $this->assertStringContainsString('عميل', (string) $response->json('data.answer'));
        $this->assertStringContainsString('مورد', (string) $response->json('data.answer'));
    }

    public function test_contract_balance_follow_up_uses_contract_slot(): void
    {
        [$owner, $workspace] = $this->createWorkspaceOwner();
        [$contract] = $this->openContractWithSchedule($workspace, $owner, 300);
        Sanctum::actingAs($owner);

        $first = $this->postJson('/api/finance/v1/assistant/ask', [
            'question' => 'عقد '.$contract->contract_number,
            'locale' => 'ar',
        ], $this->workspaceHeader($workspace))->assertOk();

        $conversationId = (int) $first->json('data.conversation_id');
        $this->assertContains('contracts.details', $first->json('data.tools_used'));

        $second = $this->postJson('/api/finance/v1/assistant/ask', [
            'question' => 'كم فوترنا منه؟',
            'conversation_id' => $conversationId,
            'locale' => 'ar',
        ], $this->workspaceHeader($workspace))->assertOk();

        $this->assertContains('contracts.balance', $second->json('data.tools_used'));
    }

    public function test_follow_up_resolver_maps_compare_short_question(): void
    {
        $resolver = app(ConversationFollowUpResolver::class);
        $state = new ConversationEntityState(
            periodPreset: 'month',
            lastTools: ['sales.period'],
            lastMetrics: ['sales.period'],
            entityFocus: ConversationEntityState::FOCUS_METRIC,
        );

        $plans = $resolver->resolve('وقارن', $state);

        $this->assertNotNull($plans);
        $this->assertSame('compare.periods', $plans[0]['name']);
        $this->assertSame('month', $plans[0]['arguments']['preset'] ?? null);
    }

    public function test_entity_tracker_merges_customer_from_tool_facts(): void
    {
        $tracker = app(ConversationEntityTracker::class);
        $state = new ConversationEntityState;

        $merged = $tracker->mergeFromTurn($state, [
            'tools_used' => ['customers.balance'],
            'facts' => [[
                'tool' => 'customers.balance',
                'available' => true,
                'data' => [
                    'customer' => ['name' => 'Merged Customer'],
                ],
            ]],
            'period' => ['from' => '2026-09-01', 'to' => '2026-09-30'],
        ]);

        $this->assertSame('Merged Customer', $merged->customerName);
        $this->assertSame(ConversationEntityState::FOCUS_CUSTOMER, $merged->entityFocus);
        $this->assertSame(['customers.balance'], $merged->lastTools);
    }

    /**
     * @return array{0: Contract, 1: FinanceBillingSchedule}
     */
    private function openContractWithSchedule(
        Workspace $workspace,
        User $owner,
        float $value = 230,
        int $occurrences = 2,
        ?Customer $customer = null,
    ): array {
        $customer ??= $this->customer($workspace, 'Contract Customer '.uniqid());

        $contract = $this->runInWorkspace($workspace, function () use ($workspace, $owner, $customer, $value): Contract {
            $draft = app(ContractService::class)->create($workspace, [
                'title' => 'Assistant Contract',
                'customer_id' => $customer->id,
                'currency' => 'SAR',
                'start_date' => now()->toDateString(),
                'end_date' => now()->addYear()->toDateString(),
                'items' => [[
                    'title' => 'Service',
                    'quantity' => 1,
                    'unit_price' => $value,
                    'tax_rate' => 0,
                ]],
            ], (int) $owner->id);

            return app(ContractService::class)->activate($draft, (int) $owner->id);
        });

        $schedule = $this->runInWorkspace($workspace, function () use ($contract, $owner, $occurrences) {
            return app(BillingScheduleService::class)->createFromContract($contract, [
                'title' => 'Installment',
                'frequency' => 'installment',
                'total_occurrences' => $occurrences,
                'start_date' => now()->toDateString(),
                'status' => FinanceBillingSchedule::STATUS_ACTIVE,
            ], (int) $owner->id);
        });

        return [$contract, $schedule];
    }

    /**
     * @return array{0: User, 1: Workspace}
     */
    private function createWorkspaceOwner(string $name = 'Phase4 Co'): array
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

        foreach (['finance', 'products', 'orders', 'customers', 'payments'] as $feature) {
            WorkspaceFeatureFlag::withoutGlobalScopes()->updateOrCreate(
                ['workspace_id' => $workspace->id, 'feature_key' => $feature],
                ['workspace_id' => $workspace->id, 'feature_key' => $feature, 'enabled' => true, 'source' => 'manual']
            );
        }

        WorkspaceFeatureFlag::withoutGlobalScopes()->updateOrCreate(
            ['workspace_id' => $workspace->id, 'feature_key' => 'ai'],
            ['workspace_id' => $workspace->id, 'feature_key' => 'ai', 'enabled' => true, 'source' => 'manual']
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

    private function supplier(Workspace $workspace, string $name): FinanceSupplier
    {
        app(WorkspaceContext::class)->set($workspace);

        return FinanceSupplier::withoutGlobalScopes()->create([
            'workspace_id' => $workspace->id,
            'name' => $name,
            'status' => 'active',
        ]);
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
     * @template T
     *
     * @param  callable(): T  $callback
     * @return T
     */
    private function runInWorkspace(Workspace $workspace, callable $callback)
    {
        app(WorkspaceContext::class)->set($workspace);

        return $callback();
    }

    /**
     * @return array<string, string>
     */
    private function workspaceHeader(Workspace $workspace): array
    {
        return ['X-Workspace-Id' => (string) $workspace->id];
    }
}
