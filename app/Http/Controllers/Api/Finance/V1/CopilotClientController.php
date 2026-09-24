<?php

namespace App\Http\Controllers\Api\Finance\V1;

use App\Exceptions\Api\ApiErrorCode;
use App\Http\Controllers\Api\Finance\Concerns\HandlesFinanceClient;
use App\Http\Controllers\Api\Finance\FinanceApiController;
use App\Models\Workspace;
use App\Services\Feature\FeatureAccessService;
use App\Services\Finance\Assistant\Conversation\FinanceAiConversationBusyException;
use App\Services\Finance\Assistant\Conversation\FinanceAiConversationNotFoundException;
use App\Services\Finance\Assistant\FinanceAssistantTurnService;
use App\Services\Finance\Assistant\FinanceCopilotLegacyAdapter;
use App\Support\Tenancy\WorkspaceContext;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use InvalidArgumentException;

/**
 * @deprecated Use AssistantController (/assistant/ask). Kept for backward-compatible clients.
 */
class CopilotClientController extends FinanceApiController
{
    use HandlesFinanceClient;

    public function __construct(
        private readonly WorkspaceContext $workspaceContext,
        private readonly FinanceAssistantTurnService $assistantTurns,
        private readonly FinanceCopilotLegacyAdapter $legacyAdapter,
        private readonly FeatureAccessService $featureAccessService,
    ) {}

    public function ask(Request $request): JsonResponse
    {
        $workspace = $this->clientWorkspace($this->workspaceContext);
        $user = $this->clientActor($request, $workspace, 'finance.view');
        $this->ensureAi($request, $workspace);

        $validated = $request->validate([
            'question' => ['required', 'string', 'max:2000'],
            'conversation_id' => ['nullable', 'integer', 'min:1'],
            'locale' => ['nullable', 'string', 'max:8'],
        ]);

        try {
            $result = $this->assistantTurns->ask(
                $workspace,
                $user,
                $validated['question'],
                isset($validated['conversation_id']) ? (int) $validated['conversation_id'] : null,
                (string) ($validated['locale'] ?? 'ar'),
            );
        } catch (FinanceAiConversationNotFoundException) {
            return $this->fail('Conversation not found.', ApiErrorCode::NotFound, 404);
        } catch (FinanceAiConversationBusyException $exception) {
            return $this->fail($exception->getMessage(), ApiErrorCode::IdempotencyConflict, 409);
        } catch (InvalidArgumentException $exception) {
            return $this->fail($exception->getMessage(), ApiErrorCode::ValidationFailed, 422);
        }

        return $this->ok($this->legacyAdapter->adapt($result['payload']));
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
}
