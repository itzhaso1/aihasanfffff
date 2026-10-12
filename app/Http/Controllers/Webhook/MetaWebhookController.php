<?php

namespace App\Http\Controllers\Webhook;

use App\Http\Controllers\Controller;
use App\Services\Meta\MetaWebhookCredentialSource;
use App\Services\Meta\MetaWebhookRouter;
use App\Services\Meta\MetaWebhookSignatureVerifier;
use App\Services\Observability\OpsMetricsRecorder;
use App\Support\Observability\CorrelationContext;
use App\Support\Observability\OpsLogger;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class MetaWebhookController extends Controller
{
    public function __construct(
        private readonly MetaWebhookSignatureVerifier $signatureVerifier,
        private readonly MetaWebhookRouter $webhookRouter,
        private readonly OpsMetricsRecorder $metrics,
    ) {}

    public function verify(Request $request): Response
    {
        return $this->signatureVerifier->verifySubscription(
            $request,
            MetaWebhookCredentialSource::MetaUnified,
        );
    }

    public function handle(Request $request): JsonResponse
    {
        $started = microtime(true);
        $payloadObject = $request->input('object');

        OpsLogger::info('webhook.meta.received', array_merge(CorrelationContext::logContext(), [
            'object' => is_string($payloadObject) ? $payloadObject : null,
            'entry_count' => is_array($request->input('entry')) ? count($request->input('entry')) : 0,
            'content_length' => strlen($request->getContent()),
        ]));
        $this->metrics->increment('webhook.meta.received', [
            'object' => is_string($payloadObject) ? $payloadObject : 'unknown',
        ]);

        $signatureFailure = $this->signatureVerifier->postSignatureFailureReason(
            $request,
            MetaWebhookCredentialSource::MetaUnified,
        );

        if ($signatureFailure !== null) {
            OpsLogger::warning('webhook.meta.signature_rejected', array_merge(CorrelationContext::logContext(), [
                'reason' => $signatureFailure,
                'object' => is_string($payloadObject) ? $payloadObject : null,
            ]));
            $this->metrics->increment('webhook.meta.signature_failed');

            return response()->json(['message' => 'Invalid signature'], 403);
        }

        $this->webhookRouter->dispatch(
            payload: $request->all(),
            headers: collect($request->headers->all())->map(fn ($value) => is_array($value) ? ($value[0] ?? '') : $value)->all(),
        );

        $this->metrics->timing('webhook.meta.accept_ms', (int) round((microtime(true) - $started) * 1000));
        $this->metrics->increment('webhook.meta.accepted', ['http_status' => 202]);

        return response()->json(['received' => true], 202);
    }
}
