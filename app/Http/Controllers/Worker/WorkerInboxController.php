<?php

declare(strict_types=1);

namespace App\Http\Controllers\Worker;

use App\Enums\ConversationHandoffStatus;
use App\Http\Controllers\Controller;
use App\Http\Requests\Worker\StoreWorkerInboxMessageRequest;
use App\Models\Conversation;
use App\Models\Message;
use App\Models\User;
use App\Models\Worker\WorkerEngagement;
use App\Models\Workspace;
use App\Services\Conversation\ConversationHandoffService;
use App\Services\Conversation\ConversationPresenceService;
use App\Services\Conversation\ConversationResponderService;
use App\Services\Conversation\Exceptions\HandoffConflictException;
use App\Services\Conversation\Exceptions\InvalidHandoffTransitionException;
use App\Services\Mobile\ConversationInboxService;
use App\Services\Worker\WorkerEngagementAuthorization;
use App\Services\Worker\WorkerInboxException;
use App\Services\Worker\WorkerInboxService;
use App\Services\Workforce\WorkforceInboxAttribution;
use App\Support\Tenancy\WorkspaceContext;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\View\View;
use InvalidArgumentException;

final class WorkerInboxController extends Controller
{
    public function __construct(
        private readonly WorkerInboxService $inbox,
        private readonly WorkerEngagementAuthorization $authorization,
        private readonly ConversationInboxService $conversationInboxService,
        private readonly ConversationHandoffService $handoff,
        private readonly ConversationResponderService $responderService,
    ) {}

    public function home(Request $request): View
    {
        $worker = $request->user();

        if (! $worker instanceof User) {
            return $this->view($request, null, null, new Collection, null);
        }

        $workspace = $this->chatWorkspace($worker);

        if ($workspace === null) {
            return $this->view($request, $worker, null, new Collection, null);
        }

        try {
            $conversations = $this->inbox->conversations($worker, $workspace);
        } catch (ModelNotFoundException) {
            $conversations = new Collection;
        }

        return $this->view($request, $worker, $workspace, $conversations, null);
    }

    public function index(Request $request, Workspace $workspace): View
    {
        try {
            $conversations = $this->inbox->conversations($request->user(), $workspace);
        } catch (ModelNotFoundException) {
            $conversations = new Collection;
        }
        $activeConversation = null;
        $conversationId = $request->integer('conversation');

        if ($conversationId > 0) {
            try {
                $activeConversation = $this->inbox->conversation(
                    $request->user(),
                    $workspace,
                    $conversationId,
                );
            } catch (ModelNotFoundException) {
                $activeConversation = null;
            }
        }

        return $this->view($request, $request->user(), $workspace, $conversations, $activeConversation);
    }

    public function show(
        Request $request,
        Workspace $workspace,
        int $conversation,
    ): View {
        $conversations = $this->inbox->conversations($request->user(), $workspace);
        $activeConversation = $this->inbox->conversation(
            $request->user(),
            $workspace,
            $conversation,
        );

        return $this->view($request, $request->user(), $workspace, $conversations, $activeConversation);
    }

    public function storeMessage(
        StoreWorkerInboxMessageRequest $request,
        Workspace $workspace,
        int $conversation,
    ): RedirectResponse|JsonResponse {
        app(WorkspaceContext::class)->set($workspace);

        try {
            $sentMessage = $this->inbox->sendMessage(
                $request->user(),
                $workspace,
                $conversation,
                $request->validated(),
                $request,
            );
        } catch (WorkerInboxException $exception) {
            if ($request->expectsJson()) {
                return response()->json(['message' => $exception->getMessage()], 422);
            }

            return back()->withErrors(['message' => $exception->getMessage()]);
        }

        if ($request->expectsJson()) {
            $conversationModel = Conversation::query()
                ->withoutGlobalScopes()
                ->where('workspace_id', $workspace->id)
                ->with([
                    'assignedUser:id,name',
                    'assignedWorkerEngagement.workerProfile:id,user_id,display_name',
                    'assignedWorkerEngagement.workerProfile.user:id,name',
                    'aiEmployee:id,workspace_id,name,avatar_path,status',
                ])
                ->find($conversation);

            return response()->json([
                'message' => $this->serializeMessageForInbox($sentMessage),
                'conversation' => $conversationModel ? [
                    'id' => $conversationModel->id,
                    'ai_enabled' => (bool) $conversationModel->ai_enabled,
                    'ai_replying' => $conversationModel->isAiReplying(),
                    'handoff_status' => $conversationModel->handoff_status instanceof ConversationHandoffStatus
                        ? $conversationModel->handoff_status->value
                        : ($conversationModel->handoff_status ?? 'none'),
                    'responder' => $conversationModel->currentResponder(),
                ] : null,
            ]);
        }

        return redirect()
            ->route('worker.inbox.show', [$workspace, $conversation])
            ->with('success', 'تم إرسال الرسالة.');
    }

    private function serializeMessageForInbox(Message $message): array
    {
        $message->loadMissing([
            'attachments',
            'user:id,name',
            'workerEngagement.workerProfile:id,user_id,display_name',
            'workerEngagement.workerProfile.user:id,name',
        ]);

        $isInternalNote = $message->direction === 'internal_note'
            || $message->message_type === 'system'
            || (bool) data_get($message->metadata, 'handoff_acknowledgement');

        $participantType = WorkforceInboxAttribution::typeForMessage($message)?->value;

        $senderName = null;
        if ($participantType === 'ai_employee' || $message->ai_generated) {
            $senderName = 'AI';
        } elseif ($participantType === 'independent_worker') {
            $senderName = $message->workerEngagement?->workerProfile?->display_name
                ?: $message->workerEngagement?->workerProfile?->user?->name
                ?: $message->user?->name
                ?: 'موظف مستقل';
        } elseif ($message->user) {
            $senderName = $message->user->name;
        }

        $metadata = is_array($message->metadata) ? $message->metadata : [];

        return [
            'id' => $message->id,
            'conversation_id' => $message->conversation_id,
            'direction' => $message->direction,
            'message_type' => $message->message_type,
            'content' => $message->content,
            'ai_generated' => (bool) $message->ai_generated,
            'delivery_status' => $message->delivery_status,
            'delivery_error' => $message->delivery_status === 'failed'
                && is_string($metadata['delivery_error'] ?? null)
                ? $metadata['delivery_error']
                : null,
            'delivered_at' => optional($message->delivered_at)?->toIso8601String(),
            'read_at' => optional($message->read_at)?->toIso8601String(),
            'is_internal_note' => $isInternalNote,
            'sender_name' => $senderName,
            'created_at_time' => optional($message->created_at)?->format('h:i A'),
            'participant_type' => $participantType,
            'worker_engagement_id' => $message->worker_engagement_id,
            'client_id' => $metadata['client_id'] ?? $metadata['idempotency_key'] ?? null,
            'idempotency_key' => $metadata['idempotency_key'] ?? $metadata['client_id'] ?? null,
            'created_at' => optional($message->created_at)?->toIso8601String(),
            'attachments' => [],
        ];
    }

    /**
     * @param  Collection<int, Conversation>  $conversations
     */
    private function view(
        Request $request,
        ?User $worker,
        ?Workspace $workspace,
        Collection $conversations,
        ?Conversation $activeConversation,
    ): View {
        if ($workspace instanceof Workspace) {
            app(WorkspaceContext::class)->set($workspace);
        }

        $canSend = $workspace instanceof Workspace
            && $this->canSendMessage($worker, $workspace, $activeConversation);
        $activeConversation = $workspace instanceof Workspace
            ? $this->prepareActiveConversation($request, $workspace, $activeConversation)
            : null;

        return view('workspace.conversations.index', [
            'conversations' => $this->paginateConversations($request, $workspace, $conversations),
            'activeConversation' => $activeConversation,
            'channelFilter' => $this->normalizeChannelFilter($request->string('channel')->toString()),
            'availableChannels' => [
                'whatsapp' => 'WhatsApp',
                'instagram' => 'Instagram',
                'facebook_messenger' => 'Facebook Messenger',
                'email' => 'Email',
                'web' => 'Web',
                'manual' => 'Manual',
            ],
            'inboxIndexRoute' => $workspace instanceof Workspace ? 'worker.inbox.index' : 'worker.inbox.home',
            'inboxRouteParameters' => $workspace instanceof Workspace ? ['workspace' => $workspace->id] : [],
            'inboxAllowsCompanyActions' => false,
            'inboxAllowsWorkerHandoff' => $canSend,
            'inboxCanSend' => $canSend,
            'inboxMessageAction' => $activeConversation instanceof Conversation && $workspace instanceof Workspace
                ? route('worker.inbox.messages.store', [$workspace, $activeConversation->id])
                : null,
            'inboxPollUpdatesUrl' => $workspace instanceof Workspace
                ? route('worker.inbox.updates.poll', $workspace)
                : null,
            'inboxPollMessagesUrl' => $activeConversation instanceof Conversation && $workspace instanceof Workspace
                ? route('worker.inbox.messages.poll', [$workspace, $activeConversation->id])
                : null,
            'inboxTakeoverAction' => $activeConversation instanceof Conversation && $workspace instanceof Workspace
                ? route('worker.inbox.handoff.takeover', [$workspace, $activeConversation->id])
                : null,
            'inboxResolveHandoffAction' => $activeConversation instanceof Conversation && $workspace instanceof Workspace
                ? route('worker.inbox.handoff.resolve', [$workspace, $activeConversation->id])
                : null,
            'inboxResumeAiAction' => $activeConversation instanceof Conversation && $workspace instanceof Workspace
                ? route('worker.inbox.handoff.resume-ai', [$workspace, $activeConversation->id])
                : null,
            'inboxResponderLogsUrl' => $activeConversation instanceof Conversation && $workspace instanceof Workspace
                ? route('worker.inbox.responder-logs', [$workspace, $activeConversation->id])
                : null,
            'inboxRealtimeEnabled' => true,
        ]);
    }

    /**
     * @param  Collection<int, Conversation>  $conversations
     */
    private function paginateConversations(
        Request $request,
        ?Workspace $workspace,
        Collection $conversations,
    ): LengthAwarePaginator {
        $channelFilter = $this->normalizeChannelFilter($request->string('channel')->toString());
        $status = $request->string('status')->toString();
        $handoff = $request->string('handoff')->toString();
        $search = mb_strtolower(trim($request->string('search')->toString()));

        $actor = $request->user();
        abort_unless($actor instanceof User, 403);

        $filtered = $conversations
            ->map(function (Conversation $conversation) use ($actor): Conversation {
                $conversation->setAttribute('display_channel', $this->displayChannel($conversation));
                $conversation->setAttribute(
                    'unread_count',
                    $this->conversationInboxService->unreadCountForConversation($conversation, $actor),
                );

                return $conversation;
            })
            ->filter(function (Conversation $conversation) use ($request, $channelFilter, $status, $handoff, $search): bool {
                if ($channelFilter !== null && $conversation->display_channel !== $channelFilter) {
                    return false;
                }

                if (in_array($status, ['open', 'closed', 'archived'], true) && $conversation->status !== $status) {
                    return false;
                }

                if ($request->boolean('unread') && (int) $conversation->unread_count < 1) {
                    return false;
                }

                if (! $this->matchesHandoffFilter($conversation, $handoff, $request->user()?->id)) {
                    return false;
                }

                if ($search === '') {
                    return true;
                }

                $customerName = mb_strtolower((string) $conversation->customer?->name);
                $externalId = mb_strtolower((string) $conversation->external_id);

                return str_contains($customerName, $search) || str_contains($externalId, $search);
            })
            ->values();

        $perPage = 12;
        $page = max(1, $request->integer('page', 1));

        return new LengthAwarePaginator(
            $filtered->slice(($page - 1) * $perPage, $perPage)->values(),
            $filtered->count(),
            $perPage,
            $page,
            [
                'path' => $workspace instanceof Workspace
                    ? route('worker.inbox.index', $workspace)
                    : route('worker.inbox.home'),
                'query' => $request->except('page'),
            ],
        );
    }

    private function prepareActiveConversation(
        Request $request,
        Workspace $workspace,
        ?Conversation $activeConversation,
    ): ?Conversation {
        if (! $activeConversation instanceof Conversation) {
            return null;
        }

        $actor = $request->user();
        if ($actor instanceof User) {
            $this->conversationInboxService->markRead($activeConversation, $actor);
            $activeConversation->setAttribute(
                'unread_count',
                $this->conversationInboxService->unreadCountForConversation($activeConversation, $actor),
            );
        }

        $activeConversation->setAttribute('display_channel', $this->displayChannel($activeConversation));
        $activeConversation->setRelation(
            'messages',
            $activeConversation->messages->sortBy('created_at')->values(),
        );

        $customer = $activeConversation->customer;
        if ($customer !== null) {
            $customer->setRelation('notes', new Collection);
            $customer->setRelation('tags', new Collection);
            $customer->setRelation('orders', new Collection);
        }

        $request->session()->put('inbox.last_conversation.'.$workspace->id, $activeConversation->id);

        return $activeConversation;
    }

    private function matchesHandoffFilter(Conversation $conversation, string $handoff, ?int $userId): bool
    {
        $status = $conversation->handoff_status instanceof ConversationHandoffStatus
            ? $conversation->handoff_status->value
            : (string) ($conversation->handoff_status ?? 'none');

        return match ($handoff) {
            'ai' => $conversation->isAiReplying(),
            'handoff' => in_array($status, ['requested', 'queued', 'assigned', 'active'], true),
            'queued' => $status === 'queued',
            'assigned_me' => $userId !== null
                && (int) $conversation->assigned_user_id === $userId
                && in_array($status, ['assigned', 'active'], true),
            'active' => $status === 'active',
            'resolved' => $status === 'resolved',
            default => true,
        };
    }

    private function displayChannel(Conversation $conversation): string
    {
        $metadata = is_array($conversation->metadata) ? $conversation->metadata : [];
        $channel = $metadata['channel_source'] ?? $conversation->channel ?? 'manual';
        $normalized = strtolower(trim((string) $channel));

        return match ($normalized) {
            'facebook', 'messenger', 'facebook-messenger' => 'facebook_messenger',
            'ig' => 'instagram',
            default => $normalized !== '' ? $normalized : 'manual',
        };
    }

    private function normalizeChannelFilter(string $channel): ?string
    {
        $normalized = strtolower(trim($channel));

        if ($normalized === '') {
            return null;
        }

        return match ($normalized) {
            'facebook', 'messenger', 'facebook-messenger' => 'facebook_messenger',
            'ig' => 'instagram',
            default => $normalized,
        };
    }

    private function chatWorkspace(?User $worker): ?Workspace
    {
        if (! $worker instanceof User) {
            return null;
        }

        $workspaceId = WorkerEngagement::withoutGlobalScopes()
            ->whereHas('workerProfile', function (Builder $query) use ($worker): void {
                $query->where('user_id', $worker->id);
            })
            ->latest('id')
            ->value('workspace_id');

        if ($workspaceId === null) {
            return null;
        }

        return Workspace::query()->find($workspaceId);
    }

    private function canSendMessage(
        ?User $worker,
        Workspace $workspace,
        ?Conversation $conversation,
    ): bool {
        if (! $worker instanceof User || $conversation === null) {
            return false;
        }

        return $this->authorization->workerCanInWorkspace(
            $worker,
            $workspace,
            WorkerInboxService::SEND_CAPABILITY,
        );
    }

    public function typing(Request $request, Workspace $workspace, Conversation $conversation): JsonResponse
    {
        $user = $request->user();
        abort_unless($user !== null, 403);
        $isTyping = $request->boolean('is_typing', true);

        $workerProfile = $user->workerProfile;
        $senderName = $workerProfile?->display_name ?: $user->name ?: 'موظف مستقل';

        $res = app(ConversationPresenceService::class)->setTyping(
            $conversation,
            $user,
            $isTyping,
            senderType: 'worker',
            senderName: $senderName,
        );

        return response()->json([
            'status' => 'ok',
            'is_typing' => $isTyping,
            'typing_users' => $res['typing_users'] ?? [],
            'typing_summary' => $res['typing_summary'] ?? '',
        ]);
    }

    public function presence(Request $request, Workspace $workspace, Conversation $conversation): JsonResponse
    {
        $state = app(ConversationPresenceService::class)->getConversationState($conversation);

        return response()->json([
            'status' => 'ok',
            'data' => $state,
        ]);
    }

    public function presencePing(Request $request, Workspace $workspace, Conversation $conversation): JsonResponse
    {
        $user = $request->user();
        abort_unless($user !== null, 401);

        $stateInput = $request->string('state', ConversationPresenceService::STATE_VIEWING)->toString();
        $state = app(ConversationPresenceService::class)->ping($conversation, $user, $stateInput);

        return response()->json([
            'status' => 'ok',
            'data' => $state,
        ]);
    }

    public function presenceLeave(Request $request, Workspace $workspace, Conversation $conversation): JsonResponse
    {
        $user = $request->user();
        if ($user !== null) {
            app(ConversationPresenceService::class)->leave($conversation, $user);
        }

        return response()->json([
            'status' => 'ok',
        ]);
    }

    public function takeover(
        Request $request,
        Workspace $workspace,
        int $conversation,
    ): RedirectResponse|JsonResponse {
        return $this->runWorkerHandoffAction(
            $request,
            $workspace,
            $conversation,
            fn (Conversation $conv, User $actor, ?int $expectedVersion): Conversation => $this->handoff->takeover($conv, $actor, $expectedVersion),
            'أنت تتولى المحادثة الآن.',
        );
    }

    public function resolve(
        Request $request,
        Workspace $workspace,
        int $conversation,
    ): RedirectResponse|JsonResponse {
        return $this->runWorkerHandoffAction(
            $request,
            $workspace,
            $conversation,
            fn (Conversation $conv, User $actor, ?int $expectedVersion): Conversation => $this->handoff->resolve($conv, $actor, $expectedVersion),
            'تم إنهاء التحويل.',
        );
    }

    public function resumeAi(
        Request $request,
        Workspace $workspace,
        int $conversation,
    ): RedirectResponse|JsonResponse {
        return $this->runWorkerHandoffAction(
            $request,
            $workspace,
            $conversation,
            fn (Conversation $conv, User $actor, ?int $expectedVersion): Conversation => $this->handoff->resumeAi($conv, $actor, $expectedVersion),
            'تم تحويل المحادثة إلى الذكاء الاصطناعي.',
        );
    }

    public function responderLogs(
        Request $request,
        Workspace $workspace,
        int $conversation,
    ): JsonResponse {
        $worker = $request->user();
        abort_unless($worker instanceof User, 403);

        app(WorkspaceContext::class)->set($workspace);
        [$resolved] = $this->inbox->resolveConversationForWorker($worker, $workspace, $conversation);
        $logs = $this->responderService->getLogs($resolved);

        return response()->json([
            'conversation_id' => $resolved->id,
            'current_responder' => $resolved->currentResponder(),
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

    public function pollMessages(
        Request $request,
        Workspace $workspace,
        int $conversation,
    ): JsonResponse {
        $worker = $request->user();
        abort_unless($worker instanceof User, 403);

        app(WorkspaceContext::class)->set($workspace);
        [$resolved] = $this->inbox->resolveConversationForWorker($worker, $workspace, $conversation);

        $afterId = $request->integer('after_id', 0);
        $messages = Message::query()
            ->where('conversation_id', $resolved->id)
            ->when($afterId > 0, fn ($query) => $query->where('id', '>', $afterId))
            ->with([
                'user:id,name',
                'workerEngagement.workerProfile:id,display_name',
                'attachments',
            ])
            ->orderBy('id', 'asc')
            ->limit(50)
            ->get();

        $pendingIds = array_values(array_filter(array_map(
            'intval',
            explode(',', (string) $request->query('pending_ids', '')),
        )));
        $statusUpdates = $this->conversationInboxService->recentOutboundStatusUpdates(
            $resolved,
            $afterId,
            $pendingIds,
        );
        $unreadCount = $this->conversationInboxService->unreadCountForConversation($resolved, $worker);
        $latestMessage = $resolved->messages()->latest('id')->first();

        return response()->json([
            'messages' => $messages->map(fn (Message $m): array => $this->serializeMessageForInbox($m))->values(),
            'status_updates' => $statusUpdates,
            'conversation' => [
                'id' => $resolved->id,
                'status' => (string) $resolved->status,
                'ai_enabled' => (bool) $resolved->ai_enabled,
                'ai_replying' => $resolved->isAiReplying(),
                'handoff_status' => $resolved->handoff_status instanceof ConversationHandoffStatus
                    ? $resolved->handoff_status->value
                    : ($resolved->handoff_status ?? 'none'),
                'handoff_version' => (int) ($resolved->handoff_version ?? 1),
                'assigned_user_id' => $resolved->assigned_user_id,
                'assigned_user_name' => $resolved->assignedUser?->name,
                'assigned_worker_engagement_id' => $resolved->assigned_worker_engagement_id,
                'last_message' => $latestMessage?->content ?? '',
                'last_message_at' => $resolved->last_message_at?->format('h:i A') ?? '',
                'unread_count' => $unreadCount,
                'responder' => $resolved->currentResponder(),
            ],
        ]);
    }

    public function pollUpdates(Request $request, Workspace $workspace): JsonResponse
    {
        $worker = $request->user();
        abort_unless($worker instanceof User, 403);

        app(WorkspaceContext::class)->set($workspace);
        $recent = $this->inbox->conversations($worker, $workspace)->take(20);

        $items = $recent->map(function (Conversation $conv) use ($worker): array {
            $latestMsg = $conv->messages->first();

            return [
                'id' => $conv->id,
                'customer_name' => $conv->customer?->name ?? 'عميل غير معروف',
                'channel' => $this->displayChannel($conv),
                'last_message' => $latestMsg?->content ?? '',
                'last_message_at' => $conv->last_message_at?->format('h:i A') ?? '',
                'unread_count' => $this->conversationInboxService->unreadCountForConversation($conv, $worker),
                'ai_enabled' => (bool) $conv->ai_enabled,
                'is_ai_replying' => $conv->isAiReplying(),
                'ai_replying' => $conv->isAiReplying(),
                'handoff_status' => $conv->handoff_status instanceof ConversationHandoffStatus
                    ? $conv->handoff_status->value
                    : ($conv->handoff_status ?? 'none'),
                'handoff_version' => (int) ($conv->handoff_version ?? 1),
                'assigned_user_id' => $conv->assigned_user_id,
                'assigned_user_name' => $conv->assignedUser?->name,
                'assigned_worker_engagement_id' => $conv->assigned_worker_engagement_id,
                'status' => $conv->status,
                'responder' => $conv->currentResponder(),
            ];
        })->values();

        return response()->json([
            'conversations' => $items,
        ]);
    }

    /**
     * @param  callable(Conversation, User, ?int): Conversation  $callback
     */
    private function runWorkerHandoffAction(
        Request $request,
        Workspace $workspace,
        int $conversation,
        callable $callback,
        string $successMessage,
    ): RedirectResponse|JsonResponse {
        $worker = $request->user();
        abort_unless($worker instanceof User, 403);

        app(WorkspaceContext::class)->set($workspace);
        [$resolved] = $this->inbox->resolveConversationForWorker(
            $worker,
            $workspace,
            $conversation,
            [WorkerInboxService::SEND_CAPABILITY],
        );

        $expectedVersion = $request->filled('expected_handoff_version')
            ? $request->integer('expected_handoff_version')
            : null;

        try {
            $fresh = $callback($resolved, $worker, $expectedVersion);
        } catch (ModelNotFoundException) {
            abort(404);
        } catch (AuthorizationException $exception) {
            if ($request->expectsJson()) {
                return response()->json(['success' => false, 'message' => $exception->getMessage() ?: 'لا تملك صلاحية تنفيذ هذا الإجراء.'], 403);
            }
            abort(403);
        } catch (HandoffConflictException $exception) {
            if ($request->expectsJson()) {
                return response()->json([
                    'success' => false,
                    'message' => $exception->getMessage(),
                    'handoff_version' => $exception->currentHandoffVersion,
                ], 409);
            }

            return back()->with('error', $exception->getMessage());
        } catch (InvalidHandoffTransitionException|InvalidArgumentException|WorkerInboxException $exception) {
            if ($request->expectsJson()) {
                return response()->json(['success' => false, 'message' => $exception->getMessage()], 422);
            }

            return back()->with('error', $exception->getMessage());
        }

        if ($request->expectsJson()) {
            $fresh->loadMissing(['assignedUser:id,name']);
            $responder = $fresh->currentResponder();

            return response()->json([
                'success' => true,
                'message' => $successMessage,
                'conversation' => [
                    'id' => $fresh->id,
                    'status' => (string) $fresh->status,
                    'ai_enabled' => (bool) $fresh->ai_enabled,
                    'ai_replying' => $fresh->isAiReplying(),
                    'handoff_status' => $fresh->handoff_status instanceof ConversationHandoffStatus
                        ? $fresh->handoff_status->value
                        : ($fresh->handoff_status ?? 'none'),
                    'handoff_version' => (int) ($fresh->handoff_version ?? 1),
                    'assigned_user_id' => $fresh->assigned_user_id,
                    'assigned_user_name' => $fresh->assignedUser?->name,
                    'assigned_worker_engagement_id' => $fresh->assigned_worker_engagement_id,
                    'responder' => $responder,
                    'active_responder' => $responder,
                    'last_message_sender' => $fresh->lastMessageSender(),
                ],
            ]);
        }

        return redirect()
            ->route('worker.inbox.show', [$workspace, $conversation])
            ->with('success', $successMessage);
    }
}
