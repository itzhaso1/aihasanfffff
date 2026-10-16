<?php

namespace App\Services\Mobile;

use App\Events\Realtime\ConversationUpdated;
use App\Models\Conversation;
use App\Models\ConversationUserState;
use App\Models\Message;
use App\Models\User;
use App\Models\Workspace;
use App\Services\Conversation\ConversationHandoffService;
use App\Services\Conversation\ConversationService;
use App\Services\Inbox\InboxHumanHandoffService;
use App\Services\Worker\WorkerInboxService;
use App\Support\Tenancy\WorkspaceContext;
use Illuminate\Contracts\Pagination\CursorPaginator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class ConversationInboxService
{
    public function __construct(
        private readonly ConversationService $conversationService,
        private readonly ConversationHandoffService $handoff,
        private readonly MobileInboxActor $inboxActor,
        private readonly WorkerInboxService $workerInbox,
        private readonly WorkspaceContext $workspaceContext,
    ) {}

    /**
     * @param  array{filter?:string,channel?:string,search?:string,customer_priority?:string,payment_status?:string,review_status?:string,per_page?:int}  $filters
     */
    public function listForUser(User $user, Workspace $workspace, array $filters = []): CursorPaginator
    {
        $perPage = max(1, min(50, (int) ($filters['per_page'] ?? 20)));
        $filter = (string) ($filters['filter'] ?? 'all');
        $handoff = trim((string) ($filters['handoff'] ?? ''));
        $channel = trim((string) ($filters['channel'] ?? ''));
        $search = trim((string) ($filters['search'] ?? ''));
        $customerPriority = trim((string) ($filters['customer_priority'] ?? ''));
        $paymentStatus = trim((string) ($filters['payment_status'] ?? ''));
        $reviewStatus = trim((string) ($filters['review_status'] ?? ''));

        $query = Conversation::query()
            ->with([
                'customer:id,name,phone,email',
                'messages' => fn ($q) => $q->latest('id')->limit(1),
            ])
            ->leftJoin('conversation_user_states as cus', function ($join) use ($user): void {
                $join->on('cus.conversation_id', '=', 'conversations.id')
                    ->where('cus.user_id', '=', $user->id);
            })
            ->select('conversations.*')
            ->addSelect([
                'cus.last_read_message_id',
                'cus.muted_at as user_muted_at',
                'cus.archived_at as user_archived_at',
            ]);

        $this->inboxActor->constrain($query, $user, $workspace);

        if ($filter === 'archived') {
            $query->whereNotNull('cus.archived_at');
        } else {
            $query->where(function (Builder $q): void {
                $q->whereNull('cus.archived_at');
            });
        }

        if ($filter === 'unread') {
            $this->applyUnreadFilter($query);
        }

        if ($handoff === 'ai') {
            $query->where('conversations.ai_enabled', true)->where('conversations.handoff_status', 'none');
        } elseif ($handoff === 'handoff') {
            $query->whereIn('conversations.handoff_status', ['requested', 'queued', 'assigned', 'active']);
        } elseif ($handoff === 'queued') {
            $query->where('conversations.handoff_status', 'queued');
        } elseif ($handoff === 'assigned_me') {
            $query->where('conversations.assigned_user_id', $user->id)
                ->whereIn('conversations.handoff_status', ['assigned', 'active']);
        } elseif ($handoff === 'active') {
            $query->where('conversations.handoff_status', 'active');
        } elseif ($handoff === 'resolved') {
            $query->where('conversations.handoff_status', 'resolved');
        }

        if ($channel !== '') {
            $query->where('conversations.channel', $channel);
        }

        foreach ([
            'customer_priority' => $customerPriority,
            'payment_status' => $paymentStatus,
            'review_status' => $reviewStatus,
        ] as $column => $value) {
            if ($value !== '') {
                $query->where('conversations.'.$column, $value);
            }
        }

        if ($search !== '') {
            $query->where(function (Builder $q) use ($search): void {
                $q->where('conversations.external_id', 'like', '%'.$search.'%')
                    ->orWhereHas('customer', function (Builder $customer) use ($search): void {
                        $customer->where('name', 'like', '%'.$search.'%')
                            ->orWhere('phone', 'like', '%'.$search.'%')
                            ->orWhere('email', 'like', '%'.$search.'%');
                    })
                    ->orWhereHas('messages', function (Builder $messages) use ($search): void {
                        $messages->where('content', 'like', '%'.$search.'%');
                    });
            });
        }

        $paginator = $query
            ->orderByDesc('conversations.last_message_at')
            ->orderByDesc('conversations.id')
            ->cursorPaginate($perPage);

        $handoffRepair = app(InboxHumanHandoffService::class);
        foreach ($paginator->items() as $conversation) {
            if ($conversation instanceof Conversation && $handoffRepair->repairAssistantHandoffClaim($conversation)) {
                $conversation->refresh();
            }
        }

        return $paginator;
    }

    public function unreadConversationCount(User $user, Workspace $workspace): int
    {
        $query = Conversation::query()
            ->leftJoin('conversation_user_states as cus', function ($join) use ($user): void {
                $join->on('cus.conversation_id', '=', 'conversations.id')
                    ->where('cus.user_id', '=', $user->id);
            })
            ->where(function (Builder $q): void {
                $q->whereNull('cus.archived_at');
            });

        $this->inboxActor->constrain($query, $user, $workspace);
        $this->applyUnreadFilter($query, requireInbound: true);

        return (int) $query->count('conversations.id');
    }

    /**
     * @param  array{direction?:string,message_type?:string,content?:string,metadata?:array,idempotency_key?:string}  $data
     */
    public function sendMessage(Conversation $conversation, User $actor, array $data, ?Request $request = null): Message
    {
        if ($this->inboxActor->sendsAsWorker($actor, $conversation)) {
            $workspace = $this->workspaceContext->workspace();
            if (! $workspace instanceof Workspace || (int) $workspace->id !== (int) $conversation->workspace_id) {
                $workspace = Workspace::query()->findOrFail($conversation->workspace_id);
            }

            return $this->workerInbox->sendMessage(
                $actor,
                $workspace,
                $conversation->id,
                [
                    'content' => (string) ($data['content'] ?? ''),
                    'message_type' => (string) ($data['message_type'] ?? 'text'),
                    'idempotency_key' => $data['idempotency_key'] ?? null,
                ],
                $request,
            );
        }

        $payload = [
            'direction' => $data['direction'] ?? 'outbound',
            'message_type' => $data['message_type'] ?? 'text',
            'content' => $data['content'] ?? '',
            'customer_id' => $conversation->customer_id,
            'metadata' => array_filter([
                ...((array) ($data['metadata'] ?? [])),
                'idempotency_key' => $data['idempotency_key'] ?? null,
                'channel' => $conversation->channel,
            ], fn ($v) => $v !== null),
        ];

        $idempotencyKey = isset($data['idempotency_key']) ? trim((string) $data['idempotency_key']) : '';

        return DB::transaction(function () use ($conversation, $actor, $payload, $idempotencyKey): Message {
            Conversation::query()->whereKey($conversation->id)->lockForUpdate()->first();

            if ($idempotencyKey !== '') {
                $existing = $this->findMessageByIdempotencyKey($conversation, $idempotencyKey);
                if ($existing) {
                    return $existing->loadMissing(['user', 'customer', 'attachments']);
                }
            }

            $message = $this->conversationService->addMessage($conversation, $payload, $actor);

            return $message->load(['user', 'customer', 'attachments']);
        });
    }

    private function findMessageByIdempotencyKey(Conversation $conversation, string $key): ?Message
    {
        $existing = Message::query()
            ->where('conversation_id', $conversation->id)
            ->where(function ($query) use ($key): void {
                $query->where('metadata->idempotency_key', $key)
                    ->orWhere('metadata->client_id', $key);
            })
            ->lockForUpdate()
            ->first();

        return $existing;
    }

    public function markRead(Conversation $conversation, User $user, ?int $messageId = null): ConversationUserState
    {
        $lastId = $messageId
            ?? (int) Message::withoutGlobalScopes()
                ->where('workspace_id', $conversation->workspace_id)
                ->where('conversation_id', $conversation->id)
                ->max('id');

        $state = ConversationUserState::withoutGlobalScopes()->firstOrNew([
            'workspace_id' => $conversation->workspace_id,
            'conversation_id' => $conversation->id,
            'user_id' => $user->id,
        ]);

        $state->workspace_id = $conversation->workspace_id;
        $state->last_read_message_id = $lastId > 0 ? $lastId : null;
        $state->last_read_at = now();
        $state->save();

        event(new ConversationUpdated($conversation->fresh()));

        return $state;
    }

    public function archive(Conversation $conversation, User $user, bool $archived = true): ConversationUserState
    {
        $state = ConversationUserState::withoutGlobalScopes()->firstOrNew([
            'workspace_id' => $conversation->workspace_id,
            'conversation_id' => $conversation->id,
            'user_id' => $user->id,
        ]);
        $state->workspace_id = $conversation->workspace_id;
        $state->archived_at = $archived ? now() : null;
        $state->save();

        event(new ConversationUpdated($conversation->fresh()));

        return $state;
    }

    public function mute(Conversation $conversation, User $user, bool $muted = true): ConversationUserState
    {
        $state = ConversationUserState::withoutGlobalScopes()->firstOrNew([
            'workspace_id' => $conversation->workspace_id,
            'conversation_id' => $conversation->id,
            'user_id' => $user->id,
        ]);
        $state->workspace_id = $conversation->workspace_id;
        $state->muted_at = $muted ? now() : null;
        $state->save();

        return $state;
    }

    public function toggleAi(Conversation $conversation, User $actor, bool $enabled): Conversation
    {
        if ($enabled) {
            return $this->handoff->resumeAi($conversation, $actor);
        }

        return $this->handoff->disableAi($conversation, $actor);
    }

    public function messages(Conversation $conversation, int $perPage = 30): CursorPaginator
    {
        $perPage = max(1, min(50, $perPage));

        return Message::query()
            ->with(['user:id,name', 'customer:id,name', 'attachments'])
            ->where('conversation_id', $conversation->id)
            ->orderByDesc('id')
            ->cursorPaginate($perPage);
    }

    public function joinUserState(Builder $query, User $user): void
    {
        if ($this->queryHasUserStateJoin($query)) {
            return;
        }

        $query->leftJoin('conversation_user_states as cus', function ($join) use ($user): void {
            $join->on('cus.conversation_id', '=', 'conversations.id')
                ->where('cus.user_id', '=', $user->id);
        });
    }

    /**
     * Unread = no read cursor, or read cursor behind latest inbound message.
     * Uses one grouped subquery join instead of a per-row correlated MAX().
     * Requires {@see joinUserState} on the same query (alias `cus`).
     */
    public function applyUnreadFilter(Builder $query, bool $requireInbound = false): void
    {
        $inboundMaxSub = Message::query()
            ->selectRaw('conversation_id, MAX(id) as max_inbound_message_id')
            ->where('direction', 'inbound')
            ->groupBy('conversation_id');

        $query->leftJoinSub($inboundMaxSub, 'inbound_latest', function ($join): void {
            $join->on('inbound_latest.conversation_id', '=', 'conversations.id');
        });

        if ($requireInbound) {
            $query->whereNotNull('inbound_latest.max_inbound_message_id');
        }

        $query->where(function (Builder $q): void {
            $q->whereNull('cus.last_read_message_id')
                ->orWhereColumn('cus.last_read_message_id', '<', 'inbound_latest.max_inbound_message_id');
        });
    }

    public function unreadCountForConversation(Conversation $conversation, User $user): int
    {
        $state = ConversationUserState::withoutGlobalScopes()
            ->where('workspace_id', $conversation->workspace_id)
            ->where('conversation_id', $conversation->id)
            ->where('user_id', $user->id)
            ->first();

        $query = Message::withoutGlobalScopes()
            ->where('workspace_id', $conversation->workspace_id)
            ->where('conversation_id', $conversation->id)
            ->where('direction', 'inbound');

        if ($state?->last_read_message_id) {
            $query->where('id', '>', $state->last_read_message_id);
        }

        return (int) $query->count();
    }

    private function queryHasUserStateJoin(Builder $query): bool
    {
        $joins = $query->getQuery()->joins ?? [];

        foreach ($joins as $join) {
            if ($join->table === 'conversation_user_states as cus') {
                return true;
            }
        }

        return false;
    }
}
