<?php

namespace Tests\Feature\Feature\Commerce;

use App\Enums\ChatCatalog\ChatCatalogInventoryPolicy;
use App\Enums\ChatCatalog\ChatCatalogOperatingStatus;
use App\Models\ChatCatalogItem;
use App\Models\Commerce\Seller;
use App\Models\Order;
use App\Models\Workspace;
use App\Services\Chat\ChatCatalogRootService;
use App\Services\Commerce\CommerceCartService;
use App\Support\Tenancy\WorkspaceContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class CommerceOrderConcurrencyTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        if (! $this->usingServerDatabase()) {
            $this->markTestSkipped('Commerce conversion concurrency requires mysql/mariadb/pgsql.');
        }
    }

    /**
     * @return list<string>
     */
    protected function connectionsToTransact(): array
    {
        return $this->usingServerDatabase() ? [] : [(string) config('database.default')];
    }

    public function test_concurrent_same_cart_conversion_creates_one_order_and_one_reservation(): void
    {
        $workspace = Workspace::factory()->create(['name' => 'Commerce race']);
        $seller = Seller::factory()->create(['workspace_id' => $workspace->id]);
        $item = app(WorkspaceContext::class)->runFor($workspace, function () use ($workspace, $seller): ChatCatalogItem {
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
                'name' => 'Last commerce unit',
                'price' => '50.00',
                'currency' => 'SAR',
                'classification' => 'physical',
                'inventory_policy' => ChatCatalogInventoryPolicy::FiniteStock,
                'stock' => 1,
                'is_active' => true,
                'is_visible' => true,
                'publication_status' => 'published',
                'published_at' => now(),
                'tax_display_behavior' => 'tax_downstream',
            ]);
        });

        $cartService = app(CommerceCartService::class);
        $cart = $cartService->addItem(
            $workspace,
            $cartService->create($workspace, currency: 'SAR'),
            $item->id,
            null,
            1,
        );

        $results = $this->spawnWorkers(base_path('tests/bin/commerce-cart-convert-worker.php'), [
            ['workspace_id' => $workspace->id, 'cart_id' => $cart->id, 'idempotency_key' => 'concurrent-cart-key-0001'],
            ['workspace_id' => $workspace->id, 'cart_id' => $cart->id, 'idempotency_key' => 'concurrent-cart-key-0001'],
        ]);

        $this->assertCount(2, array_filter($results, fn (array $result): bool => $result['ok'] === true));
        $this->assertCount(1, Order::withoutGlobalScopes()->where('source', 'commerce')->count());
        $order = Order::withoutGlobalScopes()->where('source', 'commerce')->firstOrFail();
        $this->assertSame(1, $order->inventoryReservations()->withoutGlobalScopes()->count());
    }

    /**
     * @param  list<array<string,mixed>>  $payloads
     * @return list<array{ok:bool,order_id?:int,error?:string}>
     */
    private function spawnWorkers(string $workerScript, array $payloads): array
    {
        $processes = [];
        $pipesList = [];
        foreach ($payloads as $index => $payload) {
            $process = proc_open(
                [PHP_BINARY, $workerScript],
                [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
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
            proc_close($process);
            $result = json_decode($stdout, true);
            $this->assertIsArray($result, $stderr !== '' ? $stderr : $stdout);
            $results[] = $result;
        }

        return $results;
    }

    private function usingServerDatabase(): bool
    {
        $connection = (string) config('database.default');
        $database = (string) config('database.connections.'.$connection.'.database');

        return in_array($connection, ['mysql', 'mariadb', 'pgsql'], true) && $database !== ':memory:';
    }
}
