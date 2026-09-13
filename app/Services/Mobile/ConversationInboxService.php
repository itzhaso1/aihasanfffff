<?php

namespace App\Services\Mobile;

use App\Events\Realtime\ConversationUpdated;
use App\Events\Realtime\MessageCreated;
use App\Models\Conversation;
use App\Models\ConversationUserState;
use App\Models\Message;
use App\Models\User;
use App\Models\Workspace;
use App\Services\Conversation\ConversationService;
use Illuminate\Contracts\Pagination\CursorPaginator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;

class ConversationInboxService
{
    public function __construct(
        private readonly ConversationService $conversationService,
    ) {}

    /**
     * @param  array{filter?:string,channel?:string,search?:string,per_page?:int}  $filters
     */
    public function listForUser(User $user, Workspace $workspace, array $filters = []): CursorPaginator
    {
        $perPage = max(1, min(50, (int) ($filters['per_page'] ?? 20)));
        $filter = (string) ($filters['filter'] ?? 'all');
        $channel = trim((string) ($filters['channel'] ?? ''));
        $search = trim((string) ($filters['search'] ?? ''));

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

        if ($filter === 'archived') {
            $query->whereNotNull('cus.archived_at');
        } else {
            $query->where(function (Builder $q): void {
                $q->whereNull('cus.archived_at');
            });
        }

        if ($filter === 'unread') {
            $query->where(function (Builder $q): void {
                $q->whereNull('cus.last_read_message_id')
                    ->orWhereRaw('cus.last_read_message_id < (select max(m.id) from messages m where m.conversation_id = conversations.id and m.direction = ?)', ['inbound']);
            });
        }

        if ($channel !== '') {
            $query->where('conversations.channel', $channel);
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

        return $query
            ->orderByDesc('conversations.last_message_at')
            ->orderByDesc('conversations.id')
            ->cursorPaginate($perPage);
    }

    public function unreadConversationCount(User $user, Workspace $workspace): int
    {
        return (int) Conversation::query()
            ->leftJoin('conversation_user_states as cus', function ($join) use ($user): void {
                $join->on('cus.conversation_id', '=', 'conversations.id')
                    ->where('cus.user_id', '=', $user->id);
            })
            ->where(function (Builder $q): void {
                $q->whereNull('cus.archived_at');
            })
            ->where(function (Builder $q): void {
                $q->whereNull('cus.last_read_message_id')
                    ->orWhereRaw('cus.last_read_message_id < (select max(m.id) from messages m where m.conversation_id = conversations.id and m.direction = ?)', ['inbound']);
            })
            ->whereExists(function ($q): void {
                $q->selectRaw('1')
                    ->from('messages')
                    ->whereColumn('messages.conversation_id', 'conversations.id')
                    ->where('messages.direction', 'inbound');
            })
            ->count('conversations.id');
    }

    /**
     * @param  array{direction?:string,message_type?:string,content?:string,metadata?:array,idempotency_key?:string}  $data
     */
    public function sendMessage(Conversation $conversation, User $actor, array $data): Message
    {
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

            event(new MessageCreated($message));
            event(new ConversationUpdated($conversation->fresh()));

            return $message->load(['user', 'customer', 'attachments']);
        });
    }

    private function findMessageByIdempotencyKey(Conversation $conversation, string $key): ?Message
    {
        $existing = Message::query()
            ->where('conversation_id', $conversation->id)
            ->where('metadata->idempotency_key', $key)
            ->lockForUpdate()
            ->first();

        if ($existing) {
            return $existing;
        }

        return Message::query()
            ->where('conversation_id', $conversation->id)
            ->lockForUpdate()
            ->get()
            ->first(function (Message $message) use ($key): bool {
                $metadata = is_array($message->metadata) ? $message->metadata : [];

                return (string) ($metadata['idempotency_key'] ?? '') === $key
                    || (string) ($metadata['client_id'] ?? '') === $key;
            });
    }

    public function markRead(Conversation $conversation, User $user, ?int $messageId = null): ConversationUserState
    {
        $lastId = $messageId
            ?? (int) Message::query()->where('conversation_id', $conversation->id)->max('id');

        $state = ConversationUserState::query()->firstOrNew([
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
        $state = ConversationUserState::query()->firstOrNew([
            'conversation_id' => $conversation->id,
            'user_id' => $user->id,
        ]);
        $state->workspace_id = $conversation->workspace_id;
        $state->archived_at = $archived ? now() : null;
        $state->save();

        if ($archived) {
            $conversation->update(['status' => 'archived']);
        }

        event(new ConversationUpdated($conversation->fresh()));

        return $state;
    }

    public function mute(Conversation $conversation, User $user, bool $muted = true): ConversationUserState
    {
        $state = ConversationUserState::query()->firstOrNew([
            'conversation_id' => $conversation->id,
            'user_id' => $user->id,
        ]);
        $state->workspace_id = $conversation->workspace_id;
        $state->muted_at = $muted ? now() : null;
        $state->save();

        return $state;
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

    public function unreadCountForConversation(Conversation $conversation, User $user): int
    {
        $state = ConversationUserState::query()
            ->where('conversation_id', $conversation->id)
            ->where('user_id', $user->id)
            ->first();

        $query = Message::query()
            ->where('conversation_id', $conversation->id)
            ->where('direction', 'inbound');

        if ($state?->last_read_message_id) {
            $query->where('id', '>', $state->last_read_message_id);
        }

        return (int) $query->count();
    }
}
