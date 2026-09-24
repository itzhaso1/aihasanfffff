<?php

namespace App\Services\Finance\Assistant\Conversation;

use App\Models\Finance\FinanceAiConversation;
use App\Models\Finance\FinanceAiMessage;
use App\Models\Finance\FinanceAiToolCall;
use App\Models\User;
use App\Models\Workspace;
use App\Support\Tenancy\WorkspaceContext;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\DB;

final class FinanceAiConversationService
{
    public function __construct(
        private readonly WorkspaceContext $workspaceContext,
        private readonly FinanceAgentResultSafeEncoder $encoder,
        private readonly ConversationEntityTracker $entityTracker,
    ) {}

    public function resolveOrCreate(Workspace $workspace, User $user, mixed $conversationId, string $locale = 'ar'): FinanceAiConversation
    {
        if ($conversationId === null || $conversationId === '') {
            return $this->create($workspace, $user, $locale);
        }

        if (! is_numeric($conversationId) || (int) $conversationId <= 0) {
            throw FinanceAiConversationNotFoundException::forLookup();
        }

        return $this->findOwnedActive($workspace, $user, (int) $conversationId);
    }

    public function create(Workspace $workspace, User $user, string $locale = 'ar'): FinanceAiConversation
    {
        return $this->workspaceContext->runFor($workspace, function () use ($workspace, $user, $locale): FinanceAiConversation {
            return FinanceAiConversation::query()->create([
                'workspace_id' => $workspace->id,
                'user_id' => $user->id,
                'status' => FinanceAiConversation::STATUS_ACTIVE,
                'locale' => $locale,
            ]);
        });
    }

    public function findOwned(Workspace $workspace, User $user, int $conversationId): FinanceAiConversation
    {
        $conversation = FinanceAiConversation::queryForWorkspace((int) $workspace->id)
            ->whereKey($conversationId)
            ->where('user_id', $user->id)
            ->first();

        if (! $conversation) {
            throw FinanceAiConversationNotFoundException::forLookup();
        }

        return $conversation;
    }

    public function findOwnedActive(Workspace $workspace, User $user, int $conversationId): FinanceAiConversation
    {
        $conversation = $this->findOwned($workspace, $user, $conversationId);
        if (! $conversation->isActive()) {
            throw FinanceAiConversationNotFoundException::forLookup();
        }

        return $conversation;
    }

    public function listOwned(Workspace $workspace, User $user, string $status = FinanceAiConversation::STATUS_ACTIVE): LengthAwarePaginator
    {
        $status = $status === FinanceAiConversation::STATUS_ARCHIVED
            ? FinanceAiConversation::STATUS_ARCHIVED
            : FinanceAiConversation::STATUS_ACTIVE;

        return FinanceAiConversation::queryForWorkspace((int) $workspace->id)
            ->where('user_id', $user->id)
            ->where('status', $status)
            ->orderByDesc('last_message_at')
            ->orderByDesc('id')
            ->paginate(20);
    }

    public function archive(Workspace $workspace, User $user, int $conversationId): FinanceAiConversation
    {
        return $this->workspaceContext->runFor($workspace, function () use ($workspace, $user, $conversationId): FinanceAiConversation {
            return DB::transaction(function () use ($workspace, $user, $conversationId): FinanceAiConversation {
                $conversation = $this->lockOwned($workspace, $user, $conversationId);
                $conversation->status = FinanceAiConversation::STATUS_ARCHIVED;
                $conversation->processing_started_at = null;
                $conversation->save();

                return $conversation->fresh();
            });
        });
    }

    public function deleteOwned(Workspace $workspace, User $user, int $conversationId): void
    {
        $this->workspaceContext->runFor($workspace, function () use ($workspace, $user, $conversationId): void {
            DB::transaction(function () use ($workspace, $user, $conversationId): void {
                $conversation = $this->lockOwned($workspace, $user, $conversationId);
                $conversation->delete();
            });
        });
    }

    public function beginTurn(
        Workspace $workspace,
        User $user,
        FinanceAiConversation $conversation,
        string $question,
        string $locale,
    ): FinanceAiConversationTurn {
        return $this->workspaceContext->runFor($workspace, function () use ($workspace, $user, $conversation, $question, $locale): FinanceAiConversationTurn {
            return DB::transaction(function () use ($workspace, $user, $conversation, $question, $locale): FinanceAiConversationTurn {
                $locked = $this->lockOwned($workspace, $user, (int) $conversation->id);
                if (! $locked->isActive()) {
                    throw FinanceAiConversationNotFoundException::forLookup();
                }

                $timeout = max(15, (int) config('finance_assistant.conversation_processing_timeout', 90));
                if ($locked->processing_started_at !== null
                    && $locked->processing_started_at->gt(now()->subSeconds($timeout))) {
                    throw FinanceAiConversationBusyException::inProgress();
                }

                $locked->processing_started_at = now();
                $locked->locale = $locale;
                $locked->last_turn_seq = (int) $locked->last_turn_seq + 1;
                if (! filled($locked->title)) {
                    $locked->title = mb_substr(trim($question), 0, 80);
                }
                $locked->save();

                $message = FinanceAiMessage::query()->create([
                    'workspace_id' => $workspace->id,
                    'conversation_id' => $locked->id,
                    'turn_seq' => $locked->last_turn_seq,
                    'user_id' => $user->id,
                    'role' => FinanceAiMessage::ROLE_USER,
                    'body' => mb_substr(trim($question), 0, 2000),
                    'locale' => $locale,
                ]);

                return new FinanceAiConversationTurn($locked->fresh(), $message);
            });
        });
    }

    /**
     * @param  array<string, mixed>  $payload
     * @param  list<array<string, mixed>>  $trace
     */
    public function completeTurn(
        Workspace $workspace,
        User $user,
        FinanceAiConversationTurn $turn,
        array $payload,
        array $trace = [],
        ?string $geminiInteractionId = null,
    ): array {
        $this->workspaceContext->runFor($workspace, function () use ($workspace, $user, $turn, $payload, $trace, $geminiInteractionId): void {
            DB::transaction(function () use ($workspace, $user, $turn, $payload, $trace, $geminiInteractionId): void {
                $locked = $this->lockOwned($workspace, $user, (int) $turn->conversation->id);

                $period = is_array($payload['period'] ?? null) ? $payload['period'] : null;
                $facts = is_array($payload['facts'] ?? null) ? $payload['facts'] : [];
                $tools = is_array($payload['tools_used'] ?? null) ? $payload['tools_used'] : [];
                $safeFacts = $this->encoder->facts($facts);

                $assistant = FinanceAiMessage::query()->create([
                    'workspace_id' => $workspace->id,
                    'conversation_id' => $locked->id,
                    'turn_seq' => (int) $turn->userMessage->turn_seq,
                    'user_id' => $user->id,
                    'role' => FinanceAiMessage::ROLE_ASSISTANT,
                    'body' => mb_substr((string) ($payload['answer'] ?? ''), 0, 8000),
                    'locale' => $turn->userMessage->locale,
                    'period_from' => $period['from'] ?? null,
                    'period_to' => $period['to'] ?? null,
                    'facts_safe' => $safeFacts,
                    'tools_used' => array_values($tools),
                ]);

                $this->persistToolCalls($workspace, $locked, $assistant, $facts, $trace);

                $metricNames = array_values(array_unique(array_filter(array_map(
                    fn (array $fact): string => (string) ($fact['tool'] ?? ''),
                    $facts,
                ))));

                $locked->last_period_from = $period['from'] ?? $locked->last_period_from;
                $locked->last_period_to = $period['to'] ?? $locked->last_period_to;
                if ($metricNames !== []) {
                    $locked->last_metric_names = $metricNames;
                }

                $entityState = $this->entityTracker->fromConversation($locked);
                $entityState = $this->entityTracker->mergeFromQuestion($entityState, (string) $turn->userMessage->body);
                $entityState = $this->entityTracker->mergeFromTurn($entityState, $payload);
                $locked->context_entities = $entityState->toArray();

                if ($geminiInteractionId !== null && $geminiInteractionId !== '') {
                    $locked->gemini_interaction_id = $geminiInteractionId;
                }
                $locked->processing_started_at = null;
                $locked->last_message_at = now();
                $locked->save();
            });
        });

        $payload['conversation_id'] = (int) $turn->conversation->id;

        return $payload;
    }

    public function failTurn(Workspace $workspace, User $user, FinanceAiConversationTurn $turn): void
    {
        $this->workspaceContext->runFor($workspace, function () use ($workspace, $user, $turn): void {
            DB::transaction(function () use ($workspace, $user, $turn): void {
                $locked = $this->lockOwned($workspace, $user, (int) $turn->conversation->id);
                FinanceAiMessage::queryForWorkspace((int) $workspace->id)
                    ->where('conversation_id', $locked->id)
                    ->whereKey($turn->userMessage->id)
                    ->where('role', FinanceAiMessage::ROLE_USER)
                    ->delete();
                $locked->processing_started_at = null;
                $locked->save();
            });
        });
    }

    /**
     * @return list<FinanceAiMessage>
     */
    public function orderedMessages(FinanceAiConversation $conversation): array
    {
        return FinanceAiMessage::queryForWorkspace((int) $conversation->workspace_id)
            ->where('conversation_id', $conversation->id)
            ->orderBy('turn_seq')
            ->orderByRaw("CASE WHEN role = 'user' THEN 0 ELSE 1 END")
            ->orderBy('id')
            ->get()
            ->all();
    }

    /**
     * @return list<FinanceAiMessage>
     */
    public function priorMessages(FinanceAiConversationTurn $turn): array
    {
        return FinanceAiMessage::queryForWorkspace((int) $turn->conversation->workspace_id)
            ->where('conversation_id', $turn->conversation->id)
            ->where('turn_seq', '<', (int) $turn->userMessage->turn_seq)
            ->orderBy('turn_seq')
            ->orderByRaw("CASE WHEN role = 'user' THEN 0 ELSE 1 END")
            ->orderBy('id')
            ->get()
            ->all();
    }

    /**
     * Stored Gemini interaction IDs are never accepted from the client.
     */
    public function storedInteractionId(FinanceAiConversation $conversation): ?string
    {
        $id = trim((string) $conversation->gemini_interaction_id);

        return $id !== '' ? $id : null;
    }

    private function lockOwned(Workspace $workspace, User $user, int $conversationId): FinanceAiConversation
    {
        $locked = FinanceAiConversation::queryForWorkspace((int) $workspace->id)
            ->whereKey($conversationId)
            ->where('user_id', $user->id)
            ->lockForUpdate()
            ->first();

        if (! $locked) {
            throw FinanceAiConversationNotFoundException::forLookup();
        }

        return $locked;
    }

    /**
     * @param  list<array<string, mixed>>  $facts
     * @param  list<array<string, mixed>>  $trace
     */
    private function persistToolCalls(
        Workspace $workspace,
        FinanceAiConversation $conversation,
        FinanceAiMessage $assistant,
        array $facts,
        array $trace,
    ): void {
        $roundsByTool = [];
        foreach ($trace as $round) {
            $roundNumber = (int) ($round['round'] ?? 1);
            foreach ($round['calls'] ?? [] as $call) {
                $tool = (string) ($call['tool'] ?? '');
                if ($tool !== '') {
                    $roundsByTool[$tool] = $roundNumber;
                }
            }
        }

        $persistedFromTrace = false;
        foreach ($trace as $round) {
            $roundNumber = (int) ($round['round'] ?? 1);
            foreach ($round['calls'] ?? [] as $call) {
                if (! is_array($call) || ! empty($call['duplicate'])) {
                    continue;
                }
                $persistedFromTrace = true;
                $tool = (string) ($call['tool'] ?? 'unknown');
                $success = (bool) ($call['success'] ?? false);
                $reason = (string) ($call['permission_result'] ?? ($success ? 'allowed' : 'unavailable'));
                $arguments = is_array($call['arguments_safe'] ?? null) ? $call['arguments_safe'] : [];
                $result = is_array($call['result_safe'] ?? null)
                    ? $this->encoder->stripResult($call['result_safe'])
                    : [];

                FinanceAiToolCall::query()->create([
                    'workspace_id' => $workspace->id,
                    'conversation_id' => $conversation->id,
                    'message_id' => $assistant->id,
                    'tool_name' => mb_substr($tool, 0, 64),
                    'round' => $roundNumber,
                    'arguments_safe' => $this->encoder->strip($arguments),
                    'result_safe' => $result,
                    'permission_result' => mb_substr($reason !== '' ? $reason : ($success ? 'allowed' : 'unavailable'), 0, 32),
                    'duration_ms' => max(0, (int) ($call['duration_ms'] ?? 0)),
                    'success' => $success,
                ]);
            }
        }

        if ($persistedFromTrace) {
            return;
        }

        foreach ($facts as $index => $fact) {
            if (! is_array($fact)) {
                continue;
            }
            $tool = (string) ($fact['tool'] ?? 'unknown');
            $available = (bool) ($fact['available'] ?? false);
            $reason = (string) ($fact['reason'] ?? ($available ? 'allowed' : 'unavailable'));
            $safe = $this->encoder->fact($fact);

            FinanceAiToolCall::query()->create([
                'workspace_id' => $workspace->id,
                'conversation_id' => $conversation->id,
                'message_id' => $assistant->id,
                'tool_name' => mb_substr($tool, 0, 64),
                'round' => $roundsByTool[$tool] ?? ($index + 1),
                'arguments_safe' => [],
                'result_safe' => $safe,
                'permission_result' => mb_substr($reason !== '' ? $reason : ($available ? 'allowed' : 'unavailable'), 0, 32),
                'duration_ms' => 0,
                'success' => $available,
            ]);
        }
    }
}
