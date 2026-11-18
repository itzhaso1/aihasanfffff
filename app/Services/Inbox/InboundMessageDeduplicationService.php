<?php

declare(strict_types=1);

namespace App\Services\Inbox;

use App\Models\Conversation;
use App\Models\Message;

class InboundMessageDeduplicationService
{
    /**
     * Normalize content for duplicate comparison (removes redundant whitespace, punctuation, diacritics).
     */
    public function normalizeText(string $text): string
    {
        $normalized = trim($text);
        // Remove common Arabic diacritics
        $normalized = preg_replace('/[\x{064B}-\x{065F}\x{0670}]/u', '', $normalized) ?? $normalized;
        // Normalize Arabic alef variants
        $normalized = preg_replace('/[إأآآ]/u', 'ا', $normalized) ?? $normalized;
        // Normalize teh marbuta to heh
        $normalized = preg_replace('/ة/u', 'ه', $normalized) ?? $normalized;
        // Normalize whitespace
        $normalized = preg_replace('/\s+/u', ' ', $normalized) ?? $normalized;
        // Remove trailing punctuation and question marks
        $normalized = trim($normalized, " \t\n\r\0\x0B?!؟.,؛");

        return mb_strtolower($normalized, 'UTF-8');
    }

    /**
     * Check if this inbound message is a duplicate or rapid repeat.
     */
    public function findDuplicateInboundMessage(
        int $workspaceId,
        int $conversationId,
        ?string $externalMessageId = null,
        ?string $idempotencyKey = null,
        ?string $content = null,
        int $windowSeconds = 15,
    ): ?Message {
        // 1. Check by external_message_id (hard unique)
        if ($externalMessageId !== null && trim($externalMessageId) !== '') {
            $existing = Message::withoutGlobalScopes()
                ->where('workspace_id', $workspaceId)
                ->where('external_message_id', trim($externalMessageId))
                ->first();

            if ($existing) {
                return $existing;
            }
        }

        // 2. Check by idempotency_key in metadata
        if ($idempotencyKey !== null && trim($idempotencyKey) !== '') {
            $key = trim($idempotencyKey);
            $existing = Message::withoutGlobalScopes()
                ->where('workspace_id', $workspaceId)
                ->where('conversation_id', $conversationId)
                ->where(function ($query) use ($key): void {
                    $query->where('metadata->idempotency_key', $key)
                        ->orWhere('metadata->client_id', $key);
                })
                ->first();

            if ($existing) {
                return $existing;
            }
        }

        // 3. Debounce check for rapid identical repeat (webhook retries or fast double-tap)
        if ($content !== null && trim($content) !== '') {
            $normalized = $this->normalizeText($content);
            if ($normalized !== '') {
                $recent = Message::withoutGlobalScopes()
                    ->where('workspace_id', $workspaceId)
                    ->where('conversation_id', $conversationId)
                    ->where('direction', 'inbound')
                    ->where('created_at', '>=', now()->subSeconds($windowSeconds))
                    ->latest('id')
                    ->first();

                if ($recent && $this->normalizeText((string) $recent->content) === $normalized) {
                    return $recent;
                }
            }
        }

        return null;
    }

    /**
     * Find if this incoming message is an identical repeat of a recent customer question
     * that has ALREADY been answered by AI in this conversation.
     *
     * @return array{message: Message, reply: Message}|null
     */
    public function findRecentAnsweredIdenticalInbound(
        Conversation $conversation,
        Message $incomingMessage,
        int $windowSeconds = 30,
    ): ?array {
        $incomingContent = $this->normalizeText((string) $incomingMessage->content);
        if ($incomingContent === '') {
            return null;
        }

        $incomingTime = $incomingMessage->created_at ?? now();

        // Find recent customer inbound messages before this incoming message
        $priorInbound = Message::withoutGlobalScopes()
            ->where('workspace_id', $conversation->workspace_id)
            ->where('conversation_id', $conversation->id)
            ->where('direction', 'inbound')
            ->where('id', '<', $incomingMessage->id)
            ->where('created_at', '>=', $incomingTime->copy()->subSeconds($windowSeconds))
            ->latest('id')
            ->first();

        if (! $priorInbound) {
            return null;
        }

        if ($this->normalizeText((string) $priorInbound->content) !== $incomingContent) {
            return null;
        }

        // Check if an AI reply was already generated and recorded for that prior inbound message
        $aiReply = Message::withoutGlobalScopes()
            ->where('workspace_id', $conversation->workspace_id)
            ->where('conversation_id', $conversation->id)
            ->where('direction', 'outbound')
            ->where('ai_generated', true)
            ->where(function ($query) use ($priorInbound): void {
                $query->where('metadata->in_reply_to_message_id', $priorInbound->id)
                    ->orWhere('external_message_id', 'ai_reply:'.$priorInbound->id)
                    ->orWhere('id', '>', $priorInbound->id);
            })
            ->latest('id')
            ->first();

        if ($aiReply) {
            return [
                'message' => $priorInbound,
                'reply' => $aiReply,
            ];
        }

        return null;
    }
}
