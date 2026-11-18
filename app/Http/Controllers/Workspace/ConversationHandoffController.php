<?php

namespace App\Http\Controllers\Workspace;

use App\Enums\ConversationHandoffReason;
use App\Http\Controllers\Controller;
use App\Http\Controllers\Workspace\Concerns\InteractsWithWorkspace;
use App\Http\Requests\Conversation\AssignConversationRequest;
use App\Http\Requests\Conversation\RequestConversationHandoffRequest;
use App\Models\Conversation;
use App\Models\User;
use App\Models\Worker\WorkerEngagement;
use App\Services\Conversation\ConversationHandoffService;
use App\Services\Conversation\ConversationResponderService;
use App\Services\Conversation\Exceptions\InvalidHandoffTransitionException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use InvalidArgumentException;

class ConversationHandoffController extends Controller
{
    use InteractsWithWorkspace;

    public function __construct(
        private readonly ConversationHandoffService $handoff,
        private readonly ConversationResponderService $responderService,
    ) {}

    public function requestHandoff(
        RequestConversationHandoffRequest $request,
        Conversation $conversation,
    ): RedirectResponse|JsonResponse {
        $validated = $request->validated();

        try {
            $fresh = $this->handoff->request(
                $conversation,
                ConversationHandoffReason::from($validated['reason']),
                [
                    'requested_by' => 'staff',
                    'source' => 'inbox',
                ],
                queueImmediately: true,
            );
        } catch (InvalidHandoffTransitionException|InvalidArgumentException $exception) {
            if ($request->expectsJson()) {
                return response()->json(['success' => false, 'message' => $exception->getMessage()], 422);
            }

            return back()->with('error', $exception->getMessage());
        }

        if ($request->expectsJson()) {
            return response()->json([
                'success' => true,
                'message' => 'تم طلب تحويل المحادثة.',
                'conversation' => [
                    'id' => $fresh->id,
                    'responder' => $fresh->currentResponder(),
                ],
            ]);
        }

        return back()->with('success', 'تم طلب تحويل المحادثة.');
    }

    public function assign(AssignConversationRequest $request, Conversation $conversation): RedirectResponse|JsonResponse
    {
        $validated = $request->validated();

        try {
            if (isset($validated['worker_engagement_id'])) {
                $engagement = WorkerEngagement::query()
                    ->with('workerProfile.user')
                    ->findOrFail((int) $validated['worker_engagement_id']);
                $worker = $engagement->workerProfile?->user;

                if ($worker === null) {
                    throw new InvalidArgumentException('Worker engagement has no valid worker.');
                }

                $fresh = $this->handoff->assign(
                    $conversation,
                    $worker,
                    $engagement,
                    $request->user(),
                );
            } else {
                $assignee = User::query()->findOrFail((int) $validated['user_id']);
                $fresh = $this->handoff->assign(
                    $conversation,
                    $assignee,
                    actor: $request->user(),
                );
            }
        } catch (InvalidHandoffTransitionException|InvalidArgumentException $exception) {
            if ($request->expectsJson()) {
                return response()->json(['success' => false, 'message' => $exception->getMessage()], 422);
            }

            return back()->with('error', $exception->getMessage());
        }

        if ($request->expectsJson()) {
            return response()->json([
                'success' => true,
                'message' => 'تم تعيين المحادثة.',
                'conversation' => [
                    'id' => $fresh->id,
                    'responder' => $fresh->currentResponder(),
                ],
            ]);
        }

        return back()->with('success', 'تم تعيين المحادثة.');
    }

    public function takeover(Request $request, Conversation $conversation): RedirectResponse|JsonResponse
    {
        $this->authorize('manageHandoff', $conversation);

        try {
            $fresh = $this->handoff->takeover($conversation, $request->user());
        } catch (InvalidHandoffTransitionException|InvalidArgumentException $exception) {
            if ($request->expectsJson()) {
                return response()->json(['success' => false, 'message' => $exception->getMessage()], 422);
            }

            return back()->with('error', $exception->getMessage());
        }

        if ($request->expectsJson()) {
            return response()->json([
                'success' => true,
                'message' => 'أنت تتولى المحادثة الآن.',
                'conversation' => [
                    'id' => $fresh->id,
                    'responder' => $fresh->currentResponder(),
                ],
            ]);
        }

        return back()->with('success', 'أنت تتولى المحادثة الآن.');
    }

    public function resolve(Request $request, Conversation $conversation): RedirectResponse|JsonResponse
    {
        $this->authorize('manageHandoff', $conversation);

        try {
            $fresh = $this->handoff->resolve($conversation, $request->user());
        } catch (InvalidHandoffTransitionException|InvalidArgumentException $exception) {
            if ($request->expectsJson()) {
                return response()->json(['success' => false, 'message' => $exception->getMessage()], 422);
            }

            return back()->with('error', $exception->getMessage());
        }

        if ($request->expectsJson()) {
            return response()->json([
                'success' => true,
                'message' => 'تم إنهاء التحويل.',
                'conversation' => [
                    'id' => $fresh->id,
                    'responder' => $fresh->currentResponder(),
                ],
            ]);
        }

        return back()->with('success', 'تم إنهاء التحويل.');
    }

    public function resumeAi(Request $request, Conversation $conversation): RedirectResponse|JsonResponse
    {
        $this->authorize('resumeAi', $conversation);

        try {
            if ($conversation->handoffStatusEnum()->blocksAi()) {
                $this->handoff->resolve($conversation, $request->user());
                $conversation = $conversation->fresh() ?? $conversation;
            }
            $fresh = $this->handoff->resumeAi($conversation, $request->user());
        } catch (InvalidHandoffTransitionException|InvalidArgumentException $exception) {
            if ($request->expectsJson()) {
                return response()->json(['success' => false, 'message' => $exception->getMessage()], 422);
            }

            return back()->with('error', $exception->getMessage());
        }

        if ($request->expectsJson()) {
            return response()->json([
                'success' => true,
                'message' => 'تم تفعيل الذكاء الاصطناعي.',
                'conversation' => [
                    'id' => $fresh->id,
                    'responder' => $fresh->currentResponder(),
                ],
            ]);
        }

        return back()->with('success', 'تم تفعيل الذكاء الاصطناعي.');
    }

    /**
     * Get the responder audit log history for a conversation.
     */
    public function responderLogs(Request $request, Conversation $conversation): JsonResponse
    {
        $this->authorize('view', $conversation);

        $logs = $this->responderService->getLogs($conversation);

        return response()->json([
            'conversation_id' => $conversation->id,
            'current_responder' => $conversation->currentResponder(),
            'logs' => $logs->map(fn ($log) => [
                'id' => $log->id,
                'action' => $log->action,
                'action_label' => $log->actionLabel(),
                'previous_type' => $log->previous_responder_type,
                'previous_name' => $log->previous_responder_name,
                'new_type' => $log->new_responder_type,
                'new_name' => $log->new_responder_name,
                'actor_type' => $log->actor_type,
                'actor_name' => $log->actor?->name ?? ($log->actor_type === 'ai' ? 'مشعل AI' : 'النظام'),
                'reason' => $log->reason,
                'created_at' => $log->created_at?->format('Y-m-d h:i A'),
                'created_at_human' => $log->created_at?->diffForHumans(),
            ])->values(),
        ]);
    }
}
