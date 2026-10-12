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
use App\Services\Conversation\Exceptions\InvalidHandoffTransitionException;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use InvalidArgumentException;

class ConversationHandoffController extends Controller
{
    use InteractsWithWorkspace;

    public function __construct(
        private readonly ConversationHandoffService $handoff,
    ) {}

    public function requestHandoff(
        RequestConversationHandoffRequest $request,
        Conversation $conversation,
    ): RedirectResponse {
        $validated = $request->validated();

        try {
            $this->handoff->request(
                $conversation,
                ConversationHandoffReason::from($validated['reason']),
                [
                    'requested_by' => 'staff',
                    'source' => 'inbox',
                ],
                queueImmediately: true,
            );
        } catch (InvalidHandoffTransitionException|InvalidArgumentException $exception) {
            return back()->with('error', $exception->getMessage());
        }

        return back()->with('success', 'تم طلب تحويل المحادثة.');
    }

    public function assign(AssignConversationRequest $request, Conversation $conversation): RedirectResponse
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

                $this->handoff->assign(
                    $conversation,
                    $worker,
                    $engagement,
                    $request->user(),
                );
            } else {
                $assignee = User::query()->findOrFail((int) $validated['user_id']);
                $this->handoff->assign(
                    $conversation,
                    $assignee,
                    actor: $request->user(),
                );
            }
        } catch (InvalidHandoffTransitionException|InvalidArgumentException $exception) {
            return back()->with('error', $exception->getMessage());
        }

        return back()->with('success', 'تم تعيين المحادثة.');
    }

    public function takeover(Request $request, Conversation $conversation): RedirectResponse
    {
        $this->authorize('manageHandoff', $conversation);

        try {
            $this->handoff->takeover($conversation, $request->user());
        } catch (InvalidHandoffTransitionException|InvalidArgumentException $exception) {
            return back()->with('error', $exception->getMessage());
        }

        return back()->with('success', 'أنت تتولى المحادثة الآن.');
    }

    public function resolve(Request $request, Conversation $conversation): RedirectResponse
    {
        $this->authorize('manageHandoff', $conversation);

        try {
            $this->handoff->resolve($conversation, $request->user());
        } catch (InvalidHandoffTransitionException|InvalidArgumentException $exception) {
            return back()->with('error', $exception->getMessage());
        }

        return back()->with('success', 'تم إنهاء التحويل.');
    }

    public function resumeAi(Request $request, Conversation $conversation): RedirectResponse
    {
        $this->authorize('resumeAi', $conversation);

        try {
            $this->handoff->resumeAi($conversation, $request->user());
        } catch (InvalidHandoffTransitionException|InvalidArgumentException $exception) {
            return back()->with('error', $exception->getMessage());
        }

        return back()->with('success', 'تم تفعيل الذكاء الاصطناعي.');
    }
}
