<?php

namespace App\Http\Controllers\Webhook;

use App\Http\Controllers\Controller;
use App\Services\Observability\OpsMetricsRecorder;
use App\Services\Telegram\TelegramWebhookService;
use App\Services\Telegram\TelegramWebhookVerifier;
use App\Support\Observability\CorrelationContext;
use App\Support\Observability\OpsLogger;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class TelegramWebhookController extends Controller
{
    public function __construct(
        private readonly TelegramWebhookVerifier $verifier,
        private readonly TelegramWebhookService $webhookService,
        private readonly OpsMetricsRecorder $metrics,
    ) {}

    public function handle(Request $request, string $publicWebhookKey): JsonResponse
    {
        $started = microtime(true);

        OpsLogger::info('webhook.telegram.received', array_merge(CorrelationContext::logContext(), [
            'content_length' => strlen($request->getContent()),
        ]));
        $this->metrics->increment('webhook.telegram.received');

        $connection = $this->verifier->connectionForRequest(
            $publicWebhookKey,
            $request->header('X-Telegram-Bot-Api-Secret-Token'),
        );

        if (! $connection) {
            OpsLogger::warning('webhook.telegram.auth_rejected', CorrelationContext::logContext());
            $this->metrics->increment('webhook.telegram.auth_failed');

            return response()->json(['message' => 'Unauthorized'], 403);
        }

        $update = $request->all();
        $this->webhookService->processUpdate(
            $connection,
            is_array($update) ? $update : [],
            $this->headers($request),
        );

        $this->metrics->timing('webhook.telegram.accept_ms', (int) round((microtime(true) - $started) * 1000));

        return response()->json(['ok' => true], 200);
    }

    /**
     * @return array<string, string>
     */
    private function headers(Request $request): array
    {
        return collect($request->headers->all())
            ->map(fn ($value) => is_array($value) ? ($value[0] ?? '') : (string) $value)
            ->all();
    }
}
