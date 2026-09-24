<?php

namespace App\Services\AI\Inbox\Memory;

use App\Models\AiSetting;
use App\Models\Conversation;
use App\Models\Customer;
use App\Models\Message;
use App\Models\Workspace;
use App\Services\AI\AiUsageAccountant;
use App\Services\AI\Inbox\Memory\Contracts\InboxAgentMemoryExtractor;
use App\Services\Feature\FeatureAccessService;
use Illuminate\Support\Facades\Log;
use Throwable;

final class InboxAgentMemoryExtractionOrchestrator
{
    public function __construct(
        private readonly InboxAgentMemoryExtractor $extractor,
        private readonly InboxAgentMemoryValidator $validator,
        private readonly InboxAgentMemoryStore $store,
        private readonly InboxAgentMemoryPolicy $policy,
        private readonly AiUsageAccountant $usageAccountant,
        private readonly FeatureAccessService $featureAccess,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function process(
        Workspace $workspace,
        Conversation $conversation,
        Message $message,
        AiSetting $setting,
    ): array {
        if (! $this->policy->enabled()) {
            return ['status' => 'disabled'];
        }

        Log::info('inbox.memory.extraction_started', [
            'workspace_id' => $workspace->id,
            'conversation_id' => $conversation->id,
            'customer_id' => $conversation->customer_id,
            'message_id' => $message->id,
        ]);

        try {
            $result = $this->extractor->extract($workspace, $conversation, $message, $setting);
        } catch (Throwable $exception) {
            Log::warning('inbox.memory.extraction_failed', [
                'workspace_id' => $workspace->id,
                'message_id' => $message->id,
                'reason' => $exception->getMessage(),
            ]);

            return ['status' => 'failed', 'reason' => 'extractor_error'];
        }

        $customer = Customer::withoutGlobalScopes()
            ->where('workspace_id', $workspace->id)
            ->where('id', $conversation->customer_id)
            ->first();

        $stats = [
            'status' => 'processed',
            'candidates' => count($result->candidates),
            'created' => 0,
            'deduplicated' => 0,
            'rejected' => 0,
        ];

        foreach ($result->candidates as $candidate) {
            try {
                $validated = $this->validator->validateCandidate(
                    $candidate,
                    $workspace,
                    $conversation,
                    $customer,
                    $message,
                );
                $storeResult = $this->store->persist($validated, $workspace, $conversation, $customer);
                if ($storeResult->outcome === 'created') {
                    $stats['created']++;
                } else {
                    $stats['deduplicated']++;
                }
            } catch (InboxAgentMemoryValidationException $exception) {
                $stats['rejected']++;
                Log::info('inbox.memory.candidate_rejected', [
                    'workspace_id' => $workspace->id,
                    'message_id' => $message->id,
                    'key' => $candidate->key,
                    'scope' => $candidate->scope->value,
                    'reason' => $exception->getMessage(),
                ]);
            }
        }

        $this->recordMemoryUsage($workspace, $message, $result->tokensUsed);

        Log::info('inbox.memory.extraction_completed', [
            'workspace_id' => $workspace->id,
            'message_id' => $message->id,
            'candidate_count' => $stats['candidates'],
            'created' => $stats['created'],
            'deduplicated' => $stats['deduplicated'],
            'rejected' => $stats['rejected'],
        ]);

        return $stats;
    }

    private function recordMemoryUsage(Workspace $workspace, Message $message, int $tokensUsed): void
    {
        if ($tokensUsed <= 0) {
            return;
        }

        $eventKey = 'memory-extract:'.$workspace->id.':'.($message->exists ? (string) $message->id : 'ephemeral');
        if (! $this->usageAccountant->claim((int) $workspace->id, $eventKey)) {
            return;
        }

        try {
            $this->featureAccess->consumeUsage($workspace, 'ai_tokens', $tokensUsed, enforce: false);
        } catch (Throwable) {
            // Memory usage is secondary; do not fail extraction bookkeeping.
        }
    }
}
