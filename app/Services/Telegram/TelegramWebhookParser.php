<?php

namespace App\Services\Telegram;

final class TelegramWebhookParser
{
    /**
     * @param  array<string, mixed>  $update
     */
    public function parse(array $update): ?TelegramInboundUpdate
    {
        $updateId = (string) ($update['update_id'] ?? '');
        if ($updateId === '') {
            return null;
        }

        $message = null;
        $businessConnectionId = null;

        if (is_array($update['business_message'] ?? null)) {
            $message = $update['business_message'];
            $businessConnectionId = is_string($message['business_connection_id'] ?? null)
                ? (string) $message['business_connection_id']
                : null;
        } elseif (is_array($update['message'] ?? null)) {
            $message = $update['message'];
        }

        if (! is_array($message)) {
            return null;
        }

        if (($message['from']['is_bot'] ?? false) === true) {
            return null;
        }

        $chatId = (string) ($message['chat']['id'] ?? '');
        $senderUserId = (string) ($message['from']['id'] ?? '');
        $messageId = (string) ($message['message_id'] ?? '');
        if ($chatId === '' || $senderUserId === '' || $messageId === '') {
            return null;
        }

        $text = is_string($message['text'] ?? null) ? $message['text'] : null;
        if ($text === null && is_string($message['caption'] ?? null)) {
            $text = $message['caption'];
        }

        [$messageType, $attachments] = $this->resolveTypeAndAttachments($message);

        $username = is_string($message['from']['username'] ?? null) ? (string) $message['from']['username'] : null;
        $firstName = is_string($message['from']['first_name'] ?? null) ? (string) $message['from']['first_name'] : null;

        return new TelegramInboundUpdate(
            updateId: $updateId,
            chatId: $chatId,
            senderUserId: $senderUserId,
            messageId: $messageId,
            text: $text,
            messageType: $messageType,
            attachments: $attachments,
            businessConnectionId: $businessConnectionId,
            username: $username,
            firstName: $firstName,
            safeMetadata: [
                'telegram_chat_id' => $chatId,
                'telegram_message_type' => $messageType,
            ],
        );
    }

    /**
     * @param  array<string, mixed>  $message
     * @return array{0: string, 1: list<array<string, mixed>>}
     */
    private function resolveTypeAndAttachments(array $message): array
    {
        if (is_array($message['photo'] ?? null)) {
            $photos = $message['photo'];
            $largest = is_array($photos) ? end($photos) : null;

            return ['image', [[
                'kind' => 'image',
                'provider_media_id' => is_array($largest) ? (string) ($largest['file_id'] ?? '') : '',
            ]]];
        }

        foreach (['video' => 'video', 'audio' => 'audio', 'voice' => 'audio', 'document' => 'file'] as $key => $kind) {
            if (is_array($message[$key] ?? null)) {
                return [$kind, [[
                    'kind' => $kind,
                    'provider_media_id' => (string) ($message[$key]['file_id'] ?? ''),
                    'original_name' => (string) ($message[$key]['file_name'] ?? $kind),
                    'mime_type' => (string) ($message[$key]['mime_type'] ?? null),
                ]]];
            }
        }

        return ['text', []];
    }
}
