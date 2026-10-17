<?php

namespace App\Services\Inbox;

use App\Jobs\ProcessConversationBulkMessageJob;
use App\Models\Conversation;
use App\Models\ConversationBulkSend;
use App\Models\ConversationBulkSendItem;
use App\Models\Message;
use App\Models\User;
use App\Models\Workspace;
use App\Services\Channel\Outbound\ChannelOutboundService;
use App\Services\Conversation\ConversationService;
use App\Services\Mobile\ConversationInboxService;
use App\Services\Mobile\MobileInboxActor;
use App\Support\Tenancy\WorkspaceContext;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;

class ConversationBulkMessageService
{
    public const MAX_CONVERSATIONS = 500;

    public function __construct(
        private readonly ConversationInboxService $inbox,
        private readonly MobileInboxActor $inboxActor,
        private readonly ConversationService $conversationService,
        private readonly ChannelOutboundService $channelOutbound,
        private readonly WorkspaceContext $workspaceContext,
    ) {}

    /**
     * @param  list<int>  $conversationIds
     * @param  array<string, mixed>  $filters
     */
    public function create(
        User $actor,
        Workspace $workspace,
        array $conversationIds,
        bool $selectAll,
        array $filters,
        string $content,
    ): ConversationBulkSend {
        if (! $this->inboxActor->isMember($actor, $workspace)) {
            throw new AuthorizationException('Bulk messaging requires workspace membership.');
        }

        $content = trim($content);
        if ($content === '') {
            throw new \InvalidArgumentException('Message content is required.');
        }

        $selection = $selectAll
            ? $this->inbox->idsForUser(
                $actor,
                $workspace,
                $filters,
                self::MAX_CONVERSATIONS,
            )
            : ['ids' => array_values(array_unique(array_map('intval', $conversationIds))), 'limited' => false];
        $ids = $selection['ids'];

        $conversations = $this->conversationsForSelection($actor, $workspace, $ids);
        if (! $selectAll && $conversations->count() !== count($ids)) {
            throw (new ModelNotFoundException)->setModel(Conversation::class);
        }

        $bulk = DB::transaction(function () use (
            $actor,
            $workspace,
            $content,
            $ids,
            $conversations,
            $selectAll,
            $filters,
            $selection,
        ): ConversationBulkSend {
            $items = [];
            $queued = 0;
            $skipped = 0;
            foreach ($ids as $conversationId) {
                $conversation = $conversations->get($conversationId);
                if (! $conversation instanceof Conversation) {
                    continue;
                }

                $reason = $this->skipReason($actor, $conversation);
                if ($reason !== null) {
                    $items[] = [
                        'workspace_id' => $workspace->id,
                        'conversation_id' => $conversation->id,
                        'status' => ConversationBulkSendItem::STATUS_SKIPPED,
                        'error_code' => $reason,
                        'error_message' => $this->reasonLabel($reason),
                    ];
                    $skipped++;

                    continue;
                }

                $items[] = [
                    'workspace_id' => $workspace->id,
                    'conversation_id' => $conversation->id,
                    'status' => ConversationBulkSendItem::STATUS_PENDING,
                ];
                $queued++;
            }

            $bulk = ConversationBulkSend::create([
                'id' => (string) Str::uuid(),
                'workspace_id' => $workspace->id,
                'user_id' => $actor->id,
                'content_hash' => hash('sha256', $content),
                'status' => $queued > 0
                    ? ConversationBulkSend::STATUS_QUEUED
                    : ConversationBulkSend::STATUS_COMPLETED_WITH_ERRORS,
                'total' => count($items),
                'queued_count' => $queued,
                'processed_count' => $skipped,
                'skipped_count' => $skipped,
                'metadata' => [
                    'select_all' => $selectAll,
                    'filters' => $filters,
                    'selection_limited' => $selection['limited'],
                ],
            ]);
            foreach ($items as $item) {
                $bulk->items()->create($item);
            }

            if ($queued > 0) {
                ProcessConversationBulkMessageJob::dispatch(
                    (string) $bulk->id,
                    (int) $workspace->id,
                    $content,
                )->afterCommit();
            }

            return $bulk;
        });

        return $bulk->load('items');
    }

    public function process(string $bulkSendId, int $workspaceId, string $content): void
    {
        $this->workspaceContext->runForId($workspaceId, function () use ($bulkSendId, $workspaceId, $content): void {
            $bulk = ConversationBulkSend::queryForWorkspace($workspaceId)->find($bulkSendId);
            if (! $bulk) {
                return;
            }
            $bulk->update(['status' => ConversationBulkSend::STATUS_PROCESSING]);
            $actor = $bulk->user_id ? User::query()->find($bulk->user_id) : null;

            ConversationBulkSendItem::queryForWorkspace($workspaceId)
                ->where('bulk_send_id', $bulk->id)
                ->where('status', ConversationBulkSendItem::STATUS_PENDING)
                ->orderBy('id')
                ->chunkById(50, function (Collection $items) use ($bulk, $actor, $content): void {
                    foreach ($items as $item) {
                        $this->processItem($bulk, $item, $actor, $content);
                    }
                });

            $bulk->refresh();
            $bulk->update([
                'status' => $bulk->failed_count > 0
                    ? ConversationBulkSend::STATUS_COMPLETED_WITH_ERRORS
                    : ConversationBulkSend::STATUS_COMPLETED,
            ]);
        });
    }

    /**
     * @return array<string, mixed>
     */
    public function status(User $actor, Workspace $workspace, string $bulkSendId): array
    {
        if (! $this->inboxActor->isMember($actor, $workspace)) {
            throw new ModelNotFoundException;
        }
        $bulk = ConversationBulkSend::queryForWorkspace($workspace->id)
            ->with(['items.conversation:id,external_id'])
            ->findOrFail($bulkSendId);

        return [
            'bulk_send_id' => (string) $bulk->id,
            'status' => $bulk->status,
            'total' => $bulk->total,
            'queued_count' => $bulk->queued_count,
            'processed_count' => $bulk->processed_count,
            'sent_count' => $bulk->sent_count,
            'failed_count' => $bulk->failed_count,
            'skipped_count' => $bulk->skipped_count,
            'skipped_preview' => $bulk->items
                ->where('status', ConversationBulkSendItem::STATUS_SKIPPED)
                ->take(20)
                ->map(fn (ConversationBulkSendItem $item): array => [
                    'conversation_id' => $item->conversation_id,
                    'reason' => $item->error_code,
                    'message' => $item->error_message,
                ])
                ->values()
                ->all(),
            'failed_preview' => $bulk->items
                ->where('status', ConversationBulkSendItem::STATUS_FAILED)
                ->take(20)
                ->map(fn (ConversationBulkSendItem $item): array => [
                    'conversation_id' => $item->conversation_id,
                    'reason' => $item->error_code,
                    'message' => $item->error_message,
                ])
                ->values()
                ->all(),
        ];
    }

    /**
     * @param  list<int>  $ids
     * @return Collection<int, Conversation>
     */
    private function conversationsForSelection(
        User $actor,
        Workspace $workspace,
        array $ids,
    ): Collection {
        if ($ids === []) {
            return new Collection;
        }
        $query = Conversation::query()
            ->whereIn('conversations.id', $ids)
            ->with('customer');
        $this->inboxActor->constrain($query, $actor, $workspace);

        return $query->get()->keyBy('id');
    }

    private function skipReason(User $actor, Conversation $conversation): ?string
    {
        if (! Gate::forUser($actor)->allows('update', $conversation)) {
            return 'not_authorized';
        }
        if (! $conversation->isOpen()) {
            return 'conversation_closed';
        }
        if (in_array($conversation->handoffStatusEnum()->value, [
            'requested',
            'queued',
            'assigned',
            'active',
        ], true)) {
            return 'human_handoff';
        }
        $channel = $this->channelOutbound->resolveOutboundChannel($conversation);
        if (! $this->channelOutbound->supportsChannel($channel)) {
            return 'unsupported_channel';
        }
        if (trim((string) $conversation->external_id) === '') {
            return 'missing_channel_recipient';
        }

        return null;
    }

    private function processItem(
        ConversationBulkSend $bulk,
        ConversationBulkSendItem $item,
        ?User $actor,
        string $content,
    ): void {
        $conversation = Conversation::query()->find($item->conversation_id);
        if (! $conversation) {
            $this->markItemFailed($bulk, $item, 'conversation_not_found', 'المحادثة غير موجودة.');

            return;
        }
        if ($actor && ($reason = $this->skipReason($actor, $conversation)) !== null) {
            $this->markItemSkipped($bulk, $item, $reason);

            return;
        }

        $key = 'bulk:'.$bulk->id.':'.$conversation->id;
        $existing = Message::query()
            ->where('conversation_id', $conversation->id)
            ->where('metadata->bulk_item_key', $key)
            ->first();
        try {
            $message = $existing ?? $this->conversationService->addMessage(
                $conversation,
                [
                    'direction' => 'outbound',
                    'message_type' => 'text',
                    'content' => $content,
                    'customer_id' => $conversation->customer_id,
                    'metadata' => [
                        'bulk_send_id' => (string) $bulk->id,
                        'bulk_item_key' => $key,
                    ],
                ],
                $actor,
            );
            $item->update([
                'status' => ConversationBulkSendItem::STATUS_QUEUED,
                'message_id' => $message->id,
            ]);
            $bulk->increment('processed_count');
            $bulk->increment('sent_count');
        } catch (\Throwable $exception) {
            $this->markItemFailed($bulk, $item, 'send_failed', $exception->getMessage());
        }
    }

    private function markItemSkipped(
        ConversationBulkSend $bulk,
        ConversationBulkSendItem $item,
        string $reason,
    ): void {
        $item->update([
            'status' => ConversationBulkSendItem::STATUS_SKIPPED,
            'error_code' => $reason,
            'error_message' => $this->reasonLabel($reason),
        ]);
        $bulk->increment('processed_count');
        $bulk->increment('skipped_count');
    }

    private function markItemFailed(
        ConversationBulkSend $bulk,
        ConversationBulkSendItem $item,
        string $reason,
        string $message,
    ): void {
        $item->update([
            'status' => ConversationBulkSendItem::STATUS_FAILED,
            'error_code' => $reason,
            'error_message' => mb_substr($message, 0, 500),
        ]);
        $bulk->increment('processed_count');
        $bulk->increment('failed_count');
    }

    private function reasonLabel(string $reason): string
    {
        return match ($reason) {
            'not_authorized' => 'لا تملك صلاحية الإرسال.',
            'conversation_closed' => 'المحادثة مغلقة.',
            'human_handoff' => 'المحادثة محولة للفريق.',
            'unsupported_channel' => 'القناة غير مدعومة للإرسال الجماعي.',
            'missing_channel_recipient' => 'لا يوجد معرّف للقناة.',
            default => 'تم تخطي المحادثة.',
        };
    }
}
