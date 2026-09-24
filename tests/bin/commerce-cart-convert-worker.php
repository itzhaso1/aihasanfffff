<?php

declare(strict_types=1);

use App\Models\Commerce\CommerceCart;
use App\Models\Workspace;
use App\Services\Commerce\CommerceOrderService;
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

try {
    $workspaceId = (int) ($payload['workspace_id'] ?? 0);
    $cartId = (int) ($payload['cart_id'] ?? 0);
    $key = (string) ($payload['idempotency_key'] ?? '');
    $workspace = Workspace::query()->findOrFail($workspaceId);

    $result = app(WorkspaceContext::class)->runFor($workspace, function () use ($workspace, $cartId, $key): array {
        $cart = CommerceCart::query()->whereKey($cartId)->firstOrFail();
        $order = app(CommerceOrderService::class)->createOrder($workspace, $cart, $key);

        return ['ok' => true, 'order_id' => $order->id];
    });

    fwrite(STDOUT, json_encode($result, JSON_UNESCAPED_UNICODE));
} catch (Throwable $exception) {
    fwrite(STDOUT, json_encode(['ok' => false, 'error' => $exception->getMessage()], JSON_UNESCAPED_UNICODE));
}

exit(0);
