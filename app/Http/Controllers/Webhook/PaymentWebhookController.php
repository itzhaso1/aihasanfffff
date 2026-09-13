<?php

namespace App\Http\Controllers\Webhook;

use App\Http\Controllers\Controller;
use App\Jobs\ProcessPaymentWebhook;
use App\Services\Payment\PaymentGatewayManager;
use App\Support\Webhooks\WebhookRequest;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

class PaymentWebhookController extends Controller
{
    /** @var list<string> */
    private const PROVIDERS = ['local', 'stripe'];

    public function __construct(
        private readonly PaymentGatewayManager $paymentGatewayManager,
    ) {}

    public function handle(Request $request, string $provider): JsonResponse
    {
        $provider = strtolower($provider);
        $headers = WebhookRequest::flattenHeaders($request);
        $rawBody = $request->getContent();
        $payload = $request->all();

        if (! in_array($provider, self::PROVIDERS, true)) {
            Log::warning('payment.webhook.rejected', [
                'provider' => $provider,
                'reason' => 'unknown_provider',
            ]);

            return $this->rejected();
        }

        $verification = $this->paymentGatewayManager
            ->resolveByName($provider)
            ->verifyWebhook($headers, $payload, $rawBody);

        if (! ($verification['verified'] ?? false)) {
            Log::warning('payment.webhook.rejected', [
                'provider' => $provider,
                'reason' => $verification['reason'] ?? 'unverified',
            ]);

            return $this->rejected();
        }

        ProcessPaymentWebhook::dispatch(
            provider: $provider,
            headers: $headers,
            payload: $payload,
            rawBody: $rawBody,
        );

        return response()->json(['received' => true], 202);
    }

    private function rejected(): JsonResponse
    {
        return response()->json([
            'received' => false,
            'message' => 'Webhook authentication failed.',
        ], 401);
    }
}
