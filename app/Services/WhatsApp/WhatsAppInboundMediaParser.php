<?php

namespace App\Services\WhatsApp;

final class WhatsAppInboundMediaParser
{
    /**
     * @param  array<string, mixed>  $messageData
     * @return array{message_type:string,content:?string,descriptors:list<array<string,mixed>>}
     */
    public function parse(array $messageData): array
    {
        $type = is_string($messageData['type'] ?? null) ? strtolower((string) $messageData['type']) : 'text';

        return match ($type) {
            'image' => $this->media('image', $messageData['image'] ?? [], $messageData),
            'audio' => $this->media('audio', $messageData['audio'] ?? [], $messageData),
            'video' => $this->media('video', $messageData['video'] ?? [], $messageData),
            'document' => $this->media('file', $messageData['document'] ?? [], $messageData),
            'sticker' => $this->media('image', $messageData['sticker'] ?? [], $messageData),
            default => [
                'message_type' => 'text',
                'content' => is_string($messageData['text']['body'] ?? null) ? $messageData['text']['body'] : null,
                'descriptors' => [],
            ],
        };
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array{message_type:string,content:?string,descriptors:list<array<string,mixed>>}
     */
    private function media(string $kind, array $payload, array $messageData): array
    {
        $mediaId = trim((string) ($payload['id'] ?? ''));
        $caption = is_string($payload['caption'] ?? null) ? $payload['caption'] : null;
        $mime = is_string($payload['mime_type'] ?? null) ? $payload['mime_type'] : null;
        $filename = is_string($payload['filename'] ?? null) ? $payload['filename'] : null;

        $descriptors = [];
        if ($mediaId !== '') {
            $descriptors[] = array_filter([
                'kind' => $kind,
                'provider_media_id' => $mediaId,
                'mime_type' => $mime,
                'original_name' => $filename,
            ]);
        }

        return [
            'message_type' => $kind,
            'content' => $caption,
            'descriptors' => $descriptors,
        ];
    }
}
