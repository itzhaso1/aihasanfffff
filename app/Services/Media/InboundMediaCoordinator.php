<?php

namespace App\Services\Media;

use App\Jobs\Media\ProcessInboundMediaAttachment;
use App\Models\Message;
use App\Models\MessageAttachment;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\Log;

final class InboundMediaCoordinator
{
    /**
     * @param  list<array<string, mixed>>  $descriptors
     */
    public function registerFromDescriptors(Message $message, string $provider, array $descriptors): void
    {
        if ($descriptors === []) {
            return;
        }

        foreach ($descriptors as $descriptor) {
            $this->registerOne($message, $provider, $descriptor);
        }
    }

    /**
     * @param  array<string, mixed>  $descriptor
     */
    private function registerOne(Message $message, string $provider, array $descriptor): void
    {
        $providerMediaId = trim((string) ($descriptor['provider_media_id'] ?? ''));
        if ($providerMediaId === '') {
            return;
        }

        $kind = trim((string) ($descriptor['kind'] ?? 'file'));
        if (! in_array($kind, ['image', 'audio', 'video', 'file'], true)) {
            $kind = 'file';
        }

        try {
            $attachment = MessageAttachment::withoutGlobalScopes()->firstOrCreate(
                [
                    'workspace_id' => $message->workspace_id,
                    'provider' => $provider,
                    'provider_media_id' => $providerMediaId,
                ],
                [
                    'message_id' => $message->id,
                    'disk' => '',
                    'path' => '',
                    'original_name' => (string) ($descriptor['original_name'] ?? $kind),
                    'mime_type' => $descriptor['mime_type'] ?? null,
                    'size_bytes' => 0,
                    'kind' => $kind,
                    'status' => MessageAttachment::STATUS_PENDING,
                    'provider_metadata' => $this->safeProviderMetadata($descriptor),
                ],
            );
        } catch (UniqueConstraintViolationException) {
            $attachment = MessageAttachment::withoutGlobalScopes()
                ->where('workspace_id', $message->workspace_id)
                ->where('provider', $provider)
                ->where('provider_media_id', $providerMediaId)
                ->first();

            if (! $attachment) {
                return;
            }
        }

        if ($attachment->status === MessageAttachment::STATUS_STORED) {
            return;
        }

        if ((int) $attachment->message_id !== (int) $message->id) {
            Log::info('media.inbound.idempotent_skip', [
                'workspace_id' => $message->workspace_id,
                'message_id' => $message->id,
                'attachment_id' => $attachment->id,
                'provider' => $provider,
            ]);

            return;
        }

        ProcessInboundMediaAttachment::dispatch(
            (int) $attachment->id,
            (int) $message->workspace_id,
        );
    }

    /**
     * @param  array<string, mixed>  $descriptor
     * @return array<string, mixed>
     */
    private function safeProviderMetadata(array $descriptor): array
    {
        $meta = [];
        if (isset($descriptor['download_url']) && is_string($descriptor['download_url'])) {
            $meta['download_url'] = $descriptor['download_url'];
        }
        if (isset($descriptor['mime_type']) && is_string($descriptor['mime_type'])) {
            $meta['mime_type_hint'] = $descriptor['mime_type'];
        }

        return $meta;
    }
}
