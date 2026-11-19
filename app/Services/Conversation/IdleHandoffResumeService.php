<?php

declare(strict_types=1);

namespace App\Services\Conversation;

use App\Enums\ConversationHandoffStatus;
use App\Models\Conversation;
use App\Models\Message;
use App\Models\User;
use App\Services\Inbox\InboxRealtimePublisher;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

/**
 * Automatically resumes AI on conversations where the assigned employee
 * has been idle for too long (no reply and no active presence).
 *
 * Idle = no outbound human message AND no live presence heartbeat for
 * the configured number of minutes.
 */
final class IdleHandoffResumeService
{
    /**
     * Minutes of inactivity before AI is auto-resumed.
     * Configurable via IDLE_HANDOFF_RESUME_MINUTES env var.
     */
    public function idleThresholdMinutes(): int
    {
        return max(1, (int) config('hasim.idle_handoff_resume_minutes', 5));
    }

    /**
     * Find and resume AI for all idle handoff conversations across all workspaces.
     *
     * @return int Number of conversations auto-resumed
     */
    public function resumeIdleConversations(): int
    {
        $threshold = now()->subMinutes($this->idleThresholdMinutes());
        $resumed = 0;

        // Active handoff statuses that block AI
        $activeStatuses = [
            ConversationHandoffStatus::Assigned->value,
            ConversationHandoffStatus::Active->value,
            ConversationHandoffStatus::Queued->value,
            ConversationHandoffStatus::Requested->value,
        ];

        // Chunk through all open conversations with an active handoff
        Conversation::withoutGlobalScopes()
            ->where('status', 'open')
            ->whereNull('deleted_at')
            ->whereIn('handoff_status', $activeStatuses)
            ->where(function ($query) use ($threshold): void {
                // Either employee never replied, or last reply was before threshold
                $query->whereNull('employee_last_replied_at')
                    ->orWhere('employee_last_replied_at', '<=', $threshold);
            })
            ->orderBy('id')
            ->chunk(50, function ($conversations) use ($threshold, &$resumed): void {
                foreach ($conversations as $conversation) {
                    if ($this->isEmployeeActiveInConversation($conversation)) {
                        // Employee has a live presence heartbeat — skip
                        continue;
                    }

                    if (! $this->isEligibleForAutoResume($conversation, $threshold)) {
                        continue;
                    }

                    $this->performAutoResume($conversation);
                    $resumed++;
                }
            });

        return $resumed;
    }

    /**
     * Check if any employee is currently present (live heartbeat) in this conversation.
     */
    private function isEmployeeActiveInConversation(Conversation $conversation): bool
    {
        $presenceMap = Cache::get("conversation:{$conversation->id}:presence_map");

        if (! is_array($presenceMap) || empty($presenceMap)) {
            return false;
        }

        $ttl = ConversationPresenceService::PRESENCE_TTL_SECONDS;
        $now = now()->timestamp;

        foreach ($presenceMap as $entry) {
            $lastSeen = (int) ($entry['last_seen_at'] ?? 0);
            if (($now - $lastSeen) <= $ttl) {
                return true; // At least one employee is actively present
            }
        }

        return false;
    }

    /**
     * Final eligibility check: guard against races and ensure workspace AI is usable.
     */
    private function isEligibleForAutoResume(Conversation $conversation, Carbon $threshold): bool
    {
        // Re-check: conversation must still have an active handoff blocking AI
        $status = $conversation->handoffStatusEnum();
        if (! $status->blocksAi()) {
            return false;
        }

        // Double-check timing: employee_last_replied_at must still be old (or null)
        $lastReply = $conversation->employee_last_replied_at;
        if ($lastReply && $lastReply->gt($threshold)) {
            return false;
        }

        // If handoff_assigned_at is also newer than threshold, the assignment is fresh — skip
        $assignedAt = $conversation->handoff_assigned_at;
        if ($assignedAt && $assignedAt->gt($threshold)) {
            return false;
        }

        return true;
    }

    /**
     * Perform the actual AI resume and write an internal note to the conversation.
     */
    private function performAutoResume(Conversation $conversation): void
    {
        try {
            // Use a system actor: the workspace owner or a synthetic system user (id=1 fallback)
            $actor = $this->resolveSystemActor($conversation);

            app(ConversationHandoffService::class)->resumeAi($conversation, $actor);

            // Write an internal note visible only to staff
            $minutes = $this->idleThresholdMinutes();
            $this->writeInternalNote($conversation, $minutes);

            Log::info('[IdleHandoffResume] Auto-resumed AI', [
                'conversation_id' => $conversation->id,
                'workspace_id' => $conversation->workspace_id,
                'previous_handoff_status' => $conversation->handoff_status,
                'idle_minutes' => $minutes,
            ]);
        } catch (\Throwable $e) {
            Log::warning('[IdleHandoffResume] Failed to auto-resume AI', [
                'conversation_id' => $conversation->id,
                'error' => $e->getMessage(),
            ]);
        }
    }

    /**
     * Find the workspace owner to act as the system actor for the resume transition.
     * Falls back to the previously assigned user if owner is unavailable.
     */
    private function resolveSystemActor(Conversation $conversation): User
    {
        // Try the assigned user first (most contextually appropriate)
        if ($conversation->assigned_user_id) {
            $assigned = User::query()->find($conversation->assigned_user_id);
            if ($assigned) {
                return $assigned;
            }
        }

        // Fall back to workspace owner
        $owner = User::query()
            ->join('workspace_user', 'users.id', '=', 'workspace_user.user_id')
            ->where('workspace_user.workspace_id', $conversation->workspace_id)
            ->where('workspace_user.membership_role', 'owner')
            ->where('workspace_user.status', 'active')
            ->select('users.*')
            ->first();

        if ($owner) {
            return $owner;
        }

        // Last resort: any active member
        $member = User::query()
            ->join('workspace_user', 'users.id', '=', 'workspace_user.user_id')
            ->where('workspace_user.workspace_id', $conversation->workspace_id)
            ->where('workspace_user.status', 'active')
            ->select('users.*')
            ->first();

        if ($member) {
            return $member;
        }

        throw new \RuntimeException("No active workspace member found for workspace {$conversation->workspace_id}");
    }

    /**
     * Write a staff-only internal note informing the team that AI was auto-resumed.
     */
    private function writeInternalNote(Conversation $conversation, int $minutes): void
    {
        $content = "تم استئناف مشعل AI تلقائياً بعد {$minutes} دقيقة من عدم الرد من الموظف.";

        $message = Message::withoutGlobalScopes()->create([
            'workspace_id' => $conversation->workspace_id,
            'conversation_id' => $conversation->id,
            'customer_id' => $conversation->customer_id,
            'direction' => 'internal_note',
            'message_type' => 'system',
            'content' => $content,
            'ai_generated' => false,
            'metadata' => [
                'auto_resume_ai' => true,
                'idle_minutes' => $minutes,
                'visibility' => 'staff',
            ],
        ]);

        $conversation->updateQuietly(['last_message_at' => $message->created_at]);

        try {
            app(InboxRealtimePublisher::class)->publishNewMessage(
                $message,
                $conversation->fresh() ?? $conversation,
                ['origin' => 'idle_handoff_resume'],
            );
        } catch (\Throwable) {
            // Best-effort broadcast
        }
    }
}
