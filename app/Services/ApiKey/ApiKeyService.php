<?php

namespace App\Services\ApiKey;

use App\Models\ApiKey;
use App\Models\User;
use App\Models\Workspace;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;
use RuntimeException;

class ApiKeyService
{
    /**
     * @param  array<int, string>|null  $abilities
     * @return array{api_key: ApiKey, plain_text: string}
     */
    public function create(Workspace $workspace, string $name, ?User $user = null, ?array $abilities = null): array
    {
        $plainText = 'hs_'.Str::random(40);
        $prefix = substr($plainText, 0, 12);

        $apiKey = ApiKey::withoutGlobalScopes()->create([
            'workspace_id' => $workspace->id,
            'user_id' => $user?->id,
            'name' => $name,
            'key_prefix' => $prefix,
            'key_hash' => $this->hash($plainText),
            'abilities' => $abilities ?? ['*'],
        ]);

        return [
            'api_key' => $apiKey,
            'plain_text' => $plainText,
        ];
    }

    public function revoke(ApiKey $apiKey): ApiKey
    {
        if ($apiKey->revoked_at === null) {
            $apiKey->forceFill(['revoked_at' => now()])->save();
        }

        return $apiKey->refresh();
    }

    /**
     * @return array{api_key: ApiKey, plain_text: string}
     */
    public function regenerate(ApiKey $apiKey, ?User $actor = null): array
    {
        if ($apiKey->isRevoked()) {
            throw new RuntimeException('Cannot regenerate a revoked API key.');
        }

        $plainText = 'hs_'.Str::random(40);
        $apiKey->forceFill([
            'key_prefix' => substr($plainText, 0, 12),
            'key_hash' => $this->hash($plainText),
            'user_id' => $actor?->id ?? $apiKey->user_id,
            'last_used_at' => null,
        ])->save();

        return [
            'api_key' => $apiKey->refresh(),
            'plain_text' => $plainText,
        ];
    }

    /**
     * @return Collection<int, ApiKey>
     */
    public function list(Workspace $workspace, bool $includeRevoked = true): Collection
    {
        $query = ApiKey::withoutGlobalScopes()
            ->where('workspace_id', $workspace->id)
            ->orderByDesc('id');

        if (! $includeRevoked) {
            $query->whereNull('revoked_at');
        }

        return $query->get();
    }

    public function findByPlainText(string $plainText): ?ApiKey
    {
        return ApiKey::withoutGlobalScopes()
            ->where('key_hash', $this->hash($plainText))
            ->whereNull('revoked_at')
            ->first();
    }

    public function hash(string $plainText): string
    {
        return hash('sha256', $plainText);
    }
}
