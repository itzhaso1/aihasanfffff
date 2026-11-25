<?php

namespace App\Http\Controllers\Api\Mobile\V1;

use App\Enums\ConversationHandoffReason;
use App\Http\Controllers\Api\Mobile\MobileController;
use App\Http\Requests\Conversation\AssignConversationRequest;
use App\Http\Resources\Mobile\ConversationResource;
use App\Models\Conversation;
use App\Models\User;
use App\Models\Worker\WorkerEngagement;
use App\Services\Conversation\ConversationHandoffService;
use App\Services\Conversation\Exceptions\HandoffConflictException;
use App\Services\Conversation\Exceptions\InvalidHandoffTransitionException;
use App\Services\Mobile\MobileInboxActor;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use InvalidArgumentException;

class ConversationHandoffController extends MobileController
{
    public function __construct(
        private readonly ConversationHandoffService $handoff,
    ) {}

    public function requestHandoff(Request $request, Conversation $conversation): JsonResponse
    {
        $this->authorize('manageHandoff', $conversation);

        $validated = $request->validate([
            'reason' => ['nullable', 'string', Rule::enum(ConversationHandoffReason::class)],
        ]);

        $reason = isset($validated['reason'])
            ? ConversationHandoffReason::from($validated['reason'])
            : ConversationHandoffReason::AgentRequested;

        try {
            $updated = $this->handoff->request(
                $conversation,
                $reason,
                ['requested_by' => 'staff', 'source' => 'mobile'],
                queueImmediately: true,
            );
        } catch (InvalidHandoffTransitionException|InvalidArgumentException $exception) {
            return $this->fail($exception->getMessage(), 422);
        }

        return $this->ok(new ConversationResource($updated->load(['customer', 'assignedUser'])), message: 'تم طلب التحويل.');
    }

    public function assign(
        AssignConversationRequest $request,
        Conversation $conversation,
    ): JsonResponse {
        if (! app(MobileInboxActor::class)->isMember($request->user(), $conversation->workspace)) {
            return $this->fail('لا تملك صلاحية تنفيذ هذا الإجراء.', 403);
        }

        try {
            $validated = $request->validated();
            if (isset($validated['worker_engagement_id'])) {
                $engagement = WorkerEngagement::query()
                    ->with('workerProfile.user')
                    ->findOrFail((int) $validated['worker_engagement_id']);
                $assignee = $engagement->workerProfile?->user;

                if (! $assignee instanceof User) {
                    throw new InvalidArgumentException('Worker engagement has no valid worker.');
                }

                $updated = $this->handoff->assign(
                    $conversation,
                    $assignee,
                    $engagement,
                    $request->user(),
                );
            } else {
                $assignee = User::query()->findOrFail((int) $validated['user_id']);
                $updated = $this->handoff->assign(
                    $conversation,
                    $assignee,
                    actor: $request->user(),
                );
            }
        } catch (InvalidHandoffTransitionException|InvalidArgumentException $exception) {
            return $this->fail($exception->getMessage(), 422);
        }

        return $this->ok(new ConversationResource($updated->load(['customer', 'assignedUser'])), message: 'تم التعيين.');
    }

    public function takeover(Request $request, Conversation $conversation): JsonResponse
    {
        $user = $request->user();
        if (! $user instanceof User) {
            return $this->fail('غير مصرح.', 401);
        }

        if ($guardResponse = $this->guardActiveConversationAbility($conversation, 'manageHandoff')) {
            return $guardResponse;
        }

        $expectedVersion = $request->filled('expected_handoff_version')
            ? $request->integer('expected_handoff_version')
            : null;

        try {
            $updated = $this->handoff->takeover($conversation, $user, $expectedVersion);
        } catch (ModelNotFoundException) {
            return $this->fail('العنصر المطلوب غير موجود.', 404);
        } catch (AuthorizationException $exception) {
            return $this->fail($exception->getMessage() ?: 'لا تملك صلاحية تنفيذ هذا الإجراء.', 403);
        } catch (HandoffConflictException $exception) {
            return $this->conflictResponse($exception);
        } catch (InvalidHandoffTransitionException|InvalidArgumentException $exception) {
            return $this->fail($exception->getMessage(), 422);
        }

        return $this->ok(new ConversationResource($updated->load(['customer', 'assignedUser'])), message: 'تم تولي المحادثة.');
    }

    public function resolve(Request $request, Conversation $conversation): JsonResponse
    {
        $user = $request->user();
        if (! $user instanceof User) {
            return $this->fail('غير مصرح.', 401);
        }

        if ($guardResponse = $this->guardActiveConversationAbility($conversation, 'manageHandoff')) {
            return $guardResponse;
        }

        $expectedVersion = $request->filled('expected_handoff_version')
            ? $request->integer('expected_handoff_version')
            : null;

        try {
            $updated = $this->handoff->resolve($conversation, $user, $expectedVersion);
        } catch (ModelNotFoundException) {
            return $this->fail('العنصر المطلوب غير موجود.', 404);
        } catch (AuthorizationException $exception) {
            return $this->fail($exception->getMessage() ?: 'لا تملك صلاحية تنفيذ هذا الإجراء.', 403);
        } catch (HandoffConflictException $exception) {
            return $this->conflictResponse($exception);
        } catch (InvalidHandoffTransitionException|InvalidArgumentException $exception) {
            return $this->fail($exception->getMessage(), 422);
        }

        return $this->ok(new ConversationResource($updated->load(['customer', 'assignedUser'])), message: 'تم إنهاء التحويل.');
    }

    public function resumeAi(Request $request, Conversation $conversation): JsonResponse
    {
        $user = $request->user();
        if (! $user instanceof User) {
            return $this->fail('غير مصرح.', 401);
        }

        if ($guardResponse = $this->guardActiveConversationAbility($conversation, 'resumeAi')) {
            return $guardResponse;
        }

        $expectedVersion = $request->filled('expected_handoff_version')
            ? $request->integer('expected_handoff_version')
            : null;

        try {
            $updated = $this->handoff->resumeAi($conversation, $user, $expectedVersion);
        } catch (ModelNotFoundException) {
            return $this->fail('العنصر المطلوب غير موجود.', 404);
        } catch (AuthorizationException $exception) {
            return $this->fail($exception->getMessage() ?: 'لا تملك صلاحية تنفيذ هذا الإجراء.', 403);
        } catch (HandoffConflictException $exception) {
            return $this->conflictResponse($exception);
        } catch (InvalidHandoffTransitionException|InvalidArgumentException $exception) {
            return $this->fail($exception->getMessage(), 422);
        }

        return $this->ok(new ConversationResource($updated->load(['customer', 'assignedUser'])), message: 'تم تحويل المحادثة إلى الذكاء الاصطناعي.');
    }

    private function guardActiveConversationAbility(Conversation $conversation, string $ability): ?JsonResponse
    {
        $this->authorize('view', $conversation);

        if ((string) $conversation->status === 'archived') {
            return $this->fail('العنصر المطلوب غير موجود.', 404);
        }

        $this->authorize($ability, $conversation);

        return null;
    }

    private function conflictResponse(HandoffConflictException $exception): JsonResponse
    {
        return response()->json([
            'success' => false,
            'message' => $exception->getMessage(),
            'data' => [
                'handoff_version' => $exception->currentHandoffVersion,
                'assigned_user_id' => $exception->assignedUserId,
                'assigned_user_name' => $exception->assignedUserName,
            ],
        ], 409);
    }
}
