<?php

namespace App\Http\Controllers\Workspace;

use App\Enums\ConversationHandoffStatus;
use App\Http\Controllers\Controller;
use App\Http\Controllers\Workspace\Concerns\InteractsWithWorkspace;
use App\Http\Requests\Conversation\StoreConversationRequest;
use App\Http\Requests\Conversation\UpdateConversationRequest;
use App\Http\Requests\Message\StoreMessageRequest;
use App\Jobs\ProcessAIResponse;
use App\Models\Conversation;
use App\Models\Customer;
use App\Models\Message;
use App\Models\MessageAttachment;
use App\Services\Channel\Outbound\ChannelOutboundService;
use App\Services\Conversation\ConversationAiEligibilityService;
use App\Services\Conversation\ConversationPresenceService;
use App\Services\Conversation\ConversationResponderService;
use App\Services\Conversation\ConversationService;
use App\Services\Conversation\Exceptions\InvalidHandoffTransitionException;
use App\Services\Inbox\InboxHumanHandoffService;
use App\Services\Inbox\InboxRealtimePublisher;
use App\Services\Mobile\ConversationInboxService;
use App\Services\Mobile\MessageAttachmentService;
use App\Services\Workforce\WorkforceInboxAttribution;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\View\View;
use InvalidArgumentException;

class ConversationController extends Controller
{
    use InteractsWithWorkspace;

    public function __construct(
        private readonly ConversationService $conversationService,
        private readonly ConversationAiEligibilityService $aiEligibility,
        private readonly ConversationInboxService $conversationInboxService,
    ) {}

    public function index(Request $request): View
    {
        $this->authorize('viewAny', Conversation::class);

        $search = $request->string('search')->toString();
        $channelFilter = $this->normalizeChannelFilter($request->string('channel')->toString());

        $handoffFilter = $request->string('handoff')->toString();
        $user = $request->user();
        abort_unless($user !== null, 403);

        $conversations = Conversation::query()
            ->select('conversations.*')
            ->with([
                'customer',
                'assignedUser:id,name',
                'assignedWorkerEngagement:id,workspace_id,worker_profile_id,status',
                'assignedWorkerEngagement.workerProfile:id,user_id,display_name',
                'assignedWorkerEngagement.workerProfile.user:id,name',
                'aiEmployee:id,workspace_id,name,avatar_path,status',
                'messages' => fn ($query) => $query->latest()->limit(1),
            ])
            ->withCount('messages');

        $this->conversationInboxService->joinUserState($conversations, $user);

        $conversations = $conversations
            ->when($channelFilter, function ($query, $channelFilter): void {
                if (in_array($channelFilter, ['whatsapp', 'web'], true)) {
                    $query->where('channel', $channelFilter);

                    return;
                }

                if ($channelFilter === 'manual') {
                    $query->where('channel', 'manual')
                        ->where(function ($manualQuery): void {
                            $manualQuery
                                ->whereNull('metadata->channel_source')
                                ->orWhere('metadata->channel_source', 'manual');
                        });

                    return;
                }

                $query->where(function ($channelQuery) use ($channelFilter): void {
                    $channelQuery
                        ->where('channel', $channelFilter)
                        ->orWhere(function ($legacyQuery) use ($channelFilter): void {
                            $legacyQuery
                                ->where('channel', 'manual')
                                ->where('metadata->channel_source', $channelFilter);
                        });
                });
            })
            ->when($search, function ($query, $search): void {
                $query->where(function ($innerQuery) use ($search): void {
                    $innerQuery
                        ->where('external_id', 'like', '%'.$search.'%')
                        ->orWhereHas('customer', function ($customerQuery) use ($search): void {
                            $customerQuery
                                ->where('name', 'like', '%'.$search.'%')
                                ->orWhere('phone', 'like', '%'.$search.'%')
                                ->orWhere('email', 'like', '%'.$search.'%');
                        });
                });
            })
            ->when($request->filled('status'), function ($query) use ($request): void {
                $status = $request->string('status')->toString();
                if ($status === 'new') {
                    $query->where('status', 'open')->where('created_at', '>=', now()->subDays(2));
                } elseif ($status === 'needs_review') {
                    $query->whereIn('handoff_status', ['requested', 'queued', 'assigned']);
                } elseif (in_array($status, ['open', 'closed', 'archived'], true)) {
                    $query->where('status', $status);
                }
            })
            ->when($request->boolean('unread'), function ($query): void {
                $this->conversationInboxService->applyUnreadFilter($query, requireInbound: true);
            })
            ->when($handoffFilter === 'ai', function ($query): void {
                $query->where('ai_enabled', true)->where('handoff_status', 'none');
            })
            ->when($handoffFilter === 'handoff', function ($query): void {
                $query->whereIn('handoff_status', ['requested', 'queued', 'assigned', 'active']);
            })
            ->when($handoffFilter === 'queued', fn ($query) => $query->where('handoff_status', 'queued'))
            ->when($handoffFilter === 'assigned_me', function ($query) use ($request): void {
                $query->where('assigned_user_id', $request->user()?->id)
                    ->whereIn('handoff_status', ['assigned', 'active']);
            })
            ->when($handoffFilter === 'active', fn ($query) => $query->where('handoff_status', 'active'))
            ->when($handoffFilter === 'resolved', fn ($query) => $query->where('handoff_status', 'resolved'))
            ->orderByRaw('COALESCE(conversations.last_message_at, conversations.updated_at, conversations.created_at) DESC')
            ->orderByDesc('conversations.id')
            ->paginate(12)
            ->withQueryString();

        $conversations->setCollection(
            $conversations->getCollection()
                ->map(function (Conversation $conversation) use ($user) {
                    if (app(InboxHumanHandoffService::class)->repairAssistantHandoffClaim($conversation)) {
                        $conversation = $conversation->fresh() ?? $conversation;
                    }
                    $displayChannel = $this->resolveDisplayChannel($conversation);
                    $conversation->setAttribute('display_channel', $displayChannel);
                    $conversation->setAttribute(
                        'unread_count',
                        $this->conversationInboxService->unreadCountForConversation($conversation, $user),
                    );

                    return $conversation;
                })
                ->values()
        );

        $workspaceId = (int) $this->currentWorkspace()->id;
        $sessionKey = 'inbox.last_conversation.'.$workspaceId;

        $hasExplicitConversation = $request->has('conversation') || $request->has('conversation_id');
        $rawConversation = $request->get('conversation') ?? $request->get('conversation_id');

        if ($rawConversation === 'none') {
            $request->session()->forget($sessionKey);
            $activeConversationId = 0;
        } else {
            $activeConversationId = $request->integer('conversation') ?: $request->integer('conversation_id');
            if (! $activeConversationId && ! $hasExplicitConversation) {
                $isMobile = (bool) preg_match('/(android|iphone|ipad|ipod|mobile|phone)/i', (string) $request->userAgent());
                if (! $isMobile) {
                    $remembered = (int) $request->session()->get($sessionKey, 0);
                    if ($remembered > 0) {
                        $activeConversationId = $remembered;
                    }
                }
            }
        }

        $activeConversation = null;
        if ($activeConversationId) {
            $activeConversation = Conversation::query()
                ->with([
                    'customer.notes' => fn ($query) => $query->latest()->limit(8),
                    'customer.tags',
                    'customer.orders' => fn ($query) => $query->latest()->limit(8),
                    'assignedUser:id,name',
                    'assignedWorkerEngagement:id,workspace_id,worker_profile_id,status',
                    'assignedWorkerEngagement.workerProfile:id,user_id,display_name',
                    'assignedWorkerEngagement.workerProfile.user:id,name',
                    'aiEmployee:id,workspace_id,name,avatar_path,status',
                    'messages' => fn ($query) => $query->with([
                        'user:id,name',
                        'workerEngagement.workerProfile:id,user_id,display_name',
                        'workerEngagement.workerProfile.user:id,name',
                        'attachments',
                    ])->latest('id')->limit(80),
                ])
                ->find($activeConversationId);

            if ($activeConversation) {
                $this->conversationInboxService->markRead($activeConversation, $user);
                $activeConversation->setAttribute(
                    'unread_count',
                    $this->conversationInboxService->unreadCountForConversation($activeConversation, $user),
                );

                $activeConversation->setAttribute('display_channel', $this->resolveDisplayChannel($activeConversation));

                $activeConversation->setRelation(
                    'messages',
                    $activeConversation->messages->sortBy('id')->values()
                );

                $request->session()->put($sessionKey, $activeConversation->id);
            } elseif ($activeConversationId > 0) {
                $request->session()->forget($sessionKey);
            }
        }

        return view('workspace.conversations.index', [
            'conversations' => $conversations,
            'activeConversation' => $activeConversation,
            'channelFilter' => $channelFilter,
            'availableChannels' => [
                'whatsapp' => 'WhatsApp',
                'instagram' => 'Instagram',
                'facebook_messenger' => 'Facebook Messenger',
                'tiktok' => 'TikTok',
                'telegram' => 'Telegram',
                'email' => 'Email',
                'web' => 'Web',
                'manual' => 'Manual',
            ],
        ]);
    }

    public function create(): View
    {
        $this->authorize('create', Conversation::class);

        return view('workspace.conversations.create', [
            'customers' => Customer::query()->orderBy('name')->get(['id', 'name']),
        ]);
    }

    public function store(StoreConversationRequest $request): RedirectResponse
    {
        $this->authorize('create', Conversation::class);

        $payload = $request->validated();
        $payload['metadata'] = $this->parseJsonField($request, 'metadata_json');
        $payload['status'] = $payload['status'] ?? 'open';
        $payload['ai_enabled'] = $request->boolean('ai_enabled', true);

        $conversation = $this->conversationService->create($payload);

        return redirect()->route('workspace.conversations.index', [
            'conversation' => $conversation->id,
        ])->with('success', 'تم إنشاء المحادثة.');
    }

    public function edit(Conversation $conversation): RedirectResponse
    {
        $this->authorize('update', $conversation);

        return redirect()->route('workspace.conversations.index', [
            'conversation' => $conversation->id,
        ]);
    }

    public function update(UpdateConversationRequest $request, Conversation $conversation): RedirectResponse|JsonResponse
    {
        $payload = $request->validated();
        $actor = $request->user();
        abort_unless($actor !== null, 403);

        if ($request->exists('ai_enabled')) {
            $wantAi = $request->boolean('ai_enabled');
            if ($wantAi !== (bool) $conversation->ai_enabled) {
                if ($wantAi) {
                    $this->authorize('resumeAi', $conversation);
                }

                try {
                    $conversation = $this->conversationInboxService->toggleAi(
                        $conversation,
                        $actor,
                        $wantAi,
                    );
                } catch (InvalidHandoffTransitionException|InvalidArgumentException $exception) {
                    if ($request->expectsJson()) {
                        return response()->json(['success' => false, 'message' => $exception->getMessage()], 422);
                    }

                    return back()->with('error', $exception->getMessage());
                }
            }
        }

        $conversation->update([
            'status' => $payload['status'] ?? $conversation->status,
            'metadata' => $this->parseJsonField($request, 'metadata_json', $conversation->metadata ?? []),
        ]);

        $fresh = $conversation->fresh(['assignedUser:id,name']) ?? $conversation;
        app(InboxRealtimePublisher::class)->publishConversationUpdate($fresh, alreadyFresh: true);

        if ($request->expectsJson()) {
            $responder = $fresh->currentResponder();

            return response()->json([
                'success' => true,
                'message' => 'تم تحديث المحادثة.',
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

        return redirect()->route('workspace.conversations.index', [
            'conversation' => $fresh->id,
        ])->with('success', 'تم تحديث المحادثة.');
    }

    public function destroy(Conversation $conversation): RedirectResponse
    {
        $this->authorize('delete', $conversation);
        $convClone = clone $conversation;
        $conversation->delete();

        app(InboxRealtimePublisher::class)->publishConversationUpdate($convClone);

        return redirect()->route('workspace.conversations.index')->with('success', 'تم حذف المحادثة.');
    }

    public function storeMessage(StoreMessageRequest $request, Conversation $conversation): RedirectResponse|JsonResponse
    {
        $this->authorize('reply', $conversation);

        $payload = $request->validated();
        $payload['conversation_id'] = $conversation->id;
        $payload['customer_id'] = $payload['customer_id'] ?? $conversation->customer_id;
        $payload['message_type'] = $payload['message_type'] ?? 'text';
        $payload['metadata'] = $this->parseJsonField($request, 'metadata_json');
        if ($request->filled('client_id') || $request->filled('idempotency_key')) {
            $clientId = trim((string) ($request->input('client_id') ?? $request->input('idempotency_key')));
            if ($clientId !== '') {
                $payload['metadata']['client_id'] = $clientId;
                $payload['metadata']['idempotency_key'] = trim((string) ($request->input('idempotency_key') ?? $clientId)) ?: $clientId;
            }
        }

        $uploadedFiles = [];
        if ($request->hasFile('attachments')) {
            $files = $request->file('attachments');
            $uploadedFiles = is_array($files) ? $files : [$files];
        } elseif ($request->hasFile('attachment')) {
            $uploadedFiles = [$request->file('attachment')];
        } elseif ($request->hasFile('file')) {
            $uploadedFiles = [$request->file('file')];
        }

        if (! empty($uploadedFiles)) {
            $hasOnlyImages = true;
            foreach ($uploadedFiles as $f) {
                if ($f instanceof UploadedFile && ! str_starts_with((string) $f->getMimeType(), 'image/')) {
                    $hasOnlyImages = false;
                    break;
                }
            }
            $payload['message_type'] = $hasOnlyImages ? 'image' : 'file';
        }

        try {
            $dispatchInline = ! ($payload['direction'] === 'outbound' && $request->user() !== null);
            $sentMessage = $this->conversationService->addMessage(
                $conversation,
                $payload,
                $request->user(),
                $uploadedFiles,
                dispatchSideEffects: $dispatchInline,
            );
        } catch (InvalidArgumentException $e) {
            return response()->json([
                'message' => $e->getMessage(),
                'errors' => ['attachments' => [$e->getMessage()]],
            ], 422);
        }
        $conversation = $conversation->fresh() ?? $conversation;

        if ($sentMessage->direction === 'outbound' && $request->user()) {
            $actor = $request->user();
            app(ConversationPresenceService::class)->setTyping($conversation, $actor, false);
            app(ConversationPresenceService::class)->ping($conversation, $actor, ConversationPresenceService::STATE_VIEWING);
            if ($conversation->assigned_user_id === null && $conversation->assigned_worker_engagement_id === null) {
                $conversation->update([
                    'assigned_user_id' => $actor->id,
                    'ai_enabled' => false,
                    'handoff_status' => ConversationHandoffStatus::Active,
                    'handoff_assigned_at' => $conversation->handoff_assigned_at ?? now(),
                    'responder_type' => ConversationResponderService::TYPE_HUMAN,
                    'responder_name' => $actor->name,
                ]);
                app(ConversationResponderService::class)->recordTransition(
                    $conversation,
                    ConversationResponderService::TYPE_HUMAN,
                    $actor,
                    'human_message_sent',
                    'user',
                    $actor,
                    'قام الموظف بإرسال رد وتولي المحادثة',
                );
            } else {
                $conversation->update([
                    'ai_enabled' => false,
                    'handoff_status' => ConversationHandoffStatus::Active,
                    'responder_type' => ConversationResponderService::TYPE_HUMAN,
                    'responder_name' => $actor->name,
                ]);
                app(ConversationResponderService::class)->recordTransition(
                    $conversation,
                    ConversationResponderService::TYPE_HUMAN,
                    $actor,
                    'human_message_sent',
                    'user',
                    $actor,
                    'أرسل الموظف رسالة جديدة',
                );
            }

            if ($sentMessage->wasRecentlyCreated) {
                $this->conversationService->dispatchPostCommitSideEffects(
                    $conversation,
                    $sentMessage,
                    $actor,
                    [...$payload, 'skip_responder_log' => true],
                );
                $sentMessage = $sentMessage->fresh([
                    'attachments',
                    'user:id,name',
                    'workerEngagement.workerProfile:id,user_id,display_name',
                    'workerEngagement.workerProfile.user:id,name',
                ]) ?? $sentMessage;
            } else {
                if ($sentMessage->direction === 'outbound' && $sentMessage->delivery_status === 'pending') {
                    app(ChannelOutboundService::class)->ensureQueuedForMessage($conversation, $sentMessage);
                    $sentMessage = $sentMessage->fresh([
                        'attachments',
                        'user:id,name',
                        'workerEngagement.workerProfile:id,user_id,display_name',
                        'workerEngagement.workerProfile.user:id,name',
                    ]) ?? $sentMessage;
                }
                app(InboxRealtimePublisher::class)->publishConversationUpdate($conversation);
            }
            $conversation = $conversation->fresh() ?? $conversation;
        }

        if ($sentMessage->wasRecentlyCreated
            && $sentMessage->direction === 'inbound'
            && $this->aiEligibility->shouldDispatchAiJob($conversation, $sentMessage)) {
            ProcessAIResponse::dispatchForInbound($conversation->id, $sentMessage->id, (int) $conversation->workspace_id);
        }

        if ($request->expectsJson()) {
            return response()->json([
                'message' => $this->serializeMessageForInbox($sentMessage),
                'conversation' => [
                    'id' => $conversation->id,
                    'ai_enabled' => (bool) $conversation->ai_enabled,
                    'ai_replying' => $conversation->isAiReplying(),
                    'handoff_status' => $conversation->handoff_status instanceof ConversationHandoffStatus
                        ? $conversation->handoff_status->value
                        : ($conversation->handoff_status ?? 'none'),
                    'assigned_user_id' => $conversation->assigned_user_id,
                    'assigned_user_name' => $conversation->assignedUser?->name,
                    'responder' => $conversation->currentResponder(),
                    'active_responder' => $conversation->activeResponder(),
                    'last_message_sender' => $conversation->lastMessageSender(),
                ],
            ]);
        }

        return redirect()->route('workspace.conversations.index', [
            'conversation' => $conversation->id,
        ])->with('success', 'تم إرسال الرسالة.');
    }

    public function retryMessage(Request $request, Conversation $conversation, Message $message): JsonResponse|RedirectResponse
    {
        $this->authorize('reply', $conversation);

        abort_if(
            (int) $message->conversation_id !== (int) $conversation->id
                || (int) $message->workspace_id !== (int) $conversation->workspace_id,
            404,
        );

        abort_if($message->direction !== 'outbound', 422);

        $retried = app(ChannelOutboundService::class)->retryOutboundForMessage($conversation, $message);

        if ($request->expectsJson()) {
            return response()->json([
                'message' => $this->serializeMessageForInbox($retried),
            ]);
        }

        return redirect()->route('workspace.conversations.index', [
            'conversation' => $conversation->id,
        ])->with('success', 'تمت إعادة محاولة الإرسال.');
    }

    public function pollMessages(Request $request, Conversation $conversation): JsonResponse
    {
        $this->authorize('view', $conversation);

        $afterId = $request->integer('after_id', 0);

        $messages = Message::query()
            ->where('conversation_id', $conversation->id)
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
            $conversation,
            $afterId,
            $pendingIds,
        );

        $user = $request->user();
        $unreadCount = $user ? $this->conversationInboxService->unreadCountForConversation($conversation, $user) : 0;
        $latestMessage = $conversation->messages()->latest('id')->first();

        return response()->json([
            'messages' => $messages->map(fn (Message $m): array => $this->serializeMessageForInbox($m))->values(),
            'status_updates' => $statusUpdates,
            'conversation' => [
                'id' => $conversation->id,
                'status' => (string) $conversation->status,
                'ai_enabled' => (bool) $conversation->ai_enabled,
                'ai_replying' => $conversation->isAiReplying(),
                'handoff_status' => $conversation->handoff_status instanceof ConversationHandoffStatus
                    ? $conversation->handoff_status->value
                    : ($conversation->handoff_status ?? 'none'),
                'handoff_version' => (int) ($conversation->handoff_version ?? 1),
                'assigned_user_id' => $conversation->assigned_user_id,
                'assigned_user_name' => $conversation->assignedUser?->name,
                'assigned_worker_engagement_id' => $conversation->assigned_worker_engagement_id,
                'last_message' => $latestMessage?->content ?? '',
                'last_message_at' => $conversation->last_message_at?->format('h:i A') ?? '',
                'unread_count' => $unreadCount,
                'responder' => $conversation->currentResponder(),
            ],
        ]);
    }

    public function pollUpdates(Request $request): JsonResponse
    {
        $this->authorize('viewAny', Conversation::class);

        $user = $request->user();
        abort_unless($user !== null, 403);

        $workspaceId = (int) $this->currentWorkspace()->id;

        $recent = Conversation::query()
            ->where('workspace_id', $workspaceId)
            ->with([
                'customer:id,name',
                'assignedUser:id,name',
                'aiEmployee:id,name',
                'messages' => fn ($q) => $q->latest('id')->limit(1),
            ])
            ->orderByRaw('COALESCE(last_message_at, updated_at, created_at) DESC')
            ->orderByDesc('id')
            ->limit(20)
            ->get();

        $items = $recent->map(function (Conversation $conv) use ($user): array {
            $latestMsg = $conv->messages->first();

            return [
                'id' => $conv->id,
                'customer_name' => $conv->customer?->name ?? 'عميل غير معروف',
                'channel' => $this->resolveDisplayChannel($conv),
                'last_message' => $latestMsg?->content ?? '',
                'last_message_at' => $conv->last_message_at?->format('h:i A') ?? '',
                'unread_count' => $this->conversationInboxService->unreadCountForConversation($conv, $user),
                'is_ai_replying' => $conv->isAiReplying(),
                'ai_enabled' => (bool) $conv->ai_enabled,
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
        });

        return response()->json([
            'conversations' => $items,
        ]);
    }

    /**
     * @return array<string, mixed>
     */
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

        return [
            'id' => $message->id,
            'conversation_id' => $message->conversation_id,
            'user_id' => $message->user_id,
            'user_name' => $message->user?->name,
            'direction' => $message->direction,
            'message_type' => $message->message_type,
            'content' => $message->content,
            'ai_generated' => (bool) $message->ai_generated,
            'delivery_status' => $message->delivery_status,
            'delivery_error' => $message->delivery_status === 'failed'
                && is_string($message->metadata['delivery_error'] ?? null)
                ? $message->metadata['delivery_error']
                : null,
            'delivered_at' => optional($message->delivered_at)?->toIso8601String(),
            'read_at' => optional($message->read_at)?->toIso8601String(),
            'is_internal_note' => $isInternalNote,
            'sender_name' => $senderName,
            'created_at_time' => optional($message->created_at)?->format('h:i A'),
            'participant_type' => $participantType,
            'worker_engagement_id' => $message->worker_engagement_id,
            'client_id' => $message->metadata['client_id'] ?? $message->metadata['idempotency_key'] ?? null,
            'idempotency_key' => $message->metadata['idempotency_key'] ?? $message->metadata['client_id'] ?? null,
            'created_at' => optional($message->created_at)?->toIso8601String(),
            'attachments' => $message->attachments->map(function ($attachment): array {
                $ready = ($attachment->status ?? 'stored') === MessageAttachment::STATUS_STORED;

                return [
                    'id' => $attachment->id,
                    'kind' => $attachment->kind,
                    'original_name' => $attachment->original_name,
                    'mime_type' => $attachment->mime_type,
                    'size_bytes' => $attachment->size_bytes,
                    'status' => $attachment->status,
                    'width' => $attachment->width,
                    'height' => $attachment->height,
                    'download_url' => $ready
                        ? app(MessageAttachmentService::class)->workspaceDownloadUrl($attachment)
                        : null,
                ];
            })->values()->all(),
        ];
    }

    public function media(Request $request, Conversation $conversation): JsonResponse
    {
        $this->authorize('view', $conversation);

        $kind = $request->string('kind')->toString();

        $attachments = MessageAttachment::query()
            ->where('workspace_id', $conversation->workspace_id)
            ->whereHas('message', fn ($q) => $q->where('conversation_id', $conversation->id))
            ->where('status', MessageAttachment::STATUS_STORED)
            ->when($kind !== '' && $kind !== 'all', fn ($q) => $q->where('kind', $kind))
            ->with(['message.user:id,name', 'message.workerEngagement.workerProfile:id,display_name'])
            ->orderByDesc('id')
            ->limit(120)
            ->get();

        $attachmentService = app(MessageAttachmentService::class);

        $counts = MessageAttachment::query()
            ->where('workspace_id', $conversation->workspace_id)
            ->whereHas('message', fn ($q) => $q->where('conversation_id', $conversation->id))
            ->where('status', MessageAttachment::STATUS_STORED)
            ->selectRaw('kind, count(*) as total')
            ->groupBy('kind')
            ->pluck('total', 'kind')
            ->all();

        return response()->json([
            'counts' => [
                'all' => array_sum($counts),
                'image' => $counts['image'] ?? 0,
                'file' => ($counts['file'] ?? 0) + ($counts['document'] ?? 0),
                'video' => $counts['video'] ?? 0,
                'audio' => $counts['audio'] ?? 0,
            ],
            'media' => $attachments->map(function (MessageAttachment $att) use ($attachmentService): array {
                $msg = $att->message;
                $senderName = 'العميل';
                if ($msg?->direction === 'outbound') {
                    $isWorker = $msg->worker_engagement_id !== null;
                    $senderName = $isWorker
                        ? ($msg->workerEngagement?->workerProfile?->display_name ?: $msg->workerEngagement?->workerProfile?->user?->name ?: $msg->user?->name ?: 'موظف مستقل')
                        : ($msg->user?->name ?? 'فريق العمل');
                }

                return [
                    'id' => $att->id,
                    'message_id' => $att->message_id,
                    'kind' => $att->kind,
                    'original_name' => $att->original_name,
                    'mime_type' => $att->mime_type,
                    'size_bytes' => $att->size_bytes,
                    'size_human' => $att->size_bytes ? number_format($att->size_bytes / (1024 * 1024), 1).' MB' : '',
                    'width' => $att->width,
                    'height' => $att->height,
                    'download_url' => $attachmentService->workspaceDownloadUrl($att),
                    'sender_name' => $senderName,
                    'created_at' => $att->created_at?->format('Y-m-d h:i A'),
                    'created_at_human' => $att->created_at?->diffForHumans(),
                ];
            })->values(),
        ]);
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

    private function resolveDisplayChannel(Conversation $conversation): string
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

    public function typing(Request $request, Conversation $conversation): JsonResponse
    {
        $this->authorize('view', $conversation);
        $user = $request->user();
        $isTyping = $request->boolean('is_typing', true);
        $senderName = $user?->name ?? 'موظف';

        $res = app(ConversationPresenceService::class)->setTyping(
            $conversation,
            $user ?? 'guest',
            $isTyping,
            senderType: 'user',
            senderName: $senderName,
        );

        return response()->json([
            'status' => 'ok',
            'is_typing' => $isTyping,
            'typing_users' => $res['typing_users'] ?? [],
            'typing_summary' => $res['typing_summary'] ?? '',
            'active_responder' => $conversation->currentResponder(),
        ]);
    }

    public function presence(Conversation $conversation): JsonResponse
    {
        $this->authorize('view', $conversation);
        $state = app(ConversationPresenceService::class)->getConversationState($conversation);

        return response()->json([
            'status' => 'ok',
            'data' => $state,
        ]);
    }

    public function presencePing(Request $request, Conversation $conversation): JsonResponse
    {
        $this->authorize('view', $conversation);
        $user = $request->user();
        abort_unless($user !== null, 401);

        $stateInput = $request->string('state', ConversationPresenceService::STATE_VIEWING)->toString();
        $state = app(ConversationPresenceService::class)->ping($conversation, $user, $stateInput);

        return response()->json([
            'status' => 'ok',
            'data' => $state,
        ]);
    }

    public function presenceLeave(Request $request, Conversation $conversation): JsonResponse
    {
        $this->authorize('view', $conversation);
        $user = $request->user();
        if ($user !== null) {
            app(ConversationPresenceService::class)->leave($conversation, $user);
        }

        return response()->json([
            'status' => 'ok',
        ]);
    }
}
