<?php

namespace App\Services\AI\Inbox\Memory;

use App\Models\Conversation;
use App\Models\Customer;
use App\Models\InboxAgentMemory;
use App\Models\Workspace;

final class InboxAgentMemoryRetriever
{
    public function __construct(
        private readonly InboxAgentMemoryPolicy $policy,
        private readonly InboxAgentMemoryValueCodec $codec,
    ) {}

    /**
     * @return array{
     *   customer: list<InboxAgentRetrievedMemory>,
     *   conversation: list<InboxAgentRetrievedMemory>,
     *   fingerprint: string
     * }
     */
    public function retrieve(Workspace $workspace, Conversation $conversation, ?Customer $customer): array
    {
        if (! $this->policy->enabled()) {
            return [
                'customer' => [],
                'conversation' => [],
                'fingerprint' => 'memory_disabled',
            ];
        }

        $limit = $this->policy->maxRetrieved();
        $conversationLimit = (int) ceil($limit / 2);
        $customerLimit = $limit - $conversationLimit;

        $conversationRows = InboxAgentMemory::withoutGlobalScopes()
            ->active()
            ->conversationScope()
            ->forConversation((int) $workspace->id, (int) $conversation->id)
            ->orderByDesc('last_confirmed_at')
            ->orderByDesc('confidence')
            ->limit($conversationLimit)
            ->get();

        $customerRows = collect();
        if ($customer !== null && $customerLimit > 0) {
            $customerRows = InboxAgentMemory::withoutGlobalScopes()
                ->active()
                ->customerScope()
                ->forCustomer((int) $workspace->id, (int) $customer->id)
                ->orderByDesc('last_confirmed_at')
                ->orderByDesc('confidence')
                ->limit($customerLimit)
                ->get();
        }

        $conversationMemories = $this->mapRows($conversationRows);
        $customerMemories = $this->mapRows($customerRows);

        $fingerprintPayload = [
            'c' => array_map(fn (InboxAgentRetrievedMemory $m) => $m->key, $customerMemories),
            'v' => array_map(fn (InboxAgentRetrievedMemory $m) => $m->key, $conversationMemories),
        ];

        return [
            'customer' => $customerMemories,
            'conversation' => $conversationMemories,
            'fingerprint' => 'mem_'.substr(hash('sha256', json_encode($fingerprintPayload, JSON_THROW_ON_ERROR)), 0, 12),
        ];
    }

    /**
     * @param  iterable<InboxAgentMemory>  $rows
     * @return list<InboxAgentRetrievedMemory>
     */
    private function mapRows(iterable $rows): array
    {
        $mapped = [];
        foreach ($rows as $row) {
            $decoded = $this->codec->decode($row->value, $row->value_type);
            $mapped[] = new InboxAgentRetrievedMemory(
                scope: $row->scope,
                key: (string) $row->key,
                displayValue: $this->codec->toDisplayString($decoded, $row->value_type),
            );
        }

        return $mapped;
    }
}
