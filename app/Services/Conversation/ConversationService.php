<?php

namespace App\Services\Conversation;

use App\Enums\ConversationHandoffStatus;
use App\Models\Conversation;
use App\Models\Message;
use App\Models\User;
use App\Models\Worker\WorkerEngagement;
use App\Services\Channel\Outbound\ChannelOutboundService;
use App\Services\Inbox\InboxHumanHandoffService;
use App\Services\Inbox\InboxRealtimePublisher;
use App\Services\Mobile\MessageAttachmentService;
use App\Support\Tenancy\WorkspaceContext;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

class ConversationService
{
    private const DB_CHANNELS = ['whatsapp', 'web', 'manual', 'instagram', 'facebook_messenger', 'email', 'tiktok', 'telegram'];

    public function __construct(
        private readonly ChannelOutboundService $channelOutbound,
        private readonly ConversationHandoffService $handoff,
    ) {}

    /**
     * @param  array<string, mixed>  $data
     */
    public function create(array $data): Conversation
    {
        $data = $this->normalizeChannelPayload($data);

        return Conversation::query()->create($data);
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public function addMessage(Conversation $conversation, array $data, ?User $actor = null, array $uploadedFiles = []): Message
    {
        $workspaceId = (int) ($data['workspace_id'] ?? $conversation->workspace_id);

        $runner = function () use ($conversation, $data, $actor, $uploadedFiles, $workspaceId): Message {
            $externalMessageId = isset($data['external_message_id']) && is_string($data['external_message_id']) && trim($data['external_message_id']) !== ''
            ? trim($data['external_message_id'])
            : null;

            $idempotencyKey = $data['idempotency_key']
                ?? $data['metadata']['idempotency_key']
                ?? $data['metadata']['client_id']
                ?? null;
            $idempotencyKey = is_string($idempotencyKey) && trim($idempotencyKey) !== '' ? trim($idempotencyKey) : null;

            if ($externalMessageId !== null) {
                $existing = Message::withoutGlobalScopes()
                    ->where('workspace_id', $workspaceId)
                    ->where('external_message_id', $externalMessageId)
                    ->first();
                if ($existing) {
                    return $existing->load('attachments');
                }
            }

            if ($idempotencyKey !== null) {
                $existing = Message::withoutGlobalScopes()
                    ->where('workspace_id', $workspaceId)
                    ->where('conversation_id', $conversation->id)
                    ->where(function ($query) use ($idempotencyKey): void {
                        $query->where('metadata->idempotency_key', $idempotencyKey)
                            ->orWhere('metadata->client_id', $idempotencyKey);
                    })
                    ->first();
                if ($existing) {
                    return $existing->load('attachments');
                }
            }

            $message = DB::transaction(function () use ($conversation, $data, $actor, $uploadedFiles, $workspaceId, $externalMessageId): Message {
                try {
                    if (! isset($data['worker_engagement_id']) && $actor instanceof User && ! (bool) ($data['ai_generated'] ?? false)) {
                        $candidateEngagementId = WorkerEngagement::withoutGlobalScopes()
                            ->where('workspace_id', $workspaceId)
                            ->where('status', 'active')
                            ->whereHas('workerProfile', fn ($q) => $q->where('user_id', $actor->id))
                            ->when($conversation->assigned_worker_engagement_id, function ($q) use ($conversation): void {
                                $q->orderByRaw('CASE WHEN id = ? THEN 0 ELSE 1 END', [$conversation->assigned_worker_engagement_id]);
                            })
                            ->value('id');
                        if ($candidateEngagementId) {
                            $data['worker_engagement_id'] = $candidateEngagementId;
                        }
                    }

                    $message = $conversation->messages()->create([
                        ...$data,
                        'workspace_id' => $workspaceId,
                        'user_id' => $data['user_id'] ?? $actor?->id,
                    ]);
                } catch (UniqueConstraintViolationException $e) {
                    if ($externalMessageId !== null) {
                        $existing = Message::withoutGlobalScopes()
                            ->where('workspace_id', $workspaceId)
                            ->where('external_message_id', $externalMessageId)
                            ->first();
                        if ($existing) {
                            return $existing->load('attachments');
                        }
                    }
                    throw $e;
                }

                if (! empty($uploadedFiles) && $actor) {
                    $attachmentService = app(MessageAttachmentService::class);
                    foreach ($uploadedFiles as $file) {
                        if ($file instanceof UploadedFile && $file->isValid()) {
                            $attachmentService->upload($message, $file, $actor);
                        }
                    }
                }

                $metadata = is_array($conversation->metadata) ? $conversation->metadata : [];
                $metadata['channel_source'] = $metadata['channel_source'] ?? $this->resolveDisplayChannel($conversation);

                $conversationAttributes = [
                    'last_message_at' => $message->created_at,
                    'metadata' => $metadata,
                ];
                if ($message->direction === 'inbound') {
                    $conversationAttributes = $conversation->attributesForInboundCustomerMessage($conversationAttributes);
                } elseif ($actor instanceof User && ! (bool) ($data['ai_generated'] ?? false)) {
                    // If conversation was not assigned to any human yet, auto-assign to the replying actor
                    if ($conversation->assigned_user_id === null && $conversation->assigned_worker_engagement_id === null) {
                        $conversationAttributes['handoff_assigned_at'] = $conversation->handoff_assigned_at ?? now();

                        $workerEngagementId = $data['worker_engagement_id'] ?? null;
                        if ($workerEngagementId !== null) {
                            $conversationAttributes['assigned_worker_engagement_id'] = $workerEngagementId;
                            $workerEngagement = WorkerEngagement::withoutGlobalScopes()
                                ->with('workerProfile.user')
                                ->find($workerEngagementId);
                            $workerProfile = $workerEngagement?->workerProfile;
                            $conversationAttributes['responder_name'] = $workerProfile?->display_name
                                ?: $workerProfile?->user?->name
                                ?: $actor->name;
                        } else {
                            $conversationAttributes['assigned_user_id'] = $actor->id;
                            $conversationAttributes['responder_name'] = $actor->name;
                        }
                    } else {
                        $conversationAttributes['responder_name'] = $actor->name;
                    }

                    $conversationAttributes['responder_type'] = ConversationResponderService::TYPE_HUMAN;
                    $conversationAttributes['responder_assigned_at'] = $conversation->responder_assigned_at ?? now();
                    $conversationAttributes['handoff_status'] = ConversationHandoffStatus::Active->value;
                    $conversationAttributes['ai_enabled'] = false;
                    $metadata['human_handoff'] = true;
                    $metadata['human_handoff_status'] = 'active';
                    $conversationAttributes['metadata'] = $metadata;

                    Cache::forget("conversation:{$conversation->id}:active_typing");
                }
                $conversation->update($conversationAttributes);

                if ($conversation->customer) {
                    $conversation->customer->update([
                        'last_conversation_at' => $message->created_at,
                    ]);
                }

                return $message->load('attachments');
            });

            if (! $message->wasRecentlyCreated) {
                return $message;
            }

            if ($message->direction === 'outbound') {
                $this->channelOutbound->queueOutboundForMessage(
                    $conversation->fresh() ?? $conversation,
                    $message,
                );
            }

            app(InboxHumanHandoffService::class)->maybeHandoffFromInboundMessage(
                $conversation->fresh() ?? $conversation,
                $message,
            );

            if ($message->direction === 'inbound' && $message->message_type === 'text') {
                app(ConversationClassificationService::class)->applySignals(
                    $conversation->fresh() ?? $conversation,
                    (string) $message->content,
                );
            }

            app(InboxRealtimePublisher::class)->publishNewMessage(
                $message,
                $conversation->fresh() ?? $conversation,
                ['provider' => 'web_inbox'],
            );

            if ($message->direction === 'outbound' && $actor instanceof User && ! (bool) ($data['ai_generated'] ?? false)) {
                // Track last time a human employee replied (used for idle-handoff auto-resume)
                $conversation->updateQuietly(['employee_last_replied_at' => $message->created_at ?? now()]);

                app(ConversationResponderService::class)->recordTransition(
                    $conversation->fresh() ?? $conversation,
                    ConversationResponderService::TYPE_HUMAN,
                    $actor,
                    'staff_reply',
                    'user',
                    $actor,
                    'رد الموظف على المحادثة',
                );
                app(InboxRealtimePublisher::class)->publishConversationUpdate($conversation->fresh() ?? $conversation);
            }

            return $message;
        };

        if ($workspaceId > 0 && app(WorkspaceContext::class)->workspaceId() !== $workspaceId) {
            return app(WorkspaceContext::class)->runForId($workspaceId, $runner);
        }

        return $runner();
    }

    public function toggleAi(Conversation $conversation, User $actor, bool $enabled): Conversation
    {
        if ($enabled) {
            return $this->handoff->resumeAi($conversation, $actor);
        }

        return $this->handoff->disableAi($conversation, $actor);
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    private function normalizeChannelPayload(array $data): array
    {
        $requestedChannel = $this->normalizeChannelName((string) ($data['channel'] ?? 'manual'));
        $metadata = is_array($data['metadata'] ?? null) ? $data['metadata'] : [];
        $metadata['channel_source'] = $requestedChannel;
        $metadata['unread_count'] = (int) ($metadata['unread_count'] ?? 0);

        $data['channel'] = in_array($requestedChannel, self::DB_CHANNELS, true) ? $requestedChannel : 'manual';
        $data['metadata'] = $metadata;

        return $data;
    }

    private function normalizeChannelName(string $channel): string
    {
        $normalized = strtolower(trim($channel));

        return match ($normalized) {
            'facebook', 'messenger', 'facebook-messenger' => 'facebook_messenger',
            'ig' => 'instagram',
            default => $normalized !== '' ? $normalized : 'manual',
        };
    }

    private function resolveDisplayChannel(Conversation $conversation): string
    {
        $metadata = is_array($conversation->metadata) ? $conversation->metadata : [];
        $source = $metadata['channel_source'] ?? $conversation->channel ?? 'manual';

        return $this->normalizeChannelName((string) $source);
    }
}
