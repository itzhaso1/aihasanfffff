<?php

namespace App\Support\Meta;

use Illuminate\Contracts\Encryption\DecryptException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Str;
use RuntimeException;

final class MetaOAuthState
{
    public const PURPOSE_INSTAGRAM = 'instagram_connect';

    public const PURPOSE_FACEBOOK = 'facebook_connect';

    public const PURPOSE_TIKTOK = 'tiktok_connect';

    public const RETURN_WEB = 'web';

    public const RETURN_MOBILE = 'mobile';

    /**
     * @return array{state: string, expires_at: int}
     */
    public function issue(
        int $workspaceId,
        int $userId,
        string $purpose = self::PURPOSE_INSTAGRAM,
        string $returnTo = self::RETURN_WEB,
    ): array {
        $expiresAt = now()->addMinutes(15)->timestamp;
        $nonce = Str::random(32);
        $returnTo = $returnTo === self::RETURN_MOBILE ? self::RETURN_MOBILE : self::RETURN_WEB;

        $payload = [
            'workspace_id' => $workspaceId,
            'user_id' => $userId,
            'purpose' => $purpose,
            'nonce' => $nonce,
            'expires_at' => $expiresAt,
            'return_to' => $returnTo,
        ];

        Cache::put($this->nonceKey($nonce), 1, now()->addMinutes(16));

        return [
            'state' => Crypt::encryptString(json_encode($payload, JSON_THROW_ON_ERROR)),
            'expires_at' => $expiresAt,
        ];
    }

    /**
     * Decode and validate state without consuming the nonce.
     *
     * @return array{workspace_id: int, user_id: int, purpose: string, nonce: string, expires_at: int, return_to: string}
     */
    public function peek(string $state, string $expectedPurpose = self::PURPOSE_INSTAGRAM): array
    {
        return $this->decode($state, $expectedPurpose);
    }

    /**
     * @return array{workspace_id: int, user_id: int, purpose: string, nonce: string, expires_at: int, return_to: string}
     */
    public function verify(string $state, string $expectedPurpose = self::PURPOSE_INSTAGRAM): array
    {
        $payload = $this->decode($state, $expectedPurpose);

        if (! Cache::pull($this->nonceKey($payload['nonce']))) {
            throw new RuntimeException('OAuth state has already been used or expired.');
        }

        return $payload;
    }

    /**
     * @return array{workspace_id: int, user_id: int, purpose: string, nonce: string, expires_at: int, return_to: string}
     */
    private function decode(string $state, string $expectedPurpose): array
    {
        $state = trim($state);
        if ($state === '') {
            throw new RuntimeException('OAuth state is missing.');
        }

        try {
            $decoded = json_decode(Crypt::decryptString($state), true, 512, JSON_THROW_ON_ERROR);
        } catch (DecryptException|\JsonException) {
            throw new RuntimeException('OAuth state is invalid or expired.');
        }

        if (! is_array($decoded)) {
            throw new RuntimeException('OAuth state is invalid or expired.');
        }

        $workspaceId = (int) ($decoded['workspace_id'] ?? 0);
        $userId = (int) ($decoded['user_id'] ?? 0);
        $purpose = (string) ($decoded['purpose'] ?? '');
        $expiresAt = (int) ($decoded['expires_at'] ?? 0);
        $nonce = (string) ($decoded['nonce'] ?? '');
        $returnTo = (string) ($decoded['return_to'] ?? self::RETURN_WEB);
        if ($returnTo !== self::RETURN_MOBILE) {
            $returnTo = self::RETURN_WEB;
        }

        if ($workspaceId <= 0 || $userId <= 0 || $nonce === '' || $expiresAt <= 0) {
            throw new RuntimeException('OAuth state is invalid or expired.');
        }

        if ($purpose !== $expectedPurpose) {
            throw new RuntimeException('OAuth state purpose mismatch.');
        }

        if ($expiresAt < now()->timestamp) {
            throw new RuntimeException('OAuth state has expired.');
        }

        return [
            'workspace_id' => $workspaceId,
            'user_id' => $userId,
            'purpose' => $purpose,
            'nonce' => $nonce,
            'expires_at' => $expiresAt,
            'return_to' => $returnTo,
        ];
    }

    private function nonceKey(string $nonce): string
    {
        return 'oauth_state_nonce:'.$nonce;
    }
}
