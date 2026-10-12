<?php

declare(strict_types=1);

use App\Services\AI\Contracts\GeminiInteractionsClient;
use App\Services\AI\Gemini\GeminiInteraction;
use App\Services\AI\Gemini\GeminiInteractionRequest;
use App\Services\AI\Gemini\GeminiUsage;
use Illuminate\Contracts\Console\Kernel as ConsoleKernel;
use Illuminate\Contracts\Http\Kernel as HttpKernel;
use Illuminate\Http\Request;
use Tests\Support\FakeGeminiInteractionsClient;

require __DIR__.'/../../vendor/autoload.php';

$payload = json_decode(stream_get_contents(STDIN) ?: '{}', true);
if (! is_array($payload)) {
    fwrite(STDERR, "invalid payload\n");
    exit(2);
}

$app = require __DIR__.'/../../bootstrap/app.php';
$app->make(ConsoleKernel::class)->bootstrap();

config([
    'finance_assistant.agent_enabled' => true,
    'finance_assistant.heuristic_planner_in_testing' => false,
    'services.google_ai_studio.key' => '',
]);

$gemini = new FakeGeminiInteractionsClient;
$prefix = (string) ($payload['prefix'] ?? 'w');
$delayMs = max(0, (int) ($payload['delay_ms'] ?? 0));
$geminiDelayMs = max(0, (int) ($payload['gemini_delay_ms'] ?? 0));
$gemini->queue(function (GeminiInteractionRequest $request) use ($prefix, $geminiDelayMs): GeminiInteraction {
    if ($geminiDelayMs > 0) {
        usleep($geminiDelayMs * 1000);
    }

    return FakeGeminiInteractionsClient::requiring($prefix.'-call', [
        ['name' => 'sales_today'],
    ], new GeminiUsage(3, 1, 4));
})->queue(FakeGeminiInteractionsClient::completed($prefix.'-done', 'مبيعات اليوم 5000.00 ريال.', new GeminiUsage(2, 2, 4)));
$app->instance(GeminiInteractionsClient::class, $gemini);

if ($delayMs > 0) {
    usleep($delayMs * 1000);
}

$conversationId = $payload['conversation_id'] ?? null;
$body = [
    'question' => (string) ($payload['question'] ?? 'كم مبيعاتنا اليوم؟'),
    'locale' => 'ar',
];
if ($conversationId !== null && $conversationId !== '') {
    $body['conversation_id'] = (int) $conversationId;
}

$server = [
    'HTTP_AUTHORIZATION' => 'Bearer '.(string) ($payload['token'] ?? ''),
    'HTTP_X_WORKSPACE_ID' => (string) ($payload['workspace_id'] ?? ''),
    'HTTP_ACCEPT' => 'application/json',
    'CONTENT_TYPE' => 'application/json',
];
if (! empty($payload['idempotency_key'])) {
    $server['HTTP_IDEMPOTENCY_KEY'] = (string) $payload['idempotency_key'];
}

$request = Request::create(
    '/api/finance/v1/assistant/ask',
    'POST',
    [],
    [],
    [],
    $server,
    json_encode($body, JSON_UNESCAPED_UNICODE),
);

$response = $app->make(HttpKernel::class)->handle($request);

fwrite(STDOUT, json_encode([
    'status' => $response->getStatusCode(),
    'body' => json_decode($response->getContent() ?: 'null', true),
    'pid' => getmypid(),
    'gemini_calls' => count($gemini->requests),
], JSON_UNESCAPED_UNICODE));

exit(0);
