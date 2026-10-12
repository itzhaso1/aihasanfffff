<?php

declare(strict_types=1);

use App\Models\Order;
use App\Models\Workspace;
use App\Services\Payment\PaymentService;
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
$orderId = (int) ($payload['order_id'] ?? 0);
$delayMs = max(0, (int) ($payload['delay_ms'] ?? 0));

if ($delayMs > 0) {
    usleep($delayMs * 1000);
}

$workspace = Workspace::query()->findOrFail($workspaceId);
$order = Order::withoutGlobalScopes()->where('workspace_id', $workspaceId)->findOrFail($orderId);

$result = app(WorkspaceContext::class)->runFor($workspace, function () use ($order): array {
    $payment = app(PaymentService::class)->createPaymentLink($order, null, 'local_sandbox');

    return [
        'payment_id' => $payment->id,
        'pid' => getmypid(),
    ];
});

fwrite(STDOUT, json_encode($result, JSON_UNESCAPED_UNICODE));
exit(0);
