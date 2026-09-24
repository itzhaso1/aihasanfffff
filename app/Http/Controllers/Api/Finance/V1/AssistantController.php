<?php

namespace App\Http\Controllers\Api\Finance\V1;

use App\Exceptions\Api\ApiErrorCode;
use App\Http\Controllers\Api\Finance\Concerns\HandlesFinanceClient;
use App\Http\Controllers\Api\Finance\FinanceApiController;
use App\Models\Finance\FinanceAiConversation;
use App\Models\User;
use App\Models\Workspace;
use App\Services\AI\AiUsageAccountant;
use App\Services\Feature\FeatureAccessService;
use App\Services\Finance\Assistant\Agent\FinanceAgentOrchestrator;
use App\Services\Finance\Assistant\Conversation\FinanceAgentConversationContext;
use App\Services\Finance\Assistant\Conversation\FinanceAiConversationBusyException;
use App\Services\Finance\Assistant\Conversation\FinanceAiConversationNotFoundException;
use App\Services\Finance\Assistant\Conversation\FinanceAiConversationService;
use App\Services\Finance\Assistant\FinancialAssistantOrchestrator;
use App\Services\Mobile\IdempotencyService;
use App\Support\Tenancy\WorkspaceContext;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use InvalidArgumentException;
use Throwable;

class AssistantController extends FinanceApiController
{
    use HandlesFinanceClient;

    public function __construct(
        private readonly WorkspaceContext $workspaceContext,
        private readonly FeatureAccessService $featureAccessService,
        private readonly FinancialAssistantOrchestrator $orchestrator,
        private readonly FinanceAgentOrchestrator $agentOrchestrator,
        private readonly FinanceAiConversationService $conversations,
        private readonly IdempotencyService $idempotency,
        private readonly AiUsageAccountant $usageAccountant,
    ) {}

    public function ask(Request $request): JsonResponse
    {
        $workspace = $this->clientWorkspace($this->workspaceContext);
        $user = $this->authorizeAssistantMember($request, $workspace);
        $this->ensureAi($request, $workspace);

        $claim = $this->idempotency->begin(
            $user,
            $workspace,
            $request,
            processingTimeoutSeconds: (int) config('finance_assistant.conversation_processing_timeout', 90),
        );

        if ($claim->isReplay() && $claim->record) {
            return $this->idempotency->replay($claim->record);
        }
        if ($claim->isConflict()) {
            return $this->fail('Idempotency-Key already used with a different request.', ApiErrorCode::IdempotencyConflict, 409);
        }
        if ($claim->isBusy()) {
            return $this->fail('This request is already in progress.', ApiErrorCode::IdempotencyConflict, 409);
        }

        try {
            $response = $this->executeAsk($request, $workspace, $user);
        } catch (Throwable $exception) {
            if ($claim->isClaimed() && $claim->record) {
                $this->idempotency->release($claim->record);
            }
            throw $exception;
        }

        if ($claim->isClaimed() && $claim->record) {
            $this->idempotency->complete($claim->record, $response);
        }

        return $response;
    }

    private function executeAsk(Request $request, Workspace $workspace, User $user): JsonResponse
    {
        $validated = $request->validate([
            'question' => ['required', 'string', 'max:2000'],
            'locale' => ['nullable', 'string', 'max:8'],
            'conversation_id' => ['nullable', 'integer', 'min:1'],
        ]);

        $question = $validated['question'];
        $locale = (string) ($validated['locale'] ?? 'ar');

        try {
            $conversation = $this->conversations->resolveOrCreate(
                $workspace,
                $user,
                $validated['conversation_id'] ?? null,
                $locale,
            );
            $turn = $this->conversations->beginTurn($workspace, $user, $conversation, $question, $locale);
            $context = new FinanceAgentConversationContext(
                $turn->conversation,
                $this->conversations->priorMessages($turn),
                $turn->usageEventKey(),
            );

            try {
                $payload = $this->orchestrator->ask(
                    $workspace,
                    $user,
                    $question,
                    $locale,
                    $context,
                    $turn->usageEventKey(),
                    consumeUsage: false,
                );

                $payload = $this->conversations->completeTurn(
                    $workspace,
                    $user,
                    $turn,
                    $payload,
                    $this->orchestrator->lastTrace,
                    $this->orchestrator->lastInteractionId,
                );

                if (! ($payload['skip_usage'] ?? false)) {
                    $this->commitUsage($workspace, $turn->usageEventKey(), $this->orchestrator->lastTokenTotal);
                }
            } catch (Throwable $exception) {
                $this->conversations->failTurn($workspace, $user, $turn);
                throw $exception;
            }
        } catch (FinanceAiConversationNotFoundException) {
            return $this->fail('Conversation not found.', ApiErrorCode::NotFound, 404);
        } catch (FinanceAiConversationBusyException $exception) {
            return $this->fail($exception->getMessage(), ApiErrorCode::IdempotencyConflict, 409);
        } catch (InvalidArgumentException $exception) {
            return $this->fail($exception->getMessage(), ApiErrorCode::ValidationFailed, 422);
        }

        return $this->ok($payload);
    }

    private function commitUsage(Workspace $workspace, string $eventKey, int $tokens): void
    {
        if (! $this->usageAccountant->claim((int) $workspace->id, $eventKey)) {
            return;
        }

        $this->featureAccessService->consumeUsage($workspace, 'ai_usage', 1, enforce: true);
        if ($tokens > 0) {
            $this->featureAccessService->consumeUsage($workspace, 'ai_tokens', $tokens, enforce: true);
        }
    }

    public function conversations(Request $request): JsonResponse
    {
        $workspace = $this->clientWorkspace($this->workspaceContext);
        $user = $this->authorizeAssistantMember($request, $workspace);
        $this->ensureAi($request, $workspace);

        $status = (string) $request->query('status', 'active');
        $page = $this->conversations->listOwned($workspace, $user, $status);

        return $this->ok(
            $page->getCollection()->map(fn ($conversation): array => $this->conversationSummary($conversation))->all(),
            $this->pageMeta($page),
        );
    }

    public function show(Request $request, int $conversation): JsonResponse
    {
        $workspace = $this->clientWorkspace($this->workspaceContext);
        $user = $this->authorizeAssistantMember($request, $workspace);
        $this->ensureAi($request, $workspace);

        try {
            $owned = $this->conversations->findOwned($workspace, $user, $conversation);
        } catch (FinanceAiConversationNotFoundException) {
            return $this->fail('Conversation not found.', ApiErrorCode::NotFound, 404);
        }

        $messages = collect($this->conversations->orderedMessages($owned))->map(fn ($message): array => [
            'id' => $message->id,
            'role' => $message->role,
            'body' => $message->body,
            'turn_seq' => $message->turn_seq,
            'created_at' => optional($message->created_at)?->toIso8601String(),
        ])->all();

        return $this->ok($this->conversationSummary($owned) + ['messages' => $messages]);
    }

    public function archive(Request $request, int $conversation): JsonResponse
    {
        $workspace = $this->clientWorkspace($this->workspaceContext);
        $user = $this->authorizeAssistantMember($request, $workspace);
        $this->ensureAi($request, $workspace);

        try {
            $owned = $this->conversations->archive($workspace, $user, $conversation);
        } catch (FinanceAiConversationNotFoundException) {
            return $this->fail('Conversation not found.', ApiErrorCode::NotFound, 404);
        }

        return $this->ok($this->conversationSummary($owned));
    }

    public function destroy(Request $request, int $conversation): JsonResponse
    {
        $workspace = $this->clientWorkspace($this->workspaceContext);
        $user = $this->authorizeAssistantMember($request, $workspace);
        $this->ensureAi($request, $workspace);

        try {
            $this->conversations->deleteOwned($workspace, $user, $conversation);
        } catch (FinanceAiConversationNotFoundException) {
            return $this->fail('Conversation not found.', ApiErrorCode::NotFound, 404);
        }

        return $this->ok(['deleted' => true]);
    }

    private function ensureAi(Request $request, Workspace $workspace): void
    {
        $user = $request->user();
        if (! $user || ! $this->featureAccessService->hasFeature($user, $workspace, 'ai')) {
            throw new HttpResponseException(
                $this->fail('ميزة الذكاء الاصطناعي غير متاحة لباقتك.', ApiErrorCode::Forbidden, 403)
            );
        }
    }

    /**
     * @return array<string, mixed>
     */
    private function conversationSummary(FinanceAiConversation $conversation): array
    {
        return [
            'id' => $conversation->id,
            'title' => $conversation->title,
            'status' => $conversation->status,
            'created_at' => optional($conversation->created_at)?->toIso8601String(),
            'updated_at' => optional($conversation->updated_at)?->toIso8601String(),
            'last_message_at' => optional($conversation->last_message_at)?->toIso8601String(),
        ];
    }
}
