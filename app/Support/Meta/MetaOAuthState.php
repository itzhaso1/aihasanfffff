<?php

namespace App\Support\Meta;

use Illuminate\Contracts\Encryption\DecryptException;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Str;
use RuntimeException;

final class MetaOAuthState
{
    public const PURPOSE_INSTAGRAM = 'instagram_connect';

    public const PURPOSE_FACEBOOK = 'facebook_connect';

    public const PURPOSE_TIKTOK = 'tiktok_connect';

    /**
     * @return array{state: string, expires_at: int}
     */
    public function issue(int $workspaceId, int $userId, string $purpose = self::PURPOSE_INSTAGRAM): array
    {
        $expiresAt = now()->addMinutes(15)->timestamp;

        $payload = [
            'workspace_id' => $workspaceId,
            'user_id' => $userId,
            'purpose' => $purpose,
            'nonce' => Str::random(32),
            'expires_at' => $expiresAt,
        ];

        return [
            'state' => Crypt::encryptString(json_encode($payload, JSON_THROW_ON_ERROR)),
            'expires_at' => $expiresAt,
        ];
    }

    /**
     * @return array{workspace_id: int, user_id: int, purpose: string, nonce: string, expires_at: int}
     */
    public function verify(string $state, string $expectedPurpose = self::PURPOSE_INSTAGRAM): array
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
        ];
    }
}
