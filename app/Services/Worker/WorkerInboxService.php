<?php

declare(strict_types=1);

namespace App\Services\Worker;

use App\Enums\WorkerActivityEventType;
use App\Models\Conversation;
use App\Models\Message;
use App\Models\User;
use App\Models\Worker\WorkerEngagement;
use App\Models\Worker\WorkerProfile;
use App\Models\Workspace;
use App\Services\Audit\AuditLogService;
use App\Services\Conversation\ConversationService;
use App\Support\Tenancy\WorkspaceContext;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Http\Request;

final class WorkerInboxService
{
    public const READ_CAPABILITY = 'inbox.messages.read';

    public const SEND_CAPABILITY = 'inbox.messages.send';

    public function __construct(
        private readonly ConversationService $conversationService,
        private readonly WorkerEngagementAuthorization $engagementAuthorization,
        private readonly WorkerActivityEventService $activityEvents,
        private readonly AuditLogService $auditLog,
        private readonly WorkspaceContext $workspaceContext,
    ) {}

    /**
     * Engagements that currently grant this worker Inbox read in the workspace.
     *
     * @return list<int>
     */
    public function readEngagementIds(User $worker, Workspace $workspace): array
    {
        return $this->authorizedEngagements($worker, $workspace, [self::READ_CAPABILITY])
            ->map(fn (WorkerEngagement $engagement): int => (int) $engagement->id)
            ->values()
            ->all();
    }

    /**
     * @return Collection<int, Conversation>
     */
    public function conversations(User $worker, Workspace $workspace): Collection
    {
        return $this->workspaceContext->runFor($workspace, function () use ($worker, $workspace): Collection {
            $engagements = $this->authorizedEngagements($worker, $workspace, [self::READ_CAPABILITY]);

            if ($engagements->isEmpty()) {
                throw $this->conversationNotFound();
            }

            return Conversation::query()
                ->with($this->conversationRelations())
                ->where('status', '!=', 'archived')
                ->orderByDesc('last_message_at')
                ->orderByDesc('id')
                ->get();
        });
    }

    public function conversation(
        User $worker,
        Workspace $workspace,
        int $conversationId,
    ): Conversation {
        return $this->workspaceContext->runFor($workspace, function () use (
            $worker,
            $workspace,
            $conversationId,
        ): Conversation {
            [$conversation] = $this->resolveConversation(
                $worker,
                $workspace,
                $conversationId,
                [],
            );

            return $conversation;
        });
    }

    /**
     * @param  array{content:string, message_type?:string, idempotency_key?:string|null}  $data
     */
    public function sendMessage(
        User $worker,
        Workspace $workspace,
        int $conversationId,
        array $data,
        ?Request $request = null,
    ): Message {
        return $this->workspaceContext->runFor($workspace, function () use (
            $worker,
            $workspace,
            $conversationId,
            $data,
            $request,
        ): Message {
            [$conversation, $engagement] = $this->resolveConversation(
                $worker,
                $workspace,
                $conversationId,
                [self::SEND_CAPABILITY],
            );

            $idempotencyKey = trim((string) ($data['idempotency_key'] ?? ''));
            if ($idempotencyKey !== '') {
                $existing = $this->findMessageByIdempotencyKey($conversation, $idempotencyKey);
                if ($existing instanceof Message) {
                    return $existing->load([
                        'user:id,name',
                        'workerEngagement.workerProfile:id,display_name',
                    ]);
                }
            }

            if ($conversation->status !== 'open') {
                throw WorkerInboxException::conversationNotOpen();
            }

            $message = $this->conversationService->addMessage(
                $conversation,
                [
                    'customer_id' => $conversation->customer_id,
                    'direction' => 'outbound',
                    'message_type' => $data['message_type'] ?? 'text',
                    'content' => $data['content'],
                    'ai_generated' => false,
                    'worker_engagement_id' => $engagement->id,
                    'metadata' => $idempotencyKey === '' ? [] : ['idempotency_key' => $idempotencyKey],
                ],
                $worker,
            );

            $this->activityEvents->recordOperationalEvent(
                $engagement,
                $worker,
                WorkerActivityEventType::MessageSent,
                $message::class,
                (int) $message->id,
                [
                    'conversation_id' => $conversation->id,
                    'message_id' => $message->id,
                ],
                $request,
            );

            $this->auditLog->log(
                action: 'worker.inbox.message.sent',
                entityType: Message::class,
                entityId: $message->id,
                newValues: [
                    'conversation_id' => $conversation->id,
                    'worker_engagement_id' => $engagement->id,
                ],
                actor: $worker,
                request: $request,
                meta: [
                    'conversation_id' => $conversation->id,
                    'worker_engagement_id' => $engagement->id,
                    'worker_profile_id' => $engagement->worker_profile_id,
                ],
                workspaceId: $workspace->id,
            );

            return $message->load([
                'user:id,name',
                'workerEngagement.workerProfile:id,display_name',
            ]);
        });
    }

    /**
     * @param  list<string>  $additionalCapabilities
     * @return array{0: Conversation, 1: WorkerEngagement}
     */
    private function resolveConversation(
        User $worker,
        Workspace $workspace,
        int $conversationId,
        array $additionalCapabilities,
    ): array {
        $readEngagements = $this->authorizedEngagements(
            $worker,
            $workspace,
            [self::READ_CAPABILITY],
        );

        if ($readEngagements->isEmpty()) {
            throw $this->conversationNotFound($conversationId);
        }

        $conversation = Conversation::query()
            ->with($this->conversationRelations())
            ->whereKey($conversationId)
            ->where('status', '!=', 'archived')
            ->first();

        if ($conversation === null) {
            throw $this->conversationNotFound($conversationId);
        }

        $engagements = $additionalCapabilities === []
            ? $readEngagements
            : $this->authorizedEngagements($worker, $workspace, $additionalCapabilities);

        $engagement = $engagements->first();

        if (! $engagement instanceof WorkerEngagement) {
            throw new AuthorizationException('The worker is not authorized for this capability.');
        }

        return [$conversation, $engagement];
    }

    /**
     * @param  list<string>  $capabilities
     * @return Collection<int, WorkerEngagement>
     */
    private function authorizedEngagements(
        User $worker,
        Workspace $workspace,
        array $capabilities,
    ): Collection {
        $engagements = WorkerEngagement::withoutGlobalScopes()
            ->where('workspace_id', $workspace->id)
            ->where('status', 'active')
            ->whereHas('workerProfile', function (Builder $query) use ($worker): void {
                $query
                    ->where('user_id', $worker->id)
                    ->where('verification_status', WorkerProfile::VERIFICATION_APPROVED)
                    ->where('lifecycle_status', WorkerProfile::LIFECYCLE_VERIFIED);
            })
            ->with('workerProfile:id,user_id,display_name')
            ->orderBy('id')
            ->get();

        return $engagements
            ->filter(function (WorkerEngagement $engagement) use ($worker, $workspace, $capabilities): bool {
                foreach ($capabilities as $capability) {
                    if (! $this->engagementAuthorization->workerCan(
                        $worker,
                        $workspace,
                        $engagement,
                        $capability,
                    )) {
                        return false;
                    }
                }

                return true;
            })
            ->values();
    }

    /**
     * @return array<string, mixed>
     */
    private function conversationRelations(): array
    {
        return [
            'customer:id,name',
            'assignedUser:id,name',
            'assignedWorkerEngagement:id,workspace_id,worker_profile_id,status',
            'assignedWorkerEngagement.workerProfile:id,display_name',
            'aiEmployee:id,workspace_id,name,avatar_path,status',
            'messages' => function ($query): void {
                $query
                    ->select([
                        'id',
                        'workspace_id',
                        'conversation_id',
                        'customer_id',
                        'user_id',
                        'worker_engagement_id',
                        'direction',
                        'message_type',
                        'content',
                        'ai_generated',
                        'created_at',
                    ])
                    ->with([
                        'user:id,name',
                        'workerEngagement.workerProfile:id,display_name',
                    ])
                    ->latest('id')
                    ->limit(80);
            },
        ];
    }

    private function findMessageByIdempotencyKey(Conversation $conversation, string $key): ?Message
    {
        return Message::query()
            ->where('conversation_id', $conversation->id)
            ->where(function ($query) use ($key): void {
                $query->where('metadata->idempotency_key', $key)
                    ->orWhere('metadata->client_id', $key);
            })
            ->first();
    }

    private function conversationNotFound(?int $conversationId = null): ModelNotFoundException
    {
        return (new ModelNotFoundException)->setModel(
            Conversation::class,
            $conversationId !== null ? [$conversationId] : [],
        );
    }
}
