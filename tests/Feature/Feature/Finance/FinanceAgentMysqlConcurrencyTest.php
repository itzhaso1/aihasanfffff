<?php

namespace Tests\Feature\Feature\Finance;

use App\Enums\Finance\TaxProfileType;
use App\Models\Customer;
use App\Models\Finance\FinanceAiConversation;
use App\Models\Finance\FinanceAiMessage;
use App\Models\Finance\FinanceAiToolCall;
use App\Models\Finance\FinanceInvoice;
use App\Models\Plan;
use App\Models\Subscription;
use App\Models\User;
use App\Models\Workspace;
use App\Models\WorkspaceFeatureFlag;
use App\Models\WorkspaceUsageMeter;
use App\Services\Finance\FinanceBootstrapService;
use App\Services\Finance\InvoiceService;
use App\Support\Tenancy\WorkspaceContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\RateLimiter;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

class FinanceAgentMysqlConcurrencyTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        if (! $this->usingServerDatabase()) {
            $this->markTestSkipped(
                'Genuine multi-writer conversation test requires mysql/pgsql, not sqlite :memory:.'
            );
        }
        RateLimiter::clear('mobile-ai');
        config([
            'finance_assistant.agent_enabled' => true,
            'finance_assistant.heuristic_planner_in_testing' => false,
            'services.google_ai_studio.key' => '',
        ]);
    }

    /**
     * @return list<string>
     */
    protected function connectionsToTransact()
    {
        if (! $this->usingServerDatabase()) {
            return [(string) config('database.default')];
        }

        return [];
    }

    public function test_concurrent_requests_on_the_same_conversation_are_serialized(): void
    {

        [$owner, $workspace] = $this->createWorkspaceOwner('Mysql Conv');
        $this->issueSales($workspace, $this->customer($workspace, 'Mysql Buyer'), (int) $owner->id, '5000.00');
        $token = $owner->createToken('concurrency')->plainTextToken;

        $conversation = $this->workspaceContext()->runFor($workspace, function () use ($workspace, $owner): FinanceAiConversation {
            return FinanceAiConversation::query()->create([
                'workspace_id' => $workspace->id,
                'user_id' => $owner->id,
                'status' => FinanceAiConversation::STATUS_ACTIVE,
                'locale' => 'ar',
            ]);
        });

        $results = $this->spawnWorkers([
            [
                'token' => $token,
                'workspace_id' => $workspace->id,
                'conversation_id' => $conversation->id,
                'question' => 'كم مبيعاتنا اليوم؟',
                'prefix' => 'c1',
                'delay_ms' => 0,
                'gemini_delay_ms' => 400,
            ],
            [
                'token' => $token,
                'workspace_id' => $workspace->id,
                'conversation_id' => $conversation->id,
                'question' => 'ليش؟',
                'prefix' => 'c2',
                'delay_ms' => 50,
                'gemini_delay_ms' => 400,
            ],
        ]);

        $statuses = array_column($results, 'status');
        $this->assertContains(200, $statuses, json_encode($results, JSON_UNESCAPED_UNICODE));
        $this->assertTrue(in_array(409, $statuses, true) || count(array_filter($statuses, fn ($status) => $status === 200)) === 1);
        foreach ($statuses as $status) {
            $this->assertContains($status, [200, 409]);
        }

        $userMessages = FinanceAiMessage::queryForWorkspace((int) $workspace->id)
            ->where('conversation_id', $conversation->id)
            ->where('role', FinanceAiMessage::ROLE_USER)
            ->count();
        $assistantMessages = FinanceAiMessage::queryForWorkspace((int) $workspace->id)
            ->where('conversation_id', $conversation->id)
            ->where('role', FinanceAiMessage::ROLE_ASSISTANT)
            ->count();
        $this->assertSame($userMessages, $assistantMessages);
        $this->assertLessThanOrEqual(1, $userMessages);

        $this->assertSame(1, (int) (WorkspaceUsageMeter::withoutGlobalScopes()
            ->where('workspace_id', $workspace->id)
            ->where('meter_key', 'ai_usage')
            ->value('used') ?? 0));

        $conversation = FinanceAiConversation::queryForWorkspace((int) $workspace->id)->findOrFail($conversation->id);
        $this->assertNull($conversation->processing_started_at);

        $toolMessageIds = FinanceAiToolCall::queryForWorkspace((int) $workspace->id)
            ->where('conversation_id', $conversation->id)
            ->pluck('message_id')
            ->all();
        $assistantIds = FinanceAiMessage::queryForWorkspace((int) $workspace->id)
            ->where('conversation_id', $conversation->id)
            ->where('role', FinanceAiMessage::ROLE_ASSISTANT)
            ->pluck('id')
            ->all();
        foreach ($toolMessageIds as $messageId) {
            $this->assertContains($messageId, $assistantIds);
        }
    }

    public function test_concurrent_same_idempotency_key_executes_once(): void
    {

        [$owner, $workspace] = $this->createWorkspaceOwner('Mysql Idem');
        $this->issueSales($workspace, $this->customer($workspace, 'Mysql Idem Buyer'), (int) $owner->id, '5000.00');
        $token = $owner->createToken('concurrency')->plainTextToken;
        $key = 'mysql-ask-'.uniqid('', true);

        $conversation = $this->workspaceContext()->runFor($workspace, function () use ($workspace, $owner): FinanceAiConversation {
            return FinanceAiConversation::query()->create([
                'workspace_id' => $workspace->id,
                'user_id' => $owner->id,
                'status' => FinanceAiConversation::STATUS_ACTIVE,
                'locale' => 'ar',
            ]);
        });

        $results = $this->spawnWorkers([
            [
                'token' => $token,
                'workspace_id' => $workspace->id,
                'conversation_id' => $conversation->id,
                'question' => 'كم مبيعاتنا اليوم؟',
                'idempotency_key' => $key,
                'prefix' => 'k1',
                'delay_ms' => 0,
            ],
            [
                'token' => $token,
                'workspace_id' => $workspace->id,
                'conversation_id' => $conversation->id,
                'question' => 'كم مبيعاتنا اليوم؟',
                'idempotency_key' => $key,
                'prefix' => 'k2',
                'delay_ms' => 20,
            ],
        ]);

        $statuses = array_column($results, 'status');
        $this->assertContains(200, $statuses, json_encode($results, JSON_UNESCAPED_UNICODE));
        foreach ($statuses as $status) {
            $this->assertContains($status, [200, 409]);
        }

        $okBodies = collect($results)
            ->where('status', 200)
            ->pluck('body.data.conversation_id')
            ->unique()
            ->values()
            ->all();
        $this->assertSame([(int) $conversation->id], array_map('intval', $okBodies));

        $this->assertSame(1, FinanceAiMessage::queryForWorkspace((int) $workspace->id)
            ->where('conversation_id', $conversation->id)
            ->where('role', FinanceAiMessage::ROLE_USER)
            ->count());
        $this->assertSame(1, (int) (WorkspaceUsageMeter::withoutGlobalScopes()
            ->where('workspace_id', $workspace->id)
            ->where('meter_key', 'ai_usage')
            ->value('used') ?? 0));

        $geminiCalls = array_sum(array_column($results, 'gemini_calls'));
        $this->assertLessThanOrEqual(4, $geminiCalls);
        $this->assertGreaterThan(0, $geminiCalls);
    }

    private function usingServerDatabase(): bool
    {
        $connection = (string) config('database.default');
        $database = (string) config('database.connections.'.$connection.'.database');

        return in_array($connection, ['mysql', 'mariadb', 'pgsql'], true) && $database !== ':memory:';
    }

    /**
     * @param  list<array<string, mixed>>  $payloads
     * @return list<array{status:int,body:?array,pid:?int,gemini_calls:int}>
     */
    private function spawnWorkers(array $payloads): array
    {
        $worker = base_path('tests/bin/finance-assistant-ask-worker.php');
        $processes = [];
        $pipesList = [];

        foreach ($payloads as $index => $payload) {
            $descriptors = [
                0 => ['pipe', 'r'],
                1 => ['pipe', 'w'],
                2 => ['pipe', 'w'],
            ];
            $process = proc_open(
                [PHP_BINARY, $worker],
                $descriptors,
                $pipes,
                base_path(),
            );
            $this->assertIsResource($process);
            fwrite($pipes[0], json_encode($payload, JSON_UNESCAPED_UNICODE));
            fclose($pipes[0]);
            $processes[$index] = $process;
            $pipesList[$index] = $pipes;
        }

        $results = [];
        foreach ($processes as $index => $process) {
            $stdout = stream_get_contents($pipesList[$index][1]) ?: '';
            $stderr = stream_get_contents($pipesList[$index][2]) ?: '';
            fclose($pipesList[$index][1]);
            fclose($pipesList[$index][2]);
            $code = proc_close($process);
            $decoded = json_decode($stdout, true);
            $this->assertSame(0, $code, $stderr !== '' ? $stderr : $stdout);
            $this->assertIsArray($decoded, $stdout);
            $results[] = [
                'status' => (int) ($decoded['status'] ?? 0),
                'body' => is_array($decoded['body'] ?? null) ? $decoded['body'] : null,
                'pid' => $decoded['pid'] ?? null,
                'gemini_calls' => (int) ($decoded['gemini_calls'] ?? 0),
                'stdout' => $stdout,
                'stderr' => $stderr,
            ];
        }

        return $results;
    }

    private function workspaceContext(): WorkspaceContext
    {
        return app(WorkspaceContext::class);
    }

    /**
     * @return array{0: User, 1: Workspace}
     */
    private function createWorkspaceOwner(string $name): array
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
        foreach (['finance', 'products', 'orders', 'customers', 'payments', 'ai'] as $feature) {
            WorkspaceFeatureFlag::withoutGlobalScopes()->updateOrCreate(
                ['workspace_id' => $workspace->id, 'feature_key' => $feature],
                ['workspace_id' => $workspace->id, 'feature_key' => $feature, 'enabled' => true, 'source' => 'manual']
            );
        }
        $plan = $this->ensureCompanyPlan();
        Subscription::withoutGlobalScopes()->create([
            'workspace_id' => $workspace->id,
            'plan_id' => $plan->id,
            'status' => 'active',
            'starts_at' => now(),
            'current_period_start' => now(),
            'current_period_end' => now()->addMonth(),
        ]);
        app(FinanceBootstrapService::class)->ensureWorkspaceFinanceSetup($workspace);
        app(PermissionRegistrar::class)->setPermissionsTeamId($workspace->id);

        return [$user, $workspace->fresh()];
    }

    private function ensureCompanyPlan(): Plan
    {
        $existing = Plan::query()
            ->where('workspace_type', 'company')
            ->where('is_active', true)
            ->orderByDesc('price')
            ->first();
        if ($existing) {
            return $existing;
        }

        return Plan::query()->create([
            'code' => 'pg_company_'.uniqid('', true),
            'name' => 'Company Gate',
            'workspace_type' => 'company',
            'billing_period' => 'monthly',
            'currency' => 'SAR',
            'price' => 99,
            'is_active' => true,
            'is_public' => true,
            'is_official' => true,
            'features' => ['dashboard', 'finance', 'ai'],
            'permissions' => ['workspace.view', 'finance.view'],
            'limits' => [],
        ]);
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
                'product_name' => 'Conv line',
                'quantity' => 1,
                'unit_price' => $amount,
                'discount' => 0,
                'tax_rate' => 0,
                'tax_type' => TaxProfileType::ZeroRated->value,
            ]],
        ], $actorId);
    }
}
