<?php

namespace App\Services\Mobile;

use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use RuntimeException;
use Throwable;

/**
 * Sends one FCM message. Tokens and private keys never appear in logs.
 */
class FcmPushClient
{
    private const LEGACY_URL = 'https://fcm.googleapis.com/fcm/send';

    private const OAUTH_URL = 'https://oauth2.googleapis.com/token';

    private const OAUTH_CACHE_KEY = 'fcm.oauth_access_token';

    public function isConfigured(): bool
    {
        if (! (bool) config('services.fcm.enabled')) {
            return false;
        }

        return $this->legacyKey() !== null || $this->serviceAccount() !== null;
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public function send(string $token, string $title, string $body, array $data = []): FcmDeliveryStatus
    {
        $token = trim($token);
        if ($token === '') {
            return FcmDeliveryStatus::InvalidToken;
        }

        if (! $this->isConfigured()) {
            return FcmDeliveryStatus::PermanentFailure;
        }

        $payloadData = $this->stringData($data);

        try {
            if ($this->serviceAccount() !== null) {
                return $this->sendHttpV1($token, $title, $body, $payloadData);
            }

            return $this->sendLegacy($token, $title, $body, $payloadData);
        } catch (Throwable $exception) {
            if ($exception instanceof RuntimeException && $exception->getCode() === 429) {
                throw $exception;
            }

            Log::warning('FCM delivery request failed', [
                'status' => 'transport_error',
            ]);

            throw new RuntimeException('FCM delivery failed.', 503);
        }
    }

    /**
     * @param  array<string, string>  $data
     */
    private function sendHttpV1(string $token, string $title, string $body, array $data): FcmDeliveryStatus
    {
        $projectId = $this->projectId();
        if ($projectId === null) {
            Log::info('Push notification skipped: FCM project id is missing', [
                'device_count' => 1,
            ]);

            return FcmDeliveryStatus::PermanentFailure;
        }

        $accessToken = $this->accessToken();
        $url = 'https://fcm.googleapis.com/v1/projects/'.$projectId.'/messages:send';
        $response = Http::withToken($accessToken)
            ->acceptJson()
            ->asJson()
            ->timeout($this->timeout())
            ->post($url, [
                'message' => [
                    'token' => $token,
                    'notification' => [
                        'title' => $title,
                        'body' => $body,
                    ],
                    'data' => $data,
                    'android' => [
                        'priority' => 'high',
                    ],
                    'apns' => [
                        'headers' => [
                            'apns-priority' => '10',
                        ],
                        'payload' => [
                            'aps' => [
                                'sound' => 'default',
                            ],
                        ],
                    ],
                ],
            ]);

        return $this->interpretHttpV1($response);
    }

    /**
     * @param  array<string, string>  $data
     */
    private function sendLegacy(string $token, string $title, string $body, array $data): FcmDeliveryStatus
    {
        $key = $this->legacyKey();
        if ($key === null) {
            return FcmDeliveryStatus::PermanentFailure;
        }

        $response = Http::withHeaders([
            'Authorization' => 'key='.$key,
        ])
            ->acceptJson()
            ->asJson()
            ->timeout($this->timeout())
            ->post(self::LEGACY_URL, [
                'to' => $token,
                'priority' => 'high',
                'notification' => [
                    'title' => $title,
                    'body' => $body,
                ],
                'data' => $data,
            ]);

        return $this->interpretLegacy($response);
    }

    private function interpretHttpV1(Response $response): FcmDeliveryStatus
    {
        $status = $response->status();
        if ($response->successful()) {
            return FcmDeliveryStatus::Sent;
        }

        $errorCode = strtoupper((string) $response->json('error.status'));
        $details = $response->json('error.details');
        $errorCode = $this->fcmErrorFromDetails($details) ?? $errorCode;

        if (in_array($errorCode, ['UNREGISTERED', 'NOT_FOUND', 'INVALID_ARGUMENT'], true) || $status === 404) {
            return FcmDeliveryStatus::InvalidToken;
        }

        if ($status === 429 || $status >= 500) {
            throw new RuntimeException('FCM delivery is retryable.', 429);
        }

        Log::warning('FCM delivery rejected', [
            'http_status' => $status,
        ]);

        return FcmDeliveryStatus::PermanentFailure;
    }

    private function interpretLegacy(Response $response): FcmDeliveryStatus
    {
        $status = $response->status();
        if ($status === 401 || $status === 403) {
            Log::warning('FCM delivery rejected', [
                'http_status' => $status,
            ]);

            return FcmDeliveryStatus::PermanentFailure;
        }

        if ($status === 429 || $status >= 500) {
            throw new RuntimeException('FCM delivery is retryable.', 429);
        }

        if (! $response->successful()) {
            Log::warning('FCM delivery rejected', [
                'http_status' => $status,
            ]);

            return FcmDeliveryStatus::PermanentFailure;
        }

        $error = (string) data_get($response->json(), 'results.0.error');
        if (in_array($error, ['NotRegistered', 'InvalidRegistration', 'MismatchSenderId'], true)) {
            return FcmDeliveryStatus::InvalidToken;
        }

        if ((int) $response->json('failure') > 0 && $error !== '') {
            Log::warning('FCM delivery rejected', [
                'http_status' => $status,
            ]);

            return FcmDeliveryStatus::PermanentFailure;
        }

        return FcmDeliveryStatus::Sent;
    }

    private function fcmErrorFromDetails(mixed $details): ?string
    {
        if (! is_array($details)) {
            return null;
        }

        foreach ($details as $detail) {
            if (! is_array($detail)) {
                continue;
            }
            $code = $detail['errorCode'] ?? $detail['reason'] ?? null;
            if (is_string($code) && $code !== '') {
                return strtoupper($code);
            }
        }

        return null;
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, string>
     */
    private function stringData(array $data): array
    {
        $out = [];
        foreach ($data as $key => $value) {
            if (! is_string($key) || $key === '') {
                continue;
            }
            if (is_bool($value)) {
                $out[$key] = $value ? '1' : '0';

                continue;
            }
            if (is_scalar($value)) {
                $out[$key] = (string) $value;
            }
        }

        return $out;
    }

    private function accessToken(): string
    {
        $cached = Cache::get(self::OAUTH_CACHE_KEY);
        if (is_string($cached) && $cached !== '') {
            return $cached;
        }

        $account = $this->serviceAccount();
        if ($account === null) {
            throw new RuntimeException('FCM service account is missing.', 500);
        }

        $now = time();
        $header = $this->base64UrlEncode((string) json_encode(['alg' => 'RS256', 'typ' => 'JWT'], JSON_THROW_ON_ERROR));
        $claims = $this->base64UrlEncode((string) json_encode([
            'iss' => $account['client_email'],
            'scope' => 'https://www.googleapis.com/auth/firebase.messaging',
            'aud' => self::OAUTH_URL,
            'iat' => $now,
            'exp' => $now + 3600,
        ], JSON_THROW_ON_ERROR));
        $unsigned = $header.'.'.$claims;
        $privateKey = openssl_pkey_get_private((string) $account['private_key']);
        if ($privateKey === false) {
            throw new RuntimeException('FCM service account key is invalid.', 500);
        }
        $signature = '';
        if (! openssl_sign($unsigned, $signature, $privateKey, OPENSSL_ALGO_SHA256)) {
            throw new RuntimeException('FCM service account key is invalid.', 500);
        }
        $jwt = $unsigned.'.'.$this->base64UrlEncode($signature);

        $response = Http::asForm()
            ->timeout($this->timeout())
            ->post(self::OAUTH_URL, [
                'grant_type' => 'urn:ietf:params:oauth:grant-type:jwt-bearer',
                'assertion' => $jwt,
            ]);

        if (! $response->successful()) {
            if ($response->serverError() || $response->status() === 429) {
                throw new RuntimeException('FCM OAuth is retryable.', 429);
            }

            throw new RuntimeException('FCM OAuth failed.', 500);
        }

        $accessToken = (string) $response->json('access_token');
        if ($accessToken === '') {
            throw new RuntimeException('FCM OAuth failed.', 500);
        }

        $expiresIn = (int) ($response->json('expires_in') ?? 3600);
        Cache::put(self::OAUTH_CACHE_KEY, $accessToken, now()->addSeconds(max(60, $expiresIn - 60)));

        return $accessToken;
    }

    /**
     * @return array{client_email: string, private_key: string, project_id?: string}|null
     */
    private function serviceAccount(): ?array
    {
        $raw = config('services.fcm.credentials');
        if (! is_string($raw) || trim($raw) === '') {
            $path = config('services.fcm.credentials_path');
            if (is_string($path) && $path !== '' && is_readable($path)) {
                $raw = (string) file_get_contents($path);
            }
        }

        if (! is_string($raw) || trim($raw) === '') {
            return null;
        }

        try {
            $decoded = json_decode($raw, true, 512, JSON_THROW_ON_ERROR);
        } catch (Throwable) {
            return null;
        }

        if (! is_array($decoded)) {
            return null;
        }

        $email = $decoded['client_email'] ?? null;
        $key = $decoded['private_key'] ?? null;
        if (! is_string($email) || $email === '' || ! is_string($key) || $key === '') {
            return null;
        }

        return [
            'client_email' => $email,
            'private_key' => $key,
            'project_id' => is_string($decoded['project_id'] ?? null) ? $decoded['project_id'] : null,
        ];
    }

    private function projectId(): ?string
    {
        $configured = config('services.fcm.project_id');
        if (is_string($configured) && $configured !== '') {
            return $configured;
        }

        $account = $this->serviceAccount();
        $fromAccount = $account['project_id'] ?? null;

        return is_string($fromAccount) && $fromAccount !== '' ? $fromAccount : null;
    }

    private function legacyKey(): ?string
    {
        $key = config('services.fcm.server_key');

        return is_string($key) && $key !== '' ? $key : null;
    }

    private function timeout(): int
    {
        $timeout = (int) config('services.fcm.timeout', 20);

        return $timeout > 0 ? $timeout : 20;
    }

    private function base64UrlEncode(string $value): string
    {
        return rtrim(strtr(base64_encode($value), '+/', '-_'), '=');
    }
}
