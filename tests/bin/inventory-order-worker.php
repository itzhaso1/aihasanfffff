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
$delayMs = max(0, (int) ($payload['delay_ms'] ?? 0));

if ($delayMs > 0) {
    usleep($delayMs * 1000);
}

$workspace = Workspace::query()->findOrFail($workspaceId);
$product = Product::withoutGlobalScopes()->where('workspace_id', $workspaceId)->findOrFail($productId);

try {
    $result = app(WorkspaceContext::class)->runFor($workspace, function () use ($workspace, $product): array {
        $created = app(OrderService::class)->create([
            'workspace_id' => $workspace->id,
            'items' => [[
                'product_id' => $product->id,
                'quantity' => 1,
            ]],
        ]);

        return [
            'ok' => true,
            'order_id' => $created->id,
            'pid' => getmypid(),
        ];
    });
} catch (Throwable $exception) {
    $result = [
        'ok' => false,
        'error' => $exception->getMessage(),
        'pid' => getmypid(),
    ];
}

fwrite(STDOUT, json_encode($result, JSON_UNESCAPED_UNICODE));
exit(0);
