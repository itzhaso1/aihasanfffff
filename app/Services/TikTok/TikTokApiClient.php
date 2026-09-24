<?php

namespace App\Services\TikTok;

use Illuminate\Support\Facades\Http;
use RuntimeException;

final class TikTokApiClient
{
    public function apiBase(): string
    {
        $version = trim((string) config('tiktok.api_version', 'v1.3'), '/');
        $base = rtrim((string) config('tiktok.api_base', 'https://business-api.tiktok.com/open_api'), '/');

        return $base.'/'.$version;
    }

    /**
     * @return array<string, mixed>
     */
    public function exchangeAuthorizationCode(string $code, string $redirectUri): array
    {
        return $this->postJson('/tt_user/oauth2/token/', [
            'client_key' => (string) config('tiktok.client_key'),
            'client_secret' => (string) config('tiktok.client_secret'),
            'code' => $code,
            'grant_type' => 'authorization_code',
            'redirect_uri' => $redirectUri,
        ], accessToken: null);
    }

    /**
     * @return array<string, mixed>
     */
    public function refreshAccessToken(string $refreshToken): array
    {
        return $this->postJson('/tt_user/oauth2/refresh_token/', [
            'client_key' => (string) config('tiktok.client_key'),
            'client_secret' => (string) config('tiktok.client_secret'),
            'grant_type' => 'refresh_token',
            'refresh_token' => $refreshToken,
        ], accessToken: null);
    }

    /**
     * @param  array<string, mixed>  $body
     * @return array<string, mixed>
     */
    public function sendTextMessage(string $accessToken, string $businessId, string $conversationId, string $text): array
    {
        return $this->postJson('/business/message/send/', [
            'business_id' => $businessId,
            'message_type' => 'TEXT',
            'recipient_type' => 'CONVERSATION',
            'recipient' => $conversationId,
            'text' => ['body' => $text],
        ], $accessToken);
    }

    /**
     * @param  array<string, mixed>  $body
     * @return array<string, mixed>
     */
    private function postJson(string $path, array $body, ?string $accessToken): array
    {
        $request = Http::acceptJson()->asJson()->timeout(30);
        if ($accessToken !== null && $accessToken !== '') {
            $request = $request->withHeaders(['Access-Token' => $accessToken]);
        }

        $response = $request->post($this->apiBase().$path, $body);
        $payload = $response->json();

        if (! is_array($payload)) {
            throw new RuntimeException('TikTok API returned an invalid response.');
        }

        $code = (int) ($payload['code'] ?? -1);
        if (! $response->successful() || $code !== 0) {
            throw new RuntimeException('TikTok API error: '.((string) ($payload['message'] ?? 'unknown')));
        }

        $data = $payload['data'] ?? [];

        return is_array($data) ? $data : [];
    }
}
