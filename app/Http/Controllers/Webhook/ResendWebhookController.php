<?php

namespace App\Http\Controllers\Webhook;

use App\Http\Controllers\Controller;
use App\Services\Email\ResendWebhookService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

class ResendWebhookController extends Controller
{
    public function __construct(
        private readonly ResendWebhookService $resendWebhookService,
    ) {}

    public function handle(Request $request): JsonResponse
    {
        $rawBody = $request->getContent();
        $payload = json_decode($rawBody, true);
        if (! is_array($payload)) {
            return response()->json([
                'ok' => false,
                'message' => 'حمولة الـwebhook غير صالحة.',
            ], 422);
        }

        $verification = $this->resendWebhookService->verifySignature(
            headers: [
                'svix-id' => (string) $request->header('svix-id', ''),
                'svix-timestamp' => (string) $request->header('svix-timestamp', ''),
                'svix-signature' => (string) $request->header('svix-signature', ''),
            ],
            rawBody: $rawBody,
        );

        if (! $verification['verified']) {
            Log::warning('resend.webhook.rejected', [
                'reason' => $verification['reason'],
            ]);

            return response()->json([
                'ok' => false,
                'message' => 'تعذر التحقق من توقيع الـwebhook.',
            ], 401);
        }

        $event = $this->resendWebhookService->handle(
            $payload,
            (string) $request->header('svix-id', ''),
        );

        return response()->json([
            'ok' => true,
            'event_id' => $event->id,
        ], 202);
    }
}
