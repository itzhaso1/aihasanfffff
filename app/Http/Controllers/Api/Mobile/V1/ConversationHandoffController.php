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
use App\Services\Conversation\Exceptions\InvalidHandoffTransitionException;
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
        $this->authorize('manageHandoff', $conversation);

        try {
            $updated = $this->handoff->takeover($conversation, $request->user());
        } catch (InvalidHandoffTransitionException|InvalidArgumentException $exception) {
            return $this->fail($exception->getMessage(), 422);
        }

        return $this->ok(new ConversationResource($updated->load(['customer', 'assignedUser'])), message: 'تم تولي المحادثة.');
    }

    public function resolve(Request $request, Conversation $conversation): JsonResponse
    {
        $this->authorize('manageHandoff', $conversation);

        try {
            $updated = $this->handoff->resolve($conversation, $request->user());
        } catch (InvalidHandoffTransitionException|InvalidArgumentException $exception) {
            return $this->fail($exception->getMessage(), 422);
        }

        return $this->ok(new ConversationResource($updated->load(['customer', 'assignedUser'])), message: 'تم إنهاء التحويل.');
    }

    public function resumeAi(Request $request, Conversation $conversation): JsonResponse
    {
        $this->authorize('resumeAi', $conversation);

        try {
            $updated = $this->handoff->resumeAi($conversation, $request->user());
        } catch (InvalidHandoffTransitionException|InvalidArgumentException $exception) {
            return $this->fail($exception->getMessage(), 422);
        }

        return $this->ok(new ConversationResource($updated->load(['customer', 'assignedUser'])), message: 'تم تفعيل الذكاء الاصطناعي.');
    }
}
