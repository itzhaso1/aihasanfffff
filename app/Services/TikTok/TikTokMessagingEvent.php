<?php

namespace App\Services\TikTok;

final class TikTokMessagingEvent
{
    /**
     * @param  list<array<string, mixed>>  $attachments
     * @param  array<string, mixed>  $safeMetadata
     */
    public function __construct(
        public readonly string $eventName,
        public readonly string $businessOpenId,
        public readonly string $senderUniqueId,
        public readonly string $conversationId,
        public readonly string $messageId,
        public readonly string $messageType,
        public readonly ?string $text,
        public readonly int $timestampMs,
        public readonly array $attachments,
        public readonly array $safeMetadata,
        public readonly bool $isInboundFromCustomer,
    ) {}
}
