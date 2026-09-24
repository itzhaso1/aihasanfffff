<?php

namespace App\Services\Media\Provider;

use App\Exceptions\Media\MediaDownloadRetryableException;
use App\Models\Message;
use App\Models\WorkspaceMetaConnection;
use App\Support\Meta\MetaConnectionKind;
use Illuminate\Support\Facades\Http;

final class InstagramProviderMediaClient
{
    public function downloadForMessage(Message $message, string $downloadUrl): ProviderMediaDownloadResult
    {
        $connection = $this->resolveConnection($message);
        if (! $connection) {
            throw new \RuntimeException('instagram_connection_missing');
        }

        $token = $connection->accessToken();
        if ($token === null || $token === '') {
            throw new \RuntimeException('instagram_token_missing');
        }

        $response = Http::acceptJson()
            ->withToken($token)
            ->timeout(60)
            ->get($downloadUrl);

        if ($response->failed()) {
            if ($response->serverError() || $response->status() === 429) {
                throw new MediaDownloadRetryableException('instagram_media_'.$response->status());
            }

            throw new \RuntimeException('instagram_media_'.$response->status());
        }

        $body = $response->body();
        if ($body === '') {
            throw new \RuntimeException('instagram_media_empty');
        }

        $mime = trim((string) ($response->header('Content-Type') ?: 'application/octet-stream'));
        $mime = explode(';', $mime)[0];

        return new ProviderMediaDownloadResult(
            binary: $body,
            mimeType: $mime !== '' ? $mime : 'application/octet-stream',
            originalFilename: null,
        );
    }

    private function resolveConnection(Message $message): ?WorkspaceMetaConnection
    {
        $conversation = $message->relationLoaded('conversation')
            ? $message->conversation
            : $message->conversation()->first();

        if (! $conversation) {
            return null;
        }

        $metadata = is_array($conversation->metadata) ? $conversation->metadata : [];
        $accountId = is_string($metadata['instagram_account_id'] ?? null)
            ? trim((string) $metadata['instagram_account_id'])
            : '';

        $query = WorkspaceMetaConnection::withoutGlobalScopes()
            ->where('workspace_id', $message->workspace_id)
            ->where('kind', MetaConnectionKind::INSTAGRAM_USER)
            ->where('status', 'connected')
            ->whereNull('deleted_at');

        if ($accountId !== '') {
            $matched = (clone $query)->where('external_id', $accountId)->first();
            if ($matched) {
                return $matched;
            }
        }

        $matches = $query->get();

        return $matches->count() === 1 ? $matches->first() : null;
    }
}
