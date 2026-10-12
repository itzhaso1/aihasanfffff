<?php

namespace App\Services\AI\Inbox\Memory;

use App\Enums\InboxAgentMemoryScope;
use App\Enums\InboxAgentMemoryStatus;
use App\Models\Conversation;
use App\Models\Customer;
use App\Models\InboxAgentMemory;
use App\Models\Workspace;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\Log;

final class InboxAgentMemoryStore
{
    public function __construct(
        private readonly InboxAgentMemoryPolicy $policy,
        private readonly InboxAgentMemoryValueCodec $codec,
    ) {}

    public function persist(
        InboxAgentMemoryCandidate $candidate,
        Workspace $workspace,
        Conversation $conversation,
        ?Customer $customer,
    ): InboxAgentMemoryStoreResult {
        $now = now();
        $expiresAt = $candidate->scope === InboxAgentMemoryScope::Conversation
            ? $now->copy()->addDays($this->policy->conversationTtlDays())
            : null;

        $encoded = $this->codec->encode($candidate->value, $candidate->valueType);

        try {
            $existingFromSource = null;
            if ($candidate->sourceMessageId !== null) {
                $existingFromSource = InboxAgentMemory::withoutGlobalScopes()
                    ->where('workspace_id', $workspace->id)
                    ->where('source_message_id', $candidate->sourceMessageId)
                    ->where('scope', $candidate->scope)
                    ->where('key', $candidate->key)
                    ->first();
            }

            if ($existingFromSource !== null) {
                return InboxAgentMemoryStoreResult::deduplicated($existingFromSource);
            }

            $active = $this->findActiveMemory($workspace, $conversation, $customer, $candidate);

            if ($active !== null && $this->valuesEqual($active, $candidate)) {
                $active->last_confirmed_at = $now;
                $active->confidence = max((float) $active->confidence, $candidate->confidence);
                $active->save();

                Log::info('inbox.memory.deduplicated', [
                    'workspace_id' => $workspace->id,
                    'memory_id' => $active->id,
                    'key' => $candidate->key,
                    'scope' => $candidate->scope->value,
                ]);

                return InboxAgentMemoryStoreResult::deduplicated($active);
            }

            if ($active !== null) {
                $active->status = InboxAgentMemoryStatus::Superseded;
                $active->save();
            }

            $memory = InboxAgentMemory::withoutGlobalScopes()->create([
                'workspace_id' => $workspace->id,
                'customer_id' => $candidate->scope === InboxAgentMemoryScope::Customer ? $customer?->id : null,
                'conversation_id' => $candidate->scope === InboxAgentMemoryScope::Conversation ? $conversation->id : null,
                'scope' => $candidate->scope,
                'key' => $candidate->key,
                'value' => $encoded,
                'value_type' => $candidate->valueType,
                'status' => InboxAgentMemoryStatus::Active,
                'confidence' => $candidate->confidence,
                'evidence_type' => $candidate->evidenceType,
                'source_message_id' => $candidate->sourceMessageId,
                'source_conversation_id' => $candidate->sourceConversationId ?? $conversation->id,
                'first_seen_at' => $now,
                'last_confirmed_at' => $now,
                'expires_at' => $expiresAt,
                'metadata' => [
                    'reason_hash' => $candidate->reason !== '' ? substr(hash('sha256', $candidate->reason), 0, 16) : null,
                ],
            ]);

            Log::info('inbox.memory.created', [
                'workspace_id' => $workspace->id,
                'memory_id' => $memory->id,
                'key' => $candidate->key,
                'scope' => $candidate->scope->value,
                'superseded_id' => $active?->id,
            ]);

            return InboxAgentMemoryStoreResult::created($memory, superseded: $active);
        } catch (QueryException $exception) {
            if ($this->isSourceDedupeViolation($exception)) {
                $existing = InboxAgentMemory::withoutGlobalScopes()
                    ->where('workspace_id', $workspace->id)
                    ->where('source_message_id', $candidate->sourceMessageId)
                    ->where('scope', $candidate->scope)
                    ->where('key', $candidate->key)
                    ->first();

                if ($existing !== null) {
                    return InboxAgentMemoryStoreResult::deduplicated($existing);
                }
            }

            throw $exception;
        }
    }

    private function findActiveMemory(
        Workspace $workspace,
        Conversation $conversation,
        ?Customer $customer,
        InboxAgentMemoryCandidate $candidate,
    ): ?InboxAgentMemory {
        $query = InboxAgentMemory::withoutGlobalScopes()
            ->active()
            ->where('workspace_id', $workspace->id)
            ->where('scope', $candidate->scope)
            ->where('key', $candidate->key);

        if ($candidate->scope === InboxAgentMemoryScope::Customer) {
            $query->where('customer_id', $customer?->id);
        } else {
            $query->where('conversation_id', $conversation->id);
        }

        return $query->first();
    }

    private function valuesEqual(InboxAgentMemory $existing, InboxAgentMemoryCandidate $candidate): bool
    {
        $stored = $this->codec->decode($existing->value, $existing->value_type);
        $existingDisplay = $this->codec->toDisplayString($stored, $existing->value_type);
        $candidateDisplay = $this->codec->toDisplayString($candidate->value, $candidate->valueType);

        return $existingDisplay === $candidateDisplay;
    }

    private function isSourceDedupeViolation(QueryException $exception): bool
    {
        return str_contains(strtolower($exception->getMessage()), 'inbox_mem_ws_source_scope_key');
    }
}
