<?php

namespace App\Services\TikTok;

final class TikTokWebhookParser
{
    private const INBOUND_EVENTS = ['im_receive_msg', 'im_receive_msg_eu'];

    /**
     * @param  array<string, mixed>  $payload
     */
    public function parseEnvelope(array $payload): ?TikTokMessagingEvent
    {
        $eventName = is_string($payload['event'] ?? null) ? (string) $payload['event'] : '';
        if (! in_array($eventName, self::INBOUND_EVENTS, true)) {
            return null;
        }

        $businessOpenId = is_string($payload['user_openid'] ?? null) ? trim((string) $payload['user_openid']) : '';
        if ($businessOpenId === '') {
            return null;
        }

        $contentRaw = $payload['content'] ?? null;
        if (is_string($contentRaw)) {
            $content = json_decode($contentRaw, true);
        } elseif (is_array($contentRaw)) {
            $content = $contentRaw;
        } else {
            return null;
        }

        if (! is_array($content)) {
            return null;
        }

        $fromRole = is_array($content['from_user'] ?? null)
            ? (string) ($content['from_user']['role'] ?? '')
            : '';

        $isInbound = $fromRole === 'personal_account';
        if (! $isInbound) {
            return null;
        }

        $senderUniqueId = trim((string) ($content['unique_identifier'] ?? ''));
        if ($senderUniqueId === '') {
            $senderUniqueId = trim((string) ($content['from_user']['id'] ?? ''));
        }

        $conversationId = trim((string) ($content['conversation_id'] ?? ''));
        $messageId = trim((string) ($content['message_id'] ?? ''));
        if ($senderUniqueId === '' || $conversationId === '' || $messageId === '') {
            return null;
        }

        $messageType = strtolower(trim((string) ($content['type'] ?? 'text')));
        $text = is_array($content['text'] ?? null) ? (string) ($content['text']['body'] ?? '') : null;

        $attachments = $this->attachmentsFromContent($content, $messageType);
        $timestampMs = (int) ($content['timestamp'] ?? 0);

        return new TikTokMessagingEvent(
            eventName: $eventName,
            businessOpenId: $businessOpenId,
            senderUniqueId: $senderUniqueId,
            conversationId: $conversationId,
            messageId: $messageId,
            messageType: $messageType,
            text: $text !== '' ? $text : null,
            timestampMs: $timestampMs,
            attachments: $attachments,
            safeMetadata: [
                'conversation_id' => $conversationId,
                'message_type' => $messageType,
                'tiktok_username_from' => is_string($content['from'] ?? null) ? (string) $content['from'] : null,
            ],
            isInboundFromCustomer: true,
        );
    }

    /**
     * @param  array<string, mixed>  $content
     * @return list<array<string, mixed>>
     */
    private function attachmentsFromContent(array $content, string $messageType): array
    {
        if ($messageType === 'image' && is_array($content['image'] ?? null)) {
            $mediaId = trim((string) ($content['image']['media_id'] ?? ''));

            return $mediaId !== '' ? [[
                'kind' => 'image',
                'provider_media_id' => $mediaId,
            ]] : [];
        }

        if ($messageType === 'video' && is_array($content['video'] ?? null)) {
            $mediaId = trim((string) ($content['video']['media_id'] ?? ''));

            return $mediaId !== '' ? [[
                'kind' => 'video',
                'provider_media_id' => $mediaId,
            ]] : [];
        }

        return [];
    }
}
