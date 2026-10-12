<?php

namespace App\Services\Conversation;

use App\Enums\ConversationHandoffStatus;
use App\Models\AiSetting;
use App\Models\Conversation;
use App\Models\Message;

/**
 * Canonical guard: may AI respond to this conversation right now?
 */
final class ConversationAiEligibilityService
{
    public function mayRespond(Conversation $conversation, ?Message $incomingMessage = null): bool
    {
        $fresh = $conversation->fresh() ?? $conversation;

        if (! in_array($fresh->status, ['open'], true)) {
            return false;
        }

        if (! (bool) $fresh->ai_enabled) {
            return false;
        }

        if ($this->handoffStatus($fresh)->blocksAi()) {
            return false;
        }

        if (! $this->workspaceAiSettingActive((int) $fresh->workspace_id)) {
            return false;
        }

        if ($incomingMessage !== null) {
            if ((int) $incomingMessage->workspace_id !== (int) $fresh->workspace_id) {
                return false;
            }

            if ((int) $incomingMessage->conversation_id !== (int) $fresh->id) {
                return false;
            }

            if ($incomingMessage->direction !== 'inbound') {
                return false;
            }
        }

        return true;
    }

    public function shouldDispatchAiJob(Conversation $conversation, Message $incomingMessage): bool
    {
        return $this->mayRespond($conversation, $incomingMessage);
    }

    private function handoffStatus(Conversation $conversation): ConversationHandoffStatus
    {
        $value = $conversation->handoff_status;
        if ($value instanceof ConversationHandoffStatus) {
            return $value;
        }

        if (is_string($value) && $value !== '') {
            return ConversationHandoffStatus::tryFrom($value) ?? ConversationHandoffStatus::None;
        }

        $metadata = is_array($conversation->metadata) ? $conversation->metadata : [];
        if (($metadata['human_handoff'] ?? false) === true && ! $conversation->ai_enabled) {
            return ConversationHandoffStatus::Queued;
        }

        return ConversationHandoffStatus::None;
    }

    private function workspaceAiSettingActive(int $workspaceId): bool
    {
        $setting = AiSetting::withoutGlobalScopes()
            ->where('workspace_id', $workspaceId)
            ->first();

        if ($setting === null) {
            return true;
        }

        return (bool) $setting->is_active;
    }
}
