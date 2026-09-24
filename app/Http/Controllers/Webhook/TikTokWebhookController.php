<?php

namespace App\Http\Controllers\Webhook;

use App\Http\Controllers\Controller;
use App\Services\Observability\OpsMetricsRecorder;
use App\Services\TikTok\TikTokWebhookService;
use App\Services\TikTok\TikTokWebhookSignatureVerifier;
use App\Support\Observability\CorrelationContext;
use App\Support\Observability\OpsLogger;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class TikTokWebhookController extends Controller
{
    public function __construct(
        private readonly TikTokWebhookSignatureVerifier $signatureVerifier,
        private readonly TikTokWebhookService $webhookService,
        private readonly OpsMetricsRecorder $metrics,
    ) {}

    public function handle(Request $request): JsonResponse
    {
        $started = microtime(true);
        $raw = $request->getContent();

        OpsLogger::info('webhook.tiktok.received', array_merge(CorrelationContext::logContext(), [
            'content_length' => strlen($raw),
        ]));
        $this->metrics->increment('webhook.tiktok.received');

        if (! $this->signatureVerifier->isValid($raw, $request->header('TikTok-Signature'))) {
            OpsLogger::warning('webhook.tiktok.signature_rejected', CorrelationContext::logContext());
            $this->metrics->increment('webhook.tiktok.signature_failed');

            return response()->json(['message' => 'Invalid signature'], 403);
        }

        $payload = $request->all();
        if ($payload === [] && $raw !== '') {
            $decoded = json_decode($raw, true);
            $payload = is_array($decoded) ? $decoded : [];
        }

        $this->webhookService->processWebhook($payload, $this->headers($request));

        $this->metrics->timing('webhook.tiktok.accept_ms', (int) round((microtime(true) - $started) * 1000));

        return response()->json(['received' => true], 200);
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
