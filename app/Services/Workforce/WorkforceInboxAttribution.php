<?php

declare(strict_types=1);

namespace App\Services\Workforce;

use App\Enums\WorkforceParticipantType;
use App\Models\Conversation;
use App\Models\Message;

/**
 * Maps stored Inbox foreign keys to a participant type.
 *
 * This is not authorization and does not inspect display names, emails,
 * avatars, or the current engagement list for a worker.
 */
final class WorkforceInboxAttribution
{
    public static function typeForConversation(Conversation $conversation): ?WorkforceParticipantType
    {
        if ($conversation->assigned_worker_engagement_id !== null) {
            return WorkforceParticipantType::IndependentWorker;
        }

        if ($conversation->assigned_user_id !== null) {
            return WorkforceParticipantType::CompanyStaff;
        }

        if ($conversation->ai_employee_id !== null) {
            return WorkforceParticipantType::AiEmployee;
        }

        return null;
    }

    public static function typeForMessage(Message $message): ?WorkforceParticipantType
    {
        if ($message->worker_engagement_id !== null) {
            return WorkforceParticipantType::IndependentWorker;
        }

        if ($message->ai_generated) {
            return WorkforceParticipantType::AiEmployee;
        }

        if ($message->user_id !== null) {
            return WorkforceParticipantType::CompanyStaff;
        }

        return null;
    }

    /**
     * Historical independent-worker context stored on the record.
     * Returns null when the record has no stored engagement reference.
     */
    public static function storedEngagementId(Conversation|Message $record): ?int
    {
        if ($record instanceof Conversation) {
            return $record->assigned_worker_engagement_id !== null
                ? (int) $record->assigned_worker_engagement_id
                : null;
        }

        return $record->worker_engagement_id !== null
            ? (int) $record->worker_engagement_id
            : null;
    }
}
