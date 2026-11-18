<?php

namespace App\Services\Conversation;

use App\Enums\ConversationHandoffStatus;
use App\Models\Conversation;
use App\Models\ConversationResponderLog;
use App\Models\User;
use App\Models\Workspace;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

class ConversationResponderService
{
    public const TYPE_AI = 'ai';

    public const TYPE_HUMAN = 'human';

    public const TYPE_NONE = 'none';

    public const STATUS_AI_ACTIVE = 'ai_active';

    public const STATUS_HUMAN_ACTIVE = 'human_active';

    public const STATUS_HUMAN_NEEDED = 'human_needed';

    public const STATUS_WAITING = 'waiting';

    public const STATUS_OFFLINE = 'offline';

    /**
     * Determine the real-time responder information for a conversation.
     *
     * @return array{
     *     status: string,
     *     type: string,
     *     name: string,
     *     label: string,
     *     badge_class: string,
     *     dot_class: string,
     *     is_ai_generating: bool,
     *     user_id: int|null,
     *     user_initials: string|null,
     *     assigned_at: string|null,
     *     assigned_at_human: string|null,
     *     reason: string|null
     * }
     */
    public function determineResponder(Conversation $conversation): array
    {
        if (! $conversation->isOpen()) {
            return [
                'status' => self::STATUS_OFFLINE,
                'type' => self::TYPE_NONE,
                'name' => 'غير متصل',
                'label' => 'غير متصل',
                'badge_class' => 'border-slate-200 bg-slate-50 text-slate-500',
                'dot_class' => 'bg-slate-300',
                'is_ai_generating' => false,
                'user_id' => null,
                'user_initials' => null,
                'assigned_at' => null,
                'assigned_at_human' => null,
                'reason' => 'المحادثة مغلقة أو غير نشطة',
            ];
        }

        $handoffStatus = $conversation->handoffStatusEnum();
        $isAiGenerating = (bool) Cache::get("conversation:{$conversation->id}:ai_generating", false);

        // 1. Human Needed (Handed off from AI, awaiting human pickup)
        if (in_array($handoffStatus, [ConversationHandoffStatus::Requested, ConversationHandoffStatus::Queued], true)) {
            return [
                'status' => self::STATUS_HUMAN_NEEDED,
                'type' => self::TYPE_NONE,
                'name' => 'مشعل AI (تحويل)',
                'label' => 'تحتاج تدخل بشري',
                'badge_class' => 'border-amber-300 bg-amber-50 text-amber-900',
                'dot_class' => 'bg-amber-500 animate-pulse',
                'is_ai_generating' => false,
                'user_id' => null,
                'user_initials' => '!',
                'assigned_at' => $conversation->handoff_requested_at?->toIso8601String(),
                'assigned_at_human' => $conversation->handoff_requested_at?->diffForHumans(),
                'reason' => $conversation->handoff_reason?->value ?? 'طلب تحويل إلى موظف',
            ];
        }

        // 2. Human Active (Assigned to employee or staff member)
        if (
            in_array($handoffStatus, [ConversationHandoffStatus::Assigned, ConversationHandoffStatus::Active], true)
            || ($conversation->assigned_user_id !== null && ! (bool) $conversation->ai_enabled)
        ) {
            $isWorkerAssigned = $conversation->assigned_worker_engagement_id !== null;
            if ($isWorkerAssigned) {
                $name = 'فريق العمل';
                $user = null;
            } else {
                $user = $conversation->relationLoaded('assignedUser')
                    ? $conversation->assignedUser
                    : $conversation->assignedUser()->first();
                $name = $user?->name ?? $conversation->responder_name ?? 'موظف خدمة العملاء';
            }
            $assignedAt = $conversation->responder_assigned_at ?? $conversation->handoff_assigned_at ?? $conversation->updated_at;

            return [
                'status' => self::STATUS_HUMAN_ACTIVE,
                'type' => self::TYPE_HUMAN,
                'name' => $name,
                'label' => 'الموظف يرد الآن',
                'badge_class' => 'border-blue-200/90 bg-blue-50 text-blue-800',
                'dot_class' => 'bg-blue-500',
                'is_ai_generating' => false,
                'user_id' => $user?->id ?? $conversation->assigned_user_id,
                'user_initials' => mb_substr($name, 0, 1),
                'assigned_at' => $assignedAt?->toIso8601String(),
                'assigned_at_human' => $assignedAt?->diffForHumans(),
                'reason' => null,
            ];
        }

        // 3. AI Active (Meshal AI in charge)
        if ((bool) $conversation->ai_enabled && $handoffStatus->allowsAiAutoReply()) {
            $aiName = $conversation->aiEmployee?->name ?? 'مشعل';

            return [
                'status' => self::STATUS_AI_ACTIVE,
                'type' => self::TYPE_AI,
                'name' => $aiName.' AI',
                'label' => $isAiGenerating ? 'AI يكتب رداً...' : 'AI يرد الآن',
                'badge_class' => 'border-emerald-200/90 bg-emerald-50 text-emerald-800',
                'dot_class' => $isAiGenerating ? 'bg-emerald-500 animate-ping' : 'bg-emerald-500',
                'is_ai_generating' => $isAiGenerating,
                'user_id' => null,
                'user_initials' => 'AI',
                'assigned_at' => $conversation->created_at?->toIso8601String(),
                'assigned_at_human' => $conversation->created_at?->diffForHumans(),
                'reason' => null,
            ];
        }

        // 4. Waiting for Reply (Neither AI nor employee is assigned or auto-replying)
        return [
            'status' => self::STATUS_WAITING,
            'type' => self::TYPE_NONE,
            'name' => '—',
            'label' => 'بانتظار الرد',
            'badge_class' => 'border-slate-200 bg-slate-100 text-slate-700',
            'dot_class' => 'bg-slate-400',
            'is_ai_generating' => false,
            'user_id' => null,
            'user_initials' => '—',
            'assigned_at' => null,
            'assigned_at_human' => null,
            'reason' => 'الذكاء الاصطناعي متوقف وبانتظار تعيين موظف',
        ];
    }

    /**
     * Record a transition in responder responsibility and update conversation state.
     */
    public function recordTransition(
        Conversation $conversation,
        string $newType,
        ?User $newUser,
        string $action,
        string $actorType = 'system',
        ?User $actor = null,
        ?string $reason = null,
        ?array $metadata = null,
    ): ConversationResponderLog {
        return DB::transaction(function () use (
            $conversation,
            $newType,
            $newUser,
            $action,
            $actorType,
            $actor,
            $reason,
            $metadata,
        ): ConversationResponderLog {
            $fresh = $conversation->fresh() ?? $conversation;

            $previousType = $fresh->responder_type ?? (
                $fresh->assigned_user_id !== null ? self::TYPE_HUMAN : ($fresh->ai_enabled ? self::TYPE_AI : self::TYPE_NONE)
            );
            $previousUserId = $fresh->assigned_user_id;
            $previousName = $fresh->responder_name ?? ($previousType === self::TYPE_AI ? 'مشعل AI' : ($fresh->assignedUser?->name ?? '—'));

            $newName = match ($newType) {
                self::TYPE_AI => ($fresh->aiEmployee?->name ?? 'مشعل').' AI',
                self::TYPE_HUMAN => $newUser?->name ?? 'موظف خدمة العملاء',
                default => '—',
            };

            $log = ConversationResponderLog::query()->create([
                'workspace_id' => $fresh->workspace_id,
                'conversation_id' => $fresh->id,
                'previous_responder_type' => $previousType,
                'previous_user_id' => $previousUserId,
                'previous_responder_name' => $previousName,
                'new_responder_type' => $newType,
                'new_user_id' => $newUser?->id,
                'new_responder_name' => $newName,
                'action' => $action,
                'actor_type' => $actorType,
                'actor_id' => $actor?->id,
                'reason' => $reason,
                'metadata' => $metadata,
                'created_at' => now(),
            ]);

            $fresh->update([
                'responder_type' => $newType,
                'responder_name' => $newName,
                'responder_assigned_at' => now(),
            ]);

            return $log;
        });
    }

    /**
     * Get the audit log of responder transitions for a conversation.
     *
     * @return Collection<int, ConversationResponderLog>
     */
    public function getLogs(Conversation $conversation): Collection
    {
        return ConversationResponderLog::query()
            ->where('conversation_id', $conversation->id)
            ->with(['actor:id,name,email', 'newUser:id,name', 'previousUser:id,name'])
            ->orderBy('id', 'desc')
            ->limit(100)
            ->get();
    }

    /**
     * Calculate live monitoring stats and active staff roster for a workspace.
     *
     * @return array{
     *     counts: array{
     *         total_open: int,
     *         ai_count: int,
     *         human_count: int,
     *         human_needed_count: int,
     *         waiting_count: int,
     *         closed_count: int
     *     },
     *     active_employees: list<array<string, mixed>>,
     *     conversations: list<array<string, mixed>>
     * }
     */
    public function getLiveMonitoringStats(Workspace $workspace): array
    {
        $workspaceId = (int) $workspace->id;

        $openConversations = Conversation::query()
            ->where('workspace_id', $workspaceId)
            ->where('status', 'open')
            ->with([
                'customer:id,name,phone',
                'assignedUser:id,name,email',
                'aiEmployee:id,name',
                'messages' => fn ($q) => $q->latest('id')->limit(1),
            ])
            ->orderByRaw('COALESCE(last_message_at, updated_at, created_at) DESC')
            ->get();

        $aiCount = 0;
        $humanCount = 0;
        $humanNeededCount = 0;
        $waitingCount = 0;

        $staffAssignedMap = [];

        $mappedConversations = [];

        foreach ($openConversations as $conv) {
            $resp = $this->determineResponder($conv);

            match ($resp['status']) {
                self::STATUS_AI_ACTIVE => $aiCount++,
                self::STATUS_HUMAN_ACTIVE => $humanCount++,
                self::STATUS_HUMAN_NEEDED => $humanNeededCount++,
                default => $waitingCount++,
            };

            if ($resp['status'] === self::STATUS_HUMAN_ACTIVE && $resp['user_id']) {
                $uid = (int) $resp['user_id'];
                if (! isset($staffAssignedMap[$uid])) {
                    $staffAssignedMap[$uid] = [];
                }
                $staffAssignedMap[$uid][] = [
                    'conversation_id' => $conv->id,
                    'customer_name' => $conv->customer?->name ?? 'عميل',
                    'last_message_at' => $conv->last_message_at?->format('h:i A') ?? '',
                ];
            }

            $latestMsg = $conv->messages->first();

            $mappedConversations[] = [
                'id' => $conv->id,
                'customer_name' => $conv->customer?->name ?? 'عميل بدون اسم',
                'customer_phone' => $conv->customer?->phone ?? '—',
                'channel' => $conv->channel,
                'last_message' => $latestMsg?->content ?? '',
                'last_message_at' => $conv->last_message_at?->format('h:i A') ?? '',
                'last_activity_human' => $conv->last_message_at?->diffForHumans() ?? $conv->updated_at?->diffForHumans() ?? '',
                'responder' => $resp,
            ];
        }

        $closedCount = Conversation::query()
            ->where('workspace_id', $workspaceId)
            ->whereIn('status', ['closed', 'archived'])
            ->count();

        // Build active employees list
        $employees = $workspace->users()
            ->wherePivot('status', 'active')
            ->get(['users.id', 'users.name', 'users.email']);

        $activeEmployees = [];
        foreach ($employees as $employee) {
            $assigned = $staffAssignedMap[$employee->id] ?? [];
            $activeEmployees[] = [
                'id' => $employee->id,
                'name' => $employee->name,
                'email' => $employee->email,
                'role' => $employee->pivot?->membership_role ?? 'عضو',
                'assigned_count' => count($assigned),
                'assigned_conversations' => $assigned,
                'is_handling' => count($assigned) > 0,
            ];
        }

        // Sort employees: those currently handling conversations first
        usort($activeEmployees, fn ($a, $b) => $b['assigned_count'] <=> $a['assigned_count']);

        return [
            'counts' => [
                'total_open' => $openConversations->count(),
                'ai_count' => $aiCount,
                'human_count' => $humanCount,
                'human_needed_count' => $humanNeededCount,
                'waiting_count' => $waitingCount,
                'closed_count' => $closedCount,
            ],
            'active_employees' => $activeEmployees,
            'conversations' => $mappedConversations,
        ];
    }
}
