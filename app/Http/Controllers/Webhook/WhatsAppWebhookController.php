<?php

namespace App\Http\Controllers\Webhook;

use App\Http\Controllers\Controller;
use App\Services\Meta\MetaWebhookCredentialSource;
use App\Services\Meta\MetaWebhookRouter;
use App\Services\Meta\MetaWebhookSignatureVerifier;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class WhatsAppWebhookController extends Controller
{
    public function __construct(
        private readonly MetaWebhookSignatureVerifier $signatureVerifier,
        private readonly MetaWebhookRouter $webhookRouter,
    ) {}

    public function verify(Request $request): Response
    {
        return $this->signatureVerifier->verifySubscription(
            $request,
            MetaWebhookCredentialSource::WhatsAppLegacy,
        );
    }

    public function handle(Request $request): JsonResponse
    {
        if (! $this->signatureVerifier->isValidPostSignature(
            $request,
            MetaWebhookCredentialSource::WhatsAppLegacy,
        )) {
            return response()->json(['message' => 'Invalid signature'], 403);
        }

        $this->webhookRouter->dispatch(
            payload: $request->all(),
            headers: collect($request->headers->all())->map(fn ($value) => is_array($value) ? ($value[0] ?? '') : $value)->all(),
        );

        return response()->json(['received' => true], 202);
    }
}
