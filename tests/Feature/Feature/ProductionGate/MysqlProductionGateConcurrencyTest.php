<?php

namespace Tests\Feature\Feature\ProductionGate;

use App\Models\Order;
use App\Models\Payment;
use App\Models\Plan;
use App\Models\Product;
use App\Models\Subscription;
use App\Models\SubscriptionBillingAttempt;
use App\Models\User;
use App\Models\Workspace;
use App\Services\Finance\FinanceBootstrapService;
use App\Services\Payment\PaymentService;
use App\Support\Tenancy\WorkspaceContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

class MysqlProductionGateConcurrencyTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        if (! $this->usingServerDatabase()) {
            $this->markTestSkipped('Production gate MySQL concurrency requires mysql/mariadb.');
        }
    }

    /**
     * @return list<string>
     */
    protected function connectionsToTransact(): array
    {
        return $this->usingServerDatabase() ? [] : [(string) config('database.default')];
    }

    public function test_one_hundred_concurrent_order_creates_yield_unique_numbers(): void
    {
        [$owner, $workspace] = $this->makeWorkspace('Orders100');
        $product = Product::factory()->create([
            'workspace_id' => $workspace->id,
            'category_id' => null,
            'price' => 10,
            'currency' => 'SAR',
            'status' => 'active',
            'inventory_tracking' => false,
        ]);
        DB::table('workspace_order_sequences')->insertOrIgnore([
            'workspace_id' => $workspace->id,
            'next_commerce_sequence' => 1,
            'next_pos_sequence' => 1,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $worker = base_path('tests/bin/order-create-worker.php');
        $results = $this->spawnWorkers($worker, array_map(
            fn (int $index): array => [
                'workspace_id' => $workspace->id,
                'product_id' => $product->id,
                'index' => $index,
            ],
            range(1, 100),
        ));

        $numbers = array_column($results, 'order_number');
        $this->assertCount(100, $results);
        $this->assertCount(100, array_unique($numbers));
        $this->assertSame(100, Order::withoutGlobalScopes()->where('workspace_id', $workspace->id)->count());
    }

    public function test_cross_workspace_concurrent_orders_do_not_share_sequences(): void
    {
        [$ownerA, $workspaceA] = $this->makeWorkspace('OrdersA');
        [$ownerB, $workspaceB] = $this->makeWorkspace('OrdersB');
        app(WorkspaceContext::class)->clear();
        $productA = Product::factory()->create([
            'workspace_id' => $workspaceA->id,
            'category_id' => null,
            'price' => 5,
            'currency' => 'SAR',
            'status' => 'active',
            'inventory_tracking' => false,
        ]);
        $productB = Product::factory()->create([
            'workspace_id' => $workspaceB->id,
            'category_id' => null,
            'price' => 5,
            'currency' => 'SAR',
            'status' => 'active',
            'inventory_tracking' => false,
        ]);
        foreach ([$workspaceA->id, $workspaceB->id] as $workspaceId) {
            DB::table('workspace_order_sequences')->insertOrIgnore([
                'workspace_id' => $workspaceId,
                'next_commerce_sequence' => 1,
                'next_pos_sequence' => 1,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }

        $worker = base_path('tests/bin/order-create-worker.php');
        $payloads = [];
        for ($i = 0; $i < 25; $i++) {
            $payloads[] = ['workspace_id' => $workspaceA->id, 'product_id' => $productA->id];
            $payloads[] = ['workspace_id' => $workspaceB->id, 'product_id' => $productB->id];
        }

        $results = $this->spawnWorkers($worker, $payloads);
        $numbersA = [];
        $numbersB = [];
        foreach ($results as $row) {
            if ((int) ($row['workspace_id'] ?? 0) === (int) $workspaceA->id) {
                $numbersA[] = $row['order_number'];
            } else {
                $numbersB[] = $row['order_number'];
            }
        }

        $this->assertCount(25, array_unique($numbersA));
        $this->assertCount(25, array_unique($numbersB));
        $this->assertSame(25, Order::withoutGlobalScopes()->where('workspace_id', $workspaceA->id)->count());
        $this->assertSame(25, Order::withoutGlobalScopes()->where('workspace_id', $workspaceB->id)->count());
    }

    public function test_concurrent_payment_link_workers_reuse_single_pending_payment(): void
    {
        [$owner, $workspace] = $this->makeWorkspace('PayConcurrent');
        $order = Order::withoutGlobalScopes()->create([
            'workspace_id' => $workspace->id,
            'order_number' => 'ORD-GATE-00000001',
            'source' => 'manual',
            'status' => 'confirmed',
            'payment_status' => 'pending',
            'currency' => 'SAR',
            'subtotal' => 50,
            'discount_amount' => 0,
            'shipping_amount' => 0,
            'total_amount' => 50,
            'placed_at' => now(),
        ]);

        $worker = base_path('tests/bin/payment-link-worker.php');
        $results = $this->spawnWorkers($worker, [
            ['workspace_id' => $workspace->id, 'order_id' => $order->id, 'delay_ms' => 0],
            ['workspace_id' => $workspace->id, 'order_id' => $order->id, 'delay_ms' => 25],
        ]);

        $paymentIds = array_column($results, 'payment_id');
        $this->assertCount(2, $paymentIds);
        $this->assertSame($paymentIds[0], $paymentIds[1]);
        $this->assertSame(1, Payment::withoutGlobalScopes()->where('order_id', $order->id)->count());

        $payment = Payment::withoutGlobalScopes()->findOrFail($paymentIds[0]);
        $this->assertSame(
            PaymentService::orderCheckoutIdempotencyKey($workspace->id, $order->id),
            $payment->idempotency_key,
        );
    }

    public function test_concurrent_renewal_workers_do_not_duplicate_billing_attempts(): void
    {
        [$owner, $workspace] = $this->makeWorkspace('RenewConcurrent');
        $plan = Plan::query()->create([
            'code' => 'renew_gate_'.uniqid('', true),
            'name' => 'Renew Gate',
            'workspace_type' => 'company',
            'billing_period' => 'monthly',
            'currency' => 'SAR',
            'price' => 99,
            'is_active' => true,
            'is_public' => true,
            'is_official' => true,
            'features' => ['dashboard'],
            'permissions' => ['workspace.view'],
            'limits' => [],
        ]);
        Subscription::withoutGlobalScopes()->create([
            'workspace_id' => $workspace->id,
            'plan_id' => $plan->id,
            'status' => Subscription::STATUS_ACTIVE,
            'starts_at' => now()->subMonth(),
            'current_period_start' => now()->subMonth(),
            'current_period_end' => now()->addDay(),
            'running_guard' => 'running',
        ]);

        $worker = base_path('tests/bin/subscription-renewal-worker.php');
        $this->spawnWorkers($worker, [
            ['workspace_id' => $workspace->id],
            ['workspace_id' => $workspace->id, 'delay_ms' => 20],
        ]);

        $this->assertSame(1, SubscriptionBillingAttempt::withoutGlobalScopes()
            ->where('workspace_id', $workspace->id)
            ->where('purpose', SubscriptionBillingAttempt::PURPOSE_RENEWAL)
            ->count());
    }

    /**
     * @param  list<array<string, mixed>>  $payloads
     * @return list<array<string, mixed>>
     */
    private function spawnWorkers(string $workerScript, array $payloads): array
    {
        $processes = [];
        $pipesList = [];

        foreach ($payloads as $index => $payload) {
            $descriptors = [
                0 => ['pipe', 'r'],
                1 => ['pipe', 'w'],
                2 => ['pipe', 'w'],
            ];
            $process = proc_open(
                [PHP_BINARY, $workerScript],
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
            $results[] = $decoded;
        }

        return $results;
    }

    private function usingServerDatabase(): bool
    {
        $connection = (string) config('database.default');
        $database = (string) config('database.connections.'.$connection.'.database');

        return in_array($connection, ['mysql', 'mariadb', 'pgsql'], true) && $database !== ':memory:';
    }

    /**
     * @return array{0: User, 1: Workspace}
     */
    private function makeWorkspace(string $name): array
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
            $this->enableWorkspaceFeature($workspace, $feature);
        }
        app(FinanceBootstrapService::class)->ensureWorkspaceFinanceSetup($workspace);
        app(PermissionRegistrar::class)->setPermissionsTeamId($workspace->id);

        return [$user, $workspace->fresh()];
    }
}
