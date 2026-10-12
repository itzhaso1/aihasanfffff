<?php

declare(strict_types=1);

use App\Services\Subscription\SubscriptionLifecycleService;
use Illuminate\Contracts\Console\Kernel as ConsoleKernel;

require __DIR__.'/../../vendor/autoload.php';

$payload = json_decode(stream_get_contents(STDIN) ?: '{}', true);
if (! is_array($payload)) {
    fwrite(STDERR, "invalid payload\n");
    exit(2);
}

$app = require __DIR__.'/../../bootstrap/app.php';
$app->make(ConsoleKernel::class)->bootstrap();

$delayMs = max(0, (int) ($payload['delay_ms'] ?? 0));
if ($delayMs > 0) {
    usleep($delayMs * 1000);
}

app(SubscriptionLifecycleService::class)->processRenewals();

fwrite(STDOUT, json_encode([
    'ok' => true,
    'pid' => getmypid(),
], JSON_UNESCAPED_UNICODE));
exit(0);
