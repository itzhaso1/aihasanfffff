<?php

namespace App\Http\Controllers\Webhook;

use App\Http\Controllers\Controller;
use App\Services\WhatsApp\WhatsAppService;
use App\Support\Webhooks\WebhookCredential;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class WhatsAppWebhookController extends Controller
{
    public function __construct(
        private readonly WhatsAppService $whatsAppService,
    ) {}

    public function verify(Request $request): Response
    {
        $mode = $request->query('hub.mode', $request->query('hub_mode'));
        $token = $request->query('hub.verify_token', $request->query('hub_verify_token'));
        $challenge = $request->query('hub.challenge', $request->query('hub_challenge'));
        $expected = WebhookCredential::configured(config('whatsapp.verify_token'));
        $provided = is_string($token) ? WebhookCredential::configured($token) : null;

        if ($mode === 'subscribe' && $expected !== null && $provided !== null && hash_equals($expected, $provided)) {
            return response($challenge, 200);
        }

        return response('Forbidden', 403);
    }

    public function handle(Request $request): JsonResponse
    {
        if (! $this->isValidSignature($request)) {
            return response()->json(['message' => 'Invalid signature'], 403);
        }

        $this->whatsAppService->processWebhook(
            payload: $request->all(),
            headers: collect($request->headers->all())->map(fn ($value) => is_array($value) ? ($value[0] ?? '') : $value)->all()
        );

        return response()->json(['received' => true], 202);
    }

    private function isValidSignature(Request $request): bool
    {
        $secret = WebhookCredential::configured(config('whatsapp.app_secret'));
        if ($secret === null) {
            return false;
        }

        $signature = $request->header('X-Hub-Signature-256');
        if (! $signature || ! str_starts_with($signature, 'sha256=')) {
            return false;
        }

        $hash = hash_hmac('sha256', $request->getContent(), $secret);

        return hash_equals('sha256='.$hash, $signature);
    }
}
