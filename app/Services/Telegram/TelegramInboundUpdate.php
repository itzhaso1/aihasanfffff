<?php

namespace App\Services\Telegram;

final class TelegramInboundUpdate
{
    /**
     * @param  list<array<string, mixed>>  $attachments
     * @param  array<string, mixed>  $safeMetadata
     */
    public function __construct(
        public readonly string $updateId,
        public readonly string $chatId,
        public readonly string $senderUserId,
        public readonly string $messageId,
        public readonly ?string $text,
        public readonly string $messageType,
        public readonly array $attachments,
        public readonly ?string $businessConnectionId,
        public readonly ?string $username,
        public readonly ?string $firstName,
        public readonly array $safeMetadata,
    ) {}
}
