<?php

namespace App\Services\AI\Inbox\Context;

use App\Models\Conversation;
use App\Models\Message;
use App\Services\AI\AiSafety;

/**
 * Builds a bounded recent-message window (current message excluded from history).
 */
final class InboxAgentMessageWindowBuilder
{
    public function __construct(
        private readonly InboxAgentContextPolicy $policy,
        private readonly AiSafety $safety,
    ) {}

    public function build(Conversation $conversation, Message $currentMessage): InboxAgentMessageWindow
    {
        $current = $this->normalizeCurrent($currentMessage, (string) $conversation->channel);

        $maxMessages = $this->policy->maxRecentMessages();
        $perMessageLimit = $this->policy->maxMessageCharacters();
        $historyBudget = $this->policy->maxHistoryCharacters();

        if ($maxMessages === 0) {
            return new InboxAgentMessageWindow(
                history: [],
                currentMessage: $current,
                droppedOldestCount: 0,
                perMessageTruncated: mb_strlen($current->content) >= $perMessageLimit,
                historyBudgetTruncated: false,
            );
        }

        $query = Message::withoutGlobalScopes()
            ->where('workspace_id', $conversation->workspace_id)
            ->where('conversation_id', $conversation->id)
            ->whereIn('direction', ['inbound', 'outbound'])
            ->where('message_type', '!=', 'system')
            ->when($currentMessage->exists, fn ($builder) => $builder->where('id', '!=', $currentMessage->id))
            ->orderByDesc('id')
            ->limit($maxMessages);

        /** @var list<Message> $rows */
        $rows = $query->get()->all();
        $rows = array_reverse($rows);
        $rows = $this->withoutDuplicateCurrent($rows, $currentMessage);

        $droppedBeforeWindow = 0;
        $perMessageTruncated = false;
        $normalized = [];

        foreach ($rows as $row) {
            $normalizedRow = $this->normalizeHistoryRow($row, (string) $conversation->channel, $perMessageLimit);
            if ($normalizedRow['truncated']) {
                $perMessageTruncated = true;
            }
            $normalized[] = $normalizedRow['message'];
        }

        $historyBudgetTruncated = false;
        $totalChars = array_sum(array_map(static fn (InboxAgentNormalizedMessage $m): int => mb_strlen($m->content), $normalized));

        while ($totalChars > $historyBudget && $normalized !== []) {
            $removed = array_shift($normalized);
            $droppedBeforeWindow++;
            $historyBudgetTruncated = true;
            $totalChars -= mb_strlen($removed->content);
        }

        return new InboxAgentMessageWindow(
            history: $normalized,
            currentMessage: $current,
            droppedOldestCount: $droppedBeforeWindow,
            perMessageTruncated: $perMessageTruncated,
            historyBudgetTruncated: $historyBudgetTruncated,
        );
    }

    private function normalizeCurrent(Message $message, string $channel): InboxAgentNormalizedMessage
    {
        $limit = $this->policy->maxMessageCharacters();
        $content = $this->prepareContent((string) ($message->content ?? ''), $limit);

        return new InboxAgentNormalizedMessage(
            role: 'current_customer',
            content: $content,
            messageId: $message->exists ? (int) $message->id : null,
            occurredAt: $message->created_at?->toIso8601String(),
            channel: $channel,
        );
    }

    /**
     * @return array{message: InboxAgentNormalizedMessage, truncated: bool}
     */
    private function normalizeHistoryRow(Message $row, string $channel, int $limit): array
    {
        $raw = (string) ($row->content ?? '');
        $truncated = mb_strlen($raw) > $limit;
        $content = $this->prepareContent($raw, $limit);
        $role = $this->historyRole($row);

        return [
            'message' => new InboxAgentNormalizedMessage(
                role: $role,
                content: $content,
                messageId: (int) $row->id,
                occurredAt: $row->created_at?->toIso8601String(),
                channel: $channel,
            ),
            'truncated' => $truncated,
        ];
    }

    /**
     * An unsaved suggestion reuses the latest inbound text. Drop that one copy
     * so the current utterance is not also history.
     *
     * @param  list<Message>  $rows
     * @return list<Message>
     */
    private function withoutDuplicateCurrent(array $rows, Message $currentMessage): array
    {
        if ($currentMessage->exists || $rows === []) {
            return $rows;
        }

        $current = trim((string) ($currentMessage->content ?? ''));
        if ($current === '') {
            return $rows;
        }

        for ($index = count($rows) - 1; $index >= 0; $index--) {
            $row = $rows[$index];
            if ($row->direction === 'inbound' && trim((string) ($row->content ?? '')) === $current) {
                array_splice($rows, $index, 1);

                break;
            }
        }

        return $rows;
    }

    private function historyRole(Message $row): string
    {
        if ($row->direction !== 'outbound') {
            return 'customer';
        }
        if ($row->ai_generated) {
            return 'ai';
        }
        if ($row->worker_engagement_id !== null) {
            return 'worker';
        }

        return 'human';
    }

    private function prepareContent(string $content, int $limit): string
    {
        return $this->safety->redactSecrets(
            $this->safety->truncateInput($content, $limit)
        );
    }
}
