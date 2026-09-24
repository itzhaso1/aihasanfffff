<?php

namespace App\Services\Meta\Facebook;

use App\Models\Conversation;
use App\Models\CustomerChannelIdentity;
use App\Models\WorkspaceMetaConnection;
use App\Support\Meta\MetaConnectionKind;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

final class FacebookProfileResolver
{
    /**
     * @return array{display_name?: string, avatar_url?: string}|null
     */
    public function resolveForIdentity(CustomerChannelIdentity $identity): ?array
    {
        $externalUserId = trim((string) $identity->external_user_id);
        if ($externalUserId === '') {
            Log::info('facebook.profile.skipped', [
                'reason' => 'missing_psid',
                'workspace_id' => $identity->workspace_id,
                'identity_id' => $identity->id,
            ]);

            return null;
        }

        $cacheKey = 'facebook.profile.'.$identity->workspace_id.'.'.hash('sha256', $externalUserId);
        $cached = Cache::get($cacheKey);
        if (is_array($cached) && $cached !== []) {
            return $cached;
        }

        $result = $this->fetchFromGraph($identity, $externalUserId);
        if ($result !== null && $result !== []) {
            Cache::put($cacheKey, $result, now()->addHours(12));
        }

        return $result;
    }

    /**
     * @return array{display_name?: string, avatar_url?: string}|null
     */
    private function fetchFromGraph(CustomerChannelIdentity $identity, string $psid): ?array
    {
        Log::info('facebook.profile.resolve_started', [
            'workspace_id' => $identity->workspace_id,
            'identity_id' => $identity->id,
            'customer_id' => $identity->customer_id,
            'psid' => $psid,
        ]);

        $connection = $this->resolvePageConnection($identity);
        if (! $connection) {
            Log::info('facebook.profile.page_connection_missing', [
                'workspace_id' => $identity->workspace_id,
                'identity_id' => $identity->id,
                'psid' => $psid,
            ]);

            return null;
        }

        Log::info('facebook.profile.page_connection_found', [
            'workspace_id' => $identity->workspace_id,
            'identity_id' => $identity->id,
            'psid' => $psid,
            'page_external_id' => $connection->external_id,
            'connection_id' => $connection->id,
        ]);

        $token = $connection->accessToken();
        if ($token === null || $token === '') {
            Log::info('facebook.profile.page_token_missing', [
                'workspace_id' => $identity->workspace_id,
                'identity_id' => $identity->id,
                'psid' => $psid,
                'connection_id' => $connection->id,
            ]);

            return null;
        }

        $version = trim((string) config('meta.graph_api_version', 'v20.0'));
        $url = 'https://graph.facebook.com/'.$version.'/'.$psid;

        $response = Http::acceptJson()
            ->withToken($token)
            ->timeout(15)
            ->get($url, [
                'fields' => 'first_name,last_name,profile_pic',
            ]);

        if ($response->failed()) {
            Log::info('facebook.profile.graph_failed', [
                'workspace_id' => $identity->workspace_id,
                'identity_id' => $identity->id,
                'psid' => $psid,
                'http_status' => $response->status(),
                'graph_error' => $this->redactGraphErrorPayload($response->json()),
            ]);

            return null;
        }

        $displayName = $this->buildDisplayName(
            $response->json('first_name'),
            $response->json('last_name'),
        );
        $avatarUrl = $this->extractProfilePicUrl($response->json('profile_pic'));

        $result = [];
        if ($displayName !== null && $displayName !== '') {
            $result['display_name'] = $displayName;
        }
        if ($avatarUrl !== null && $avatarUrl !== '') {
            $result['avatar_url'] = $avatarUrl;
        }

        if ($result === []) {
            Log::info('facebook.profile.empty_payload', [
                'workspace_id' => $identity->workspace_id,
                'identity_id' => $identity->id,
                'psid' => $psid,
                'http_status' => $response->status(),
            ]);

            return null;
        }

        Log::info('facebook.profile.resolved', [
            'workspace_id' => $identity->workspace_id,
            'identity_id' => $identity->id,
            'psid' => $psid,
            'has_display_name' => isset($result['display_name']),
            'has_avatar_url' => isset($result['avatar_url']),
        ]);

        return $result;
    }

    private function buildDisplayName(mixed $firstName, mixed $lastName): ?string
    {
        $first = trim((string) $firstName);
        $last = trim((string) $lastName);

        if ($first !== '' && $last !== '') {
            return $first.' '.$last;
        }
        if ($first !== '') {
            return $first;
        }
        if ($last !== '') {
            return $last;
        }

        return null;
    }

    private function extractProfilePicUrl(mixed $profilePic): ?string
    {
        if (is_string($profilePic) && trim($profilePic) !== '') {
            return trim($profilePic);
        }

        if (is_array($profilePic)) {
            $nested = $profilePic['data']['url'] ?? $profilePic['url'] ?? null;
            if (is_string($nested) && trim($nested) !== '') {
                return trim($nested);
            }
        }

        return null;
    }

    /**
     * @return array<string, mixed>|null
     */
    private function redactGraphErrorPayload(mixed $payload): ?array
    {
        if (! is_array($payload)) {
            return null;
        }

        $json = json_encode($payload);
        if (! is_string($json)) {
            return null;
        }

        $json = preg_replace(
            '/access_token=[^&\s"\']+/i',
            'access_token=[REDACTED]',
            $json,
        ) ?? $json;

        $decoded = json_decode($json, true);

        return is_array($decoded) ? $decoded : null;
    }

    private function resolvePageConnection(CustomerChannelIdentity $identity): ?WorkspaceMetaConnection
    {
        $conversation = Conversation::withoutGlobalScopes()
            ->where('workspace_id', $identity->workspace_id)
            ->where('customer_id', $identity->customer_id)
            ->where('channel', 'facebook_messenger')
            ->latest('id')
            ->first();

        $pageId = null;
        if ($conversation) {
            $metadata = is_array($conversation->metadata) ? $conversation->metadata : [];
            $pageId = is_string($metadata['facebook_page_id'] ?? null) ? trim($metadata['facebook_page_id']) : null;
        }

        $query = WorkspaceMetaConnection::withoutGlobalScopes()
            ->where('workspace_id', $identity->workspace_id)
            ->where('kind', MetaConnectionKind::FACEBOOK_PAGE)
            ->where('status', 'connected')
            ->whereNull('deleted_at');

        if ($pageId) {
            $matched = (clone $query)->where('external_id', $pageId)->first();
            if ($matched) {
                return $matched;
            }
        }

        $matches = $query->get();

        return $matches->count() === 1 ? $matches->first() : null;
    }
}
