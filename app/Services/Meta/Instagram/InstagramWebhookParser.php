<?php

namespace App\Services\Meta\Instagram;

class InstagramWebhookParser
{
    /**
     * @param  array<string, mixed>  $payload
     * @return list<InstagramMessagingEvent>
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

            $entryAccountId = $this->readId($entry['id'] ?? null);

            foreach ($this->messagingItemsFromEntry($entry) as $item) {
                $event = $this->normalizeMessagingItem($item, $entryAccountId);
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

        $messaging = $entry['messaging'] ?? null;
        if (is_array($messaging)) {
            foreach ($messaging as $item) {
                if (is_array($item)) {
                    $items[] = $item;
                }
            }
        }

        $standby = $entry['standby'] ?? null;
        if (is_array($standby)) {
            foreach ($standby as $item) {
                if (is_array($item)) {
                    $items[] = $item;
                }
            }
        }

        $changes = $entry['changes'] ?? null;
        if (is_array($changes)) {
            foreach ($changes as $change) {
                if (! is_array($change)) {
                    continue;
                }

                $field = is_string($change['field'] ?? null) ? strtolower((string) $change['field']) : '';
                if (! in_array($field, ['messages', 'message', 'messaging'], true)) {
                    continue;
                }

                $value = $change['value'] ?? null;
                if (! is_array($value)) {
                    continue;
                }

                if ($this->looksLikeMessagingItem($value)) {
                    $items[] = $value;

                    continue;
                }

                $nested = $value['messaging'] ?? $value['messages'] ?? null;
                if (is_array($nested)) {
                    foreach ($nested as $item) {
                        if (is_array($item)) {
                            $items[] = $item;
                        }
                    }
                }
            }
        }

        return $items;
    }

    /**
     * @param  array<string, mixed>  $item
     */
    private function looksLikeMessagingItem(array $item): bool
    {
        return isset($item['message'])
            || isset($item['sender'])
            || isset($item['from'])
            || isset($item['recipient']);
    }

    /**
     * @param  array<string, mixed>  $item
     */
    private function normalizeMessagingItem(array $item, ?string $entryAccountId): ?InstagramMessagingEvent
    {
        $message = $item['message'] ?? null;
        if (! is_array($message)) {
            return null;
        }

        $messageId = $this->readId($message['mid'] ?? $message['id'] ?? null);
        if ($messageId === null) {
            return null;
        }

        $senderId = $this->readNestedId($item['sender'] ?? null)
            ?? $this->readNestedId($item['from'] ?? null)
            ?? $this->readId($item['from'] ?? null);

        $recipientId = $this->readNestedId($item['recipient'] ?? null)
            ?? $this->readNestedId($item['to'] ?? null)
            ?? $entryAccountId;

        if ($senderId === null || $recipientId === null) {
            return null;
        }

        $text = $this->readMessageText($message);
        $attachments = $this->safeAttachments($message['attachments'] ?? null);
        $messageType = $this->resolveMessageType($text, $attachments);
        $timestampMs = $this->readTimestamp($item['timestamp'] ?? $message['timestamp'] ?? null);

        return new InstagramMessagingEvent(
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
                'channel' => 'instagram',
                'instagram_user_id' => $senderId,
                'instagram_account_id' => $recipientId,
                'instagram_message_id' => $messageId,
                'has_attachments' => $attachments !== [],
                'attachment_types' => array_values(array_map(
                    static fn (array $attachment): string => $attachment['type'],
                    $attachments,
                )),
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

        if (is_array($text) && isset($text['body']) && is_string($text['body'])) {
            $trimmed = trim($text['body']);

            return $trimmed !== '' ? $trimmed : null;
        }

        return null;
    }

    /**
     * @return list<array{type:string,url?:string}>
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
            $origin = $this->attachmentOrigin($url !== '' ? $url : null);
            if ($origin !== null) {
                $item['url'] = $origin;
            }

            if ($url !== '') {
                $item['provider_media_id'] = hash('sha256', $type.'|'.$url);
                $item['download_url'] = $url;
            }

            $safe[] = $item;
        }

        return $safe;
    }

    /**
     * @param  list<array{type:string,url?:string}>  $attachments
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

    private function attachmentOrigin(mixed $url): ?string
    {
        if (! is_string($url) || $url === '') {
            return null;
        }

        $parts = parse_url($url);
        if (! is_array($parts) || empty($parts['host'])) {
            return null;
        }

        $scheme = isset($parts['scheme']) ? (string) $parts['scheme'] : 'https';
        $host = (string) $parts['host'];

        return $scheme.'://'.$host;
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
