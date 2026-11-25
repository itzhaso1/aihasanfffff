<?php

namespace App\Http\Controllers\Workspace;

use App\Enums\ConversationHandoffReason;
use App\Enums\ConversationHandoffStatus;
use App\Http\Controllers\Controller;
use App\Http\Controllers\Workspace\Concerns\InteractsWithWorkspace;
use App\Http\Requests\Conversation\AssignConversationRequest;
use App\Http\Requests\Conversation\RequestConversationHandoffRequest;
use App\Models\Conversation;
use App\Models\User;
use App\Models\Worker\WorkerEngagement;
use App\Services\Conversation\ConversationHandoffService;
use App\Services\Conversation\ConversationResponderService;
use App\Services\Conversation\Exceptions\HandoffConflictException;
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

        $expectedVersion = $request->filled('expected_handoff_version')
            ? $request->integer('expected_handoff_version')
            : null;

        try {
            $fresh = $this->handoff->takeover($conversation, $request->user(), $expectedVersion);
        } catch (HandoffConflictException $exception) {
            if ($request->expectsJson()) {
                return response()->json([
                    'success' => false,
                    'message' => $exception->getMessage(),
                    'handoff_version' => $exception->currentHandoffVersion,
                ], 409);
            }

            return back()->with('error', $exception->getMessage());
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
                'conversation' => $this->conversationPayload($fresh),
            ]);
        }

        return back()->with('success', 'أنت تتولى المحادثة الآن.');
    }

    public function resolve(Request $request, Conversation $conversation): RedirectResponse|JsonResponse
    {
        $this->authorize('manageHandoff', $conversation);

        $expectedVersion = $request->filled('expected_handoff_version')
            ? $request->integer('expected_handoff_version')
            : null;

        try {
            $fresh = $this->handoff->resolve($conversation, $request->user(), $expectedVersion);
        } catch (HandoffConflictException $exception) {
            if ($request->expectsJson()) {
                return response()->json([
                    'success' => false,
                    'message' => $exception->getMessage(),
                    'handoff_version' => $exception->currentHandoffVersion,
                ], 409);
            }

            return back()->with('error', $exception->getMessage());
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
                'conversation' => $this->conversationPayload($fresh),
            ]);
        }

        return back()->with('success', 'تم إنهاء التحويل.');
    }

    public function resumeAi(Request $request, Conversation $conversation): RedirectResponse|JsonResponse
    {
        $this->authorize('resumeAi', $conversation);

        $expectedVersion = $request->filled('expected_handoff_version')
            ? $request->integer('expected_handoff_version')
            : null;

        try {
            $fresh = $this->handoff->resumeAi($conversation, $request->user(), $expectedVersion);
        } catch (HandoffConflictException $exception) {
            if ($request->expectsJson()) {
                return response()->json([
                    'success' => false,
                    'message' => $exception->getMessage(),
                    'handoff_version' => $exception->currentHandoffVersion,
                ], 409);
            }

            return back()->with('error', $exception->getMessage());
        } catch (InvalidHandoffTransitionException|InvalidArgumentException $exception) {
            if ($request->expectsJson()) {
                return response()->json(['success' => false, 'message' => $exception->getMessage()], 422);
            }

            return back()->with('error', $exception->getMessage());
        }

        if ($request->expectsJson()) {
            return response()->json([
                'success' => true,
                'message' => 'تم تحويل المحادثة إلى الذكاء الاصطناعي.',
                'conversation' => $this->conversationPayload($fresh),
            ]);
        }

        return back()->with('success', 'تم تفعيل الذكاء الاصطناعي.');
    }

    /**
     * @return array<string, mixed>
     */
    private function conversationPayload(Conversation $conversation): array
    {
        $conversation->loadMissing(['assignedUser:id,name']);
        $responder = $conversation->currentResponder();

        return [
            'id' => $conversation->id,
            'status' => (string) $conversation->status,
            'ai_enabled' => (bool) $conversation->ai_enabled,
            'ai_replying' => $conversation->isAiReplying(),
            'handoff_status' => $conversation->handoff_status instanceof ConversationHandoffStatus
                ? $conversation->handoff_status->value
                : (string) ($conversation->handoff_status ?? 'none'),
            'handoff_version' => (int) ($conversation->handoff_version ?? 1),
            'assigned_user_id' => $conversation->assigned_user_id,
            'assigned_user_name' => $conversation->assignedUser?->name,
            'assigned_worker_engagement_id' => $conversation->assigned_worker_engagement_id,
            'responder' => $responder,
            'active_responder' => $responder,
            'last_message_sender' => $conversation->lastMessageSender(),
        ];
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
