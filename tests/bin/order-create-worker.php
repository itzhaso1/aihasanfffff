<?php

declare(strict_types=1);

use App\Models\Product;
use App\Models\Workspace;
use App\Services\Order\OrderService;
use App\Support\Tenancy\WorkspaceContext;
use Illuminate\Contracts\Console\Kernel as ConsoleKernel;

require __DIR__.'/../../vendor/autoload.php';

$payload = json_decode(stream_get_contents(STDIN) ?: '{}', true);
if (! is_array($payload)) {
    fwrite(STDERR, "invalid payload\n");
    exit(2);
}

$app = require __DIR__.'/../../bootstrap/app.php';
$app->make(ConsoleKernel::class)->bootstrap();

$workspaceId = (int) ($payload['workspace_id'] ?? 0);
$productId = (int) ($payload['product_id'] ?? 0);
$customerId = isset($payload['customer_id']) ? (int) $payload['customer_id'] : null;

$workspace = Workspace::query()->findOrFail($workspaceId);
$product = Product::withoutGlobalScopes()->where('workspace_id', $workspaceId)->findOrFail($productId);

$order = app(WorkspaceContext::class)->runFor($workspace, function () use ($workspace, $product, $customerId): array {
    $created = app(OrderService::class)->create([
        'workspace_id' => $workspace->id,
        'customer_id' => $customerId,
        'items' => [[
            'product_id' => $product->id,
            'quantity' => 1,
            'unit_price' => (float) $product->price,
        ]],
    ]);

    return [
        'id' => $created->id,
        'workspace_id' => $workspace->id,
        'order_number' => $created->order_number,
        'pid' => getmypid(),
    ];
});

fwrite(STDOUT, json_encode($order, JSON_UNESCAPED_UNICODE));
exit(0);
