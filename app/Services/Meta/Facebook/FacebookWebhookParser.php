<?php

namespace App\Services\Meta\Facebook;

class FacebookWebhookParser
{
    /**
     * @param  array<string, mixed>  $payload
     * @return list<FacebookMessagingEvent>
     */
    public function extractMessagingEvents(array $payload): array
    {
        $entries = $payload['entry'] ?? null;
        if (! is_array($entries)) {
            return [];
        }

        $events = [];
        $seenMessageIds = [];

        foreach ($entries as $entry) {
            if (! is_array($entry)) {
                continue;
            }

            $pageId = $this->readId($entry['id'] ?? null);

            foreach ($this->messagingItemsFromEntry($entry) as $item) {
                $event = $this->normalizeMessagingItem($item, $pageId);
                if ($event === null) {
                    continue;
                }

                if (isset($seenMessageIds[$event->messageId])) {
                    continue;
                }

                $seenMessageIds[$event->messageId] = true;
                $events[] = $event;
            }
        }

        return $events;
    }

    /**
     * @param  array<string, mixed>  $entry
     * @return list<array<string, mixed>>
     */
    private function messagingItemsFromEntry(array $entry): array
    {
        $items = [];

        foreach (['messaging', 'standby'] as $key) {
            $bucket = $entry[$key] ?? null;
            if (! is_array($bucket)) {
                continue;
            }

            foreach ($bucket as $item) {
                if (is_array($item)) {
                    $items[] = $item;
                }
            }
        }

        return $items;
    }

    /**
     * @param  array<string, mixed>  $item
     */
    private function normalizeMessagingItem(array $item, ?string $pageId): ?FacebookMessagingEvent
    {
        $message = $item['message'] ?? null;
        if (! is_array($message)) {
            return null;
        }

        $messageId = $this->readId($message['mid'] ?? $message['id'] ?? null);
        if ($messageId === null) {
            return null;
        }

        $senderId = $this->readNestedId($item['sender'] ?? null);
        $recipientId = $this->readNestedId($item['recipient'] ?? null) ?? $pageId;

        if ($senderId === null || $recipientId === null) {
            return null;
        }

        $text = $this->readMessageText($message);
        $attachments = $this->safeAttachments($message['attachments'] ?? null);
        $messageType = $this->resolveMessageType($text, $attachments);
        $timestampMs = $this->readTimestamp($item['timestamp'] ?? $message['timestamp'] ?? null);

        return new FacebookMessagingEvent(
            senderId: $senderId,
            recipientId: $recipientId,
            messageId: $messageId,
            text: $text,
            messageType: $messageType,
            isEcho: (bool) ($message['is_echo'] ?? $item['is_echo'] ?? false),
            isDeleted: (bool) ($message['is_deleted'] ?? false),
            timestampMs: $timestampMs,
            attachments: $attachments,
            safeMetadata: [
                'channel' => 'facebook_messenger',
                'facebook_user_id' => $senderId,
                'facebook_page_id' => $recipientId,
                'facebook_message_id' => $messageId,
                'has_attachments' => $attachments !== [],
                'timestamp_ms' => $timestampMs,
            ],
        );
    }

    /**
     * @param  array<string, mixed>  $message
     */
    private function readMessageText(array $message): ?string
    {
        $text = $message['text'] ?? null;
        if (is_string($text)) {
            $trimmed = trim($text);

            return $trimmed !== '' ? $trimmed : null;
        }

        return null;
    }

    /**
     * @return list<array{type:string,url?:string,provider_media_id?:string,download_url?:string}>
     */
    private function safeAttachments(mixed $attachments): array
    {
        if (! is_array($attachments)) {
            return [];
        }

        $safe = [];
        foreach ($attachments as $attachment) {
            if (! is_array($attachment)) {
                continue;
            }

            $type = is_string($attachment['type'] ?? null) ? strtolower(trim((string) $attachment['type'])) : 'file';
            if ($type === '') {
                $type = 'file';
            }

            $url = is_string($attachment['payload']['url'] ?? null) ? trim((string) $attachment['payload']['url']) : '';
            $item = ['type' => $type];
            if ($url !== '') {
                $item['url'] = $this->attachmentOrigin($url);
                $item['provider_media_id'] = hash('sha256', $type.'|'.$url);
                $item['download_url'] = $url;
            }

            $safe[] = $item;
        }

        return $safe;
    }

    /**
     * @param  list<array{type:string}>  $attachments
     */
    private function resolveMessageType(?string $text, array $attachments): string
    {
        foreach ($attachments as $attachment) {
            if (($attachment['type'] ?? '') === 'image') {
                return 'image';
            }
        }

        if ($attachments !== [] && $text === null) {
            return 'file';
        }

        return 'text';
    }

    private function attachmentOrigin(string $url): ?string
    {
        $parts = parse_url($url);
        if (! is_array($parts) || empty($parts['host'])) {
            return null;
        }

        $scheme = isset($parts['scheme']) ? (string) $parts['scheme'] : 'https';

        return $scheme.'://'.(string) $parts['host'];
    }

    private function readNestedId(mixed $value): ?string
    {
        if (is_array($value)) {
            return $this->readId($value['id'] ?? null);
        }

        return $this->readId($value);
    }

    private function readId(mixed $value): ?string
    {
        if (! is_scalar($value)) {
            return null;
        }

        $id = trim((string) $value);

        return $id !== '' ? $id : null;
    }

    private function readTimestamp(mixed $value): ?int
    {
        if (! is_numeric($value)) {
            return null;
        }

        $timestamp = (int) $value;

        return $timestamp > 0 ? $timestamp : null;
    }
}
