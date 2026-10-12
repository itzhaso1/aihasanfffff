<?php

namespace App\Http\Controllers\Webhook;

use App\Http\Controllers\Controller;
use App\Jobs\ProcessSubscriptionWebhook;
use App\Services\Subscription\SubscriptionWebhookProcessor;
use App\Support\Webhooks\WebhookRequest;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

class SubscriptionWebhookController extends Controller
{
    public function __construct(
        private readonly SubscriptionWebhookProcessor $subscriptionWebhookProcessor,
    ) {}

    public function handle(Request $request, string $provider): JsonResponse
    {
        $provider = strtolower($provider);
        $headers = WebhookRequest::flattenHeaders($request);
        $rawBody = $request->getContent();
        $payload = $request->all();

        $verification = $this->subscriptionWebhookProcessor->verify(
            $provider,
            $headers,
            $payload,
            $rawBody,
        );

        if (! $verification->verified) {
            Log::warning('subscription.webhook.rejected', [
                'provider' => $provider,
                'reason' => $verification->reason,
            ]);

            return response()->json([
                'received' => false,
                'message' => 'Webhook authentication failed.',
            ], 401);
        }

        ProcessSubscriptionWebhook::dispatch(
            provider: $provider,
            headers: $headers,
            payload: $payload,
            rawBody: $rawBody,
        );

        return response()->json(['received' => true], 202);
    }
}
