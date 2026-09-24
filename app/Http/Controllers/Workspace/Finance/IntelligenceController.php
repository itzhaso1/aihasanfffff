<?php

namespace App\Http\Controllers\Workspace\Finance;

use App\Models\User;
use App\Services\Feature\FeatureAccessService;
use App\Services\Finance\Assistant\Conversation\FinanceAgentConversationContext;
use App\Services\Finance\Assistant\Conversation\FinanceAiConversationService;
use App\Services\Finance\Assistant\FinancialAssistantOrchestrator;
use App\Services\Finance\BusinessAlertService;
use App\Services\Finance\FinanceCopilotService;
use App\Services\Finance\FinanceSearchService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;
use Throwable;

class IntelligenceController extends FinanceBaseController
{
    public function alerts(Request $request, BusinessAlertService $alerts): View
    {
        $this->authorizeFinance($request, 'finance.view');

        return view('workspace.finance.alerts.index', [
            'alerts' => $alerts->alerts((int) $this->currentWorkspace()->id),
        ]);
    }

    public function assistant(Request $request, FinanceAiConversationService $conversations): View
    {
        $this->authorizeAssistant($request);
        $workspace = $this->currentWorkspace();
        /** @var User $user */
        $user = $request->user();
        $conversationId = (int) $request->integer('conversation_id');
        $messages = [];

        if ($conversationId > 0) {
            $conversation = $conversations->findOwned($workspace, $user, $conversationId);
            $messages = collect($conversations->orderedMessages($conversation))
                ->map(fn ($message): array => [
                    'role' => $message->role,
                    'body' => $message->body,
                ])
                ->all();
        }

        return view('workspace.finance.assistant.index', [
            'messages' => $messages,
            'conversationId' => $conversationId > 0 ? $conversationId : null,
            'question' => '',
        ]);
    }

    public function copilot(Request $request, FinanceAiConversationService $conversations): View
    {
        return $this->assistant($request, $conversations);
    }

    public function copilotAsk(Request $request, FinanceCopilotService $copilot): View
    {
        $this->authorizeFinance($request, 'finance.view');
        $validated = $request->validate([
            'question' => ['required', 'string', 'max:500'],
        ]);

        return view('workspace.finance.copilot.index', [
            'question' => $validated['question'],
            'result' => $copilot->ask((int) $this->currentWorkspace()->id, $validated['question']),
        ]);
    }

    public function assistantAsk(
        Request $request,
        FinancialAssistantOrchestrator $orchestrator,
        FinanceAiConversationService $conversations,
    ): RedirectResponse {
        $this->authorizeAssistant($request);
        $validated = $request->validate([
            'question' => ['required', 'string', 'max:2000'],
            'conversation_id' => ['nullable', 'integer', 'min:1'],
        ]);

        $workspace = $this->currentWorkspace();
        /** @var User $user */
        $user = $request->user();
        $locale = 'ar';
        $conversation = $conversations->resolveOrCreate($workspace, $user, $validated['conversation_id'] ?? null, $locale);
        $turn = $conversations->beginTurn($workspace, $user, $conversation, $validated['question'], $locale);
        $context = new FinanceAgentConversationContext(
            $turn->conversation,
            $conversations->priorMessages($turn),
            $turn->usageEventKey(),
        );

        try {
            $payload = $orchestrator->ask(
                $workspace,
                $user,
                $validated['question'],
                $locale,
                $context,
                $turn->usageEventKey(),
                consumeUsage: false,
            );
            $conversations->completeTurn(
                $workspace,
                $user,
                $turn,
                $payload,
                $orchestrator->lastTrace,
                $orchestrator->lastInteractionId,
            );
        } catch (Throwable $exception) {
            $conversations->failTurn($workspace, $user, $turn);
            throw $exception;
        }

        return redirect()->route('workspace.finance.assistant.index', [
            'conversation_id' => $conversation->id,
        ]);
    }

    private function authorizeAssistant(Request $request): void
    {
        $this->authorizeFinance($request, 'finance.view');
        /** @var User $user */
        $user = $request->user();
        abort_unless(
            app(FeatureAccessService::class)->hasFeature($user, $this->currentWorkspace(), 'ai'),
            403,
        );
    }

    public function search(Request $request, FinanceSearchService $search): View|JsonResponse
    {
        $this->authorizeFinance($request, 'finance.view');
        $term = trim((string) $request->input('q', ''));
        $results = $term === '' ? [] : $search->search((int) $this->currentWorkspace()->id, $term);

        if ($request->wantsJson()) {
            return response()->json(['data' => $results]);
        }

        return view('workspace.finance.search.index', [
            'term' => $term,
            'results' => $results,
        ]);
    }
}
