<?php

namespace Tests\Feature\Feature\FinancialIntegrity;

use App\Models\Customer;
use App\Models\Order;
use App\Models\Product;
use App\Models\User;
use App\Models\Workspace;
use App\Services\Finance\FinanceBootstrapService;
use App\Support\Money\Money;
use App\Support\Tenancy\WorkspaceContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

class CustomerAggregateConcurrencyTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        if (! $this->usingServerDatabase()) {
            $this->markTestSkipped('Customer aggregate concurrency requires mysql/mariadb.');
        }
    }

    /**
     * @return list<string>
     */
    protected function connectionsToTransact(): array
    {
        return $this->usingServerDatabase() ? [] : [(string) config('database.default')];
    }

    public function test_concurrent_orders_refresh_customer_aggregates_without_lost_updates(): void
    {
        [$owner, $workspace] = $this->makeWorkspace('CustAgg');
        $customer = Customer::withoutGlobalScopes()->create([
            'workspace_id' => $workspace->id,
            'name' => 'Concurrent Buyer',
            'phone' => '0500000099',
        ]);
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
        $payloads = array_map(
            fn (int $index): array => [
                'workspace_id' => $workspace->id,
                'product_id' => $product->id,
                'customer_id' => $customer->id,
                'index' => $index,
            ],
            range(1, 50),
        );

        $results = $this->spawnWorkers($worker, $payloads);
        $this->assertCount(50, $results);

        $customer = Customer::withoutGlobalScopes()->findOrFail($customer->id);
        $this->assertSame(50, Order::withoutGlobalScopes()->where('customer_id', $customer->id)->count());
        $this->assertSame(50, $customer->orders_count);
        $this->assertSame(0, Money::cmp('500.00', (string) $customer->total_purchases));
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
