<?php

namespace App\Services\TikTok;

final class TikTokWebhookSignatureVerifier
{
    public function isValid(string $rawBody, ?string $signatureHeader): bool
    {
        $secret = (string) config('tiktok.client_secret');
        if ($secret === '' || $signatureHeader === null || trim($signatureHeader) === '') {
            return false;
        }

        $timestamp = null;
        $signature = null;
        foreach (explode(',', $signatureHeader) as $part) {
            [$prefix, $value] = array_pad(explode('=', trim($part), 2), 2, null);
            if ($prefix === 't') {
                $timestamp = $value;
            }
            if ($prefix === 's') {
                $signature = $value;
            }
        }

        if ($timestamp === null || $signature === null) {
            return false;
        }

        $maxSkew = (int) config('tiktok.webhook_max_skew_seconds', 300);
        if (abs(time() - (int) $timestamp) > $maxSkew) {
            return false;
        }

        $expected = hash_hmac('sha256', $timestamp.'.'.$rawBody, $secret);

        return hash_equals($expected, (string) $signature);
    }
}
