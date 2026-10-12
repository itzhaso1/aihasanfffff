<?php

namespace App\Services\Meta\Instagram;

final class InstagramMessagingEvent
{
    /**
     * @param  list<array{type:string,url?:string}>  $attachments
     * @param  array<string, mixed>  $safeMetadata
     */
    public function __construct(
        public readonly string $senderId,
        public readonly string $recipientId,
        public readonly string $messageId,
        public readonly ?string $text,
        public readonly string $messageType,
        public readonly bool $isEcho,
        public readonly bool $isDeleted,
        public readonly ?int $timestampMs,
        public readonly array $attachments,
        public readonly array $safeMetadata,
    ) {}
}
