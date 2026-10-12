<?php

namespace App\Services\Meta;

use App\Support\Webhooks\WebhookCredential;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

enum MetaWebhookCredentialSource
{
    case WhatsAppLegacy;

    case MetaUnified;
}

class MetaWebhookSignatureVerifier
{
    public function verifySubscription(
        Request $request,
        MetaWebhookCredentialSource $source = MetaWebhookCredentialSource::MetaUnified,
    ): Response {
        $mode = $request->query('hub.mode', $request->query('hub_mode'));
        $token = $request->query('hub.verify_token', $request->query('hub_verify_token'));
        $challenge = $request->query('hub.challenge', $request->query('hub_challenge'));
        $expected = WebhookCredential::configured($this->webhookVerifyToken($source));
        $provided = is_string($token) ? WebhookCredential::configured($token) : null;

        if ($mode === 'subscribe' && $expected !== null && $provided !== null && hash_equals($expected, $provided)) {
            return response($challenge, 200);
        }

        return response('Forbidden', 403);
    }

    public function isValidPostSignature(
        Request $request,
        MetaWebhookCredentialSource $source = MetaWebhookCredentialSource::MetaUnified,
    ): bool {
        return $this->postSignatureFailureReason($request, $source) === null;
    }

    public function postSignatureFailureReason(
        Request $request,
        MetaWebhookCredentialSource $source = MetaWebhookCredentialSource::MetaUnified,
    ): ?string {
        $secret = WebhookCredential::configured($this->appSecret($source));
        if ($secret === null) {
            return 'app_secret_not_configured';
        }

        $signature = $request->header('X-Hub-Signature-256');
        if (! is_string($signature)) {
            return 'missing_signature_header';
        }

        if (! str_starts_with($signature, 'sha256=')) {
            return 'invalid_signature_header_format';
        }

        $hash = hash_hmac('sha256', $request->getContent(), $secret);

        if (! hash_equals('sha256='.$hash, $signature)) {
            return 'signature_mismatch';
        }

        return null;
    }

    private function webhookVerifyToken(MetaWebhookCredentialSource $source): ?string
    {
        if ($source === MetaWebhookCredentialSource::WhatsAppLegacy) {
            $legacy = config('whatsapp.verify_token');

            return is_string($legacy) ? $legacy : null;
        }

        $meta = config('meta.webhook_verify_token');
        if (is_string($meta) && trim($meta) !== '') {
            return $meta;
        }

        $legacy = config('whatsapp.verify_token');

        return is_string($legacy) ? $legacy : null;
    }

    private function appSecret(MetaWebhookCredentialSource $source): ?string
    {
        if ($source === MetaWebhookCredentialSource::WhatsAppLegacy) {
            $legacy = config('whatsapp.app_secret');

            return is_string($legacy) ? $legacy : null;
        }

        $meta = config('meta.app_secret');
        if (is_string($meta) && trim($meta) !== '') {
            return $meta;
        }

        $legacy = config('whatsapp.app_secret');

        return is_string($legacy) ? $legacy : null;
    }
}
