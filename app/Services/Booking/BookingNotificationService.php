<?php

declare(strict_types=1);

namespace App\Services\Booking;

use App\Enums\BookingStatus;
use App\Models\Booking;
use App\Models\BookingReminder;
use App\Models\BookingSetting;
use App\Models\Conversation;
use App\Models\User;
use App\Notifications\BookingEventNotification;
use App\Services\Conversation\ConversationService;
use App\Support\Tenancy\WorkspaceContext;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Log;
use Throwable;

final class BookingNotificationService
{
    public function __construct(
        private readonly BookingSettingsService $settingsService,
        private readonly ConversationService $conversationService,
        private readonly WorkspaceContext $workspaceContext,
    ) {}

    public function syncUpcomingReminders(Booking $booking, BookingSetting $setting): void
    {
        $this->cancelPendingUpcomingReminders($booking);

        if (! $setting->reminder_enabled || ! $booking->statusEnum()->isOccupyingCapacity() || $booking->starts_at === null) {
            return;
        }

        $channel = $booking->channel ?: $booking->conversation?->channel ?: 'web';
        foreach ($setting->resolvedReminderOffsets() as $minutesBefore) {
            $scheduledFor = CarbonImmutable::instance($booking->starts_at)->subMinutes($minutesBefore)->utc();
            if ($scheduledFor->lessThanOrEqualTo(now()->subMinutes(5))) {
                continue;
            }

            BookingReminder::withoutGlobalScopes()->firstOrCreate(
                [
                    'booking_id' => $booking->id,
                    'reminder_type' => BookingReminder::TYPE_UPCOMING,
                    'scheduled_for' => $scheduledFor,
                ],
                [
                    'workspace_id' => $booking->workspace_id,
                    'channel' => $channel,
                    'status' => BookingReminder::STATUS_PENDING,
                ],
            );
        }
    }

    public function cancelPendingUpcomingReminders(Booking $booking): void
    {
        BookingReminder::withoutGlobalScopes()
            ->where('workspace_id', $booking->workspace_id)
            ->where('booking_id', $booking->id)
            ->where('reminder_type', BookingReminder::TYPE_UPCOMING)
            ->where('status', BookingReminder::STATUS_PENDING)
            ->update(['status' => BookingReminder::STATUS_SKIPPED]);
    }

    public function notifyWorkspaceTeam(Booking $booking, string $eventType): void
    {
        try {
            $this->workspaceContext->runForId((int) $booking->workspace_id, function () use ($booking, $eventType): void {
                $recipients = $booking->workspace->users()
                    ->wherePivotIn('membership_role', ['owner', 'admin', 'manager'])
                    ->get();

                foreach ($recipients as $recipient) {
                    if ($recipient instanceof User) {
                        $recipient->notify(new BookingEventNotification($booking, $eventType));
                    }
                }
            });
        } catch (Throwable $exception) {
            Log::warning('booking.team_notification_failed', [
                'booking_id' => $booking->id,
                'event_type' => $eventType,
                'error' => $exception->getMessage(),
            ]);
        }
    }

    public function sendEventMessageToCustomer(
        Booking $booking,
        string $eventType,
        ?User $actor = null,
        bool $sendToConversation = true,
    ): void {
        $this->workspaceContext->runForId((int) $booking->workspace_id, function () use ($booking, $eventType, $actor, $sendToConversation): void {
            $setting = $this->settingsService->forWorkspaceId((int) $booking->workspace_id);
            if (! $setting->notifications_enabled) {
                return;
            }

            $allowed = match ($eventType) {
                BookingReminder::TYPE_CONFIRMATION => (bool) $setting->send_confirmation_message,
                BookingReminder::TYPE_UPDATED => (bool) $setting->send_update_message,
                BookingReminder::TYPE_RESCHEDULED => (bool) $setting->send_reschedule_message,
                BookingReminder::TYPE_CANCELLED => (bool) $setting->send_cancellation_message,
                BookingReminder::TYPE_UPCOMING => (bool) $setting->reminder_enabled,
                default => false,
            };

            if (! $allowed || ! $sendToConversation) {
                return;
            }

            $conversation = $booking->conversation_id !== null
                ? Conversation::withoutGlobalScopes()
                    ->where('workspace_id', $booking->workspace_id)
                    ->whereKey($booking->conversation_id)
                    ->first()
                : null;

            if (! $conversation instanceof Conversation) {
                return;
            }

            $content = $this->buildCustomerMessage($booking, $eventType, $setting->timezone ?: 'Asia/Riyadh');
            $idempotencyKey = 'booking-notify:'.$booking->id.':'.$eventType.':'.($booking->updated_at?->timestamp ?? time());

            try {
                $message = $this->conversationService->addMessage($conversation, [
                    'workspace_id' => $booking->workspace_id,
                    'customer_id' => $booking->customer_id ?: $conversation->customer_id,
                    'direction' => 'outbound',
                    'message_type' => 'text',
                    'content' => $content,
                    'ai_generated' => true,
                    'metadata' => [
                        'idempotency_key' => $idempotencyKey,
                        'booking_id' => $booking->id,
                        'booking_reference' => $booking->booking_reference,
                        'booking_event' => $eventType,
                        'triggered_by_user_id' => $actor?->id,
                    ],
                ], null, dispatchSideEffects: true);

                BookingReminder::withoutGlobalScopes()->firstOrCreate(
                    [
                        'booking_id' => $booking->id,
                        'reminder_type' => $eventType,
                        'scheduled_for' => $booking->updated_at ?? now(),
                    ],
                    [
                        'workspace_id' => $booking->workspace_id,
                        'channel' => $conversation->channel,
                        'status' => BookingReminder::STATUS_SENT,
                        'sent_at' => now(),
                        'message_id' => $message->id,
                    ],
                );
            } catch (Throwable $exception) {
                Log::warning('booking.customer_notification_failed', [
                    'booking_id' => $booking->id,
                    'event_type' => $eventType,
                    'error' => $exception->getMessage(),
                ]);
            }
        });
    }

    public function sendDueReminders(?int $workspaceId = null): int
    {
        $sentCount = 0;

        $due = BookingReminder::withoutGlobalScopes()
            ->where('reminder_type', BookingReminder::TYPE_UPCOMING)
            ->where('status', BookingReminder::STATUS_PENDING)
            ->where('scheduled_for', '<=', now())
            ->when($workspaceId !== null, fn ($q) => $q->where('workspace_id', $workspaceId))
            ->orderBy('scheduled_for')
            ->limit(100)
            ->get();

        foreach ($due as $reminder) {
            $this->workspaceContext->runForId((int) $reminder->workspace_id, function () use ($reminder, &$sentCount): void {
                $booking = Booking::withoutGlobalScopes()
                    ->where('workspace_id', $reminder->workspace_id)
                    ->with(['branch', 'provider', 'service'])
                    ->find($reminder->booking_id);

                $setting = $this->settingsService->forWorkspaceId((int) $reminder->workspace_id);

                if (! $booking instanceof Booking
                    || ! $setting->notifications_enabled
                    || ! $setting->reminder_enabled
                    || ! $booking->statusEnum()->isOccupyingCapacity()
                    || ($booking->starts_at !== null && $booking->starts_at->isPast())) {
                    $reminder->forceFill(['status' => BookingReminder::STATUS_SKIPPED])->save();

                    return;
                }

                $conversation = $booking->conversation_id !== null
                    ? Conversation::withoutGlobalScopes()
                        ->where('workspace_id', $booking->workspace_id)
                        ->whereKey($booking->conversation_id)
                        ->first()
                    : null;

                if (! $conversation instanceof Conversation) {
                    $reminder->forceFill(['status' => BookingReminder::STATUS_SKIPPED])->save();

                    return;
                }

                $claimed = BookingReminder::withoutGlobalScopes()
                    ->whereKey($reminder->id)
                    ->where('status', BookingReminder::STATUS_PENDING)
                    ->update(['status' => 'processing']);

                if ($claimed === 0) {
                    return;
                }

                try {
                    $content = $this->buildCustomerMessage(
                        $booking,
                        BookingReminder::TYPE_UPCOMING,
                        $setting->timezone ?: 'Asia/Riyadh',
                    );

                    $message = $this->conversationService->addMessage($conversation, [
                        'workspace_id' => $booking->workspace_id,
                        'customer_id' => $booking->customer_id ?: $conversation->customer_id,
                        'direction' => 'outbound',
                        'message_type' => 'text',
                        'content' => $content,
                        'ai_generated' => true,
                        'metadata' => [
                            'idempotency_key' => 'booking-reminder:'.$reminder->id,
                            'booking_id' => $booking->id,
                            'booking_reference' => $booking->booking_reference,
                            'booking_event' => BookingReminder::TYPE_UPCOMING,
                        ],
                    ], null, dispatchSideEffects: true);

                    $reminder->forceFill([
                        'status' => BookingReminder::STATUS_SENT,
                        'sent_at' => now(),
                        'message_id' => $message->id,
                        'error_message' => null,
                    ])->save();
                    $sentCount++;
                } catch (Throwable $exception) {
                    $reminder->forceFill([
                        'status' => BookingReminder::STATUS_FAILED,
                        'error_message' => mb_substr($exception->getMessage(), 0, 240),
                    ])->save();
                }
            });
        }

        return $sentCount;
    }

    public function buildCustomerMessage(Booking $booking, string $eventType, string $timezone = 'Asia/Riyadh'): string
    {
        $date = $booking->booking_date?->format('Y-m-d')
            ?? $booking->starts_at?->copy()->setTimezone($timezone)->format('Y-m-d')
            ?? '';
        $time = $booking->start_time;

        $header = match ($eventType) {
            BookingReminder::TYPE_CONFIRMATION => $booking->status === BookingStatus::Pending->value
                ? 'تم تسجيل طلب الحجز بنجاح، وهو قيد المراجعة والتأكيد:'
                : 'تم تأكيد حجزك بنجاح:',
            BookingReminder::TYPE_UPDATED => 'تم تحديث بيانات حجزك:',
            BookingReminder::TYPE_RESCHEDULED => 'تمت إعادة جدولة حجزك إلى الموعد الجديد:',
            BookingReminder::TYPE_CANCELLED => 'تم إلغاء الحجز التالي بناءً على الطلب:',
            BookingReminder::TYPE_UPCOMING => 'تذكير لطيف بموعدك القادم:',
            default => 'تحديث بخصوص حجزك:',
        };

        $lines = [
            $header,
            'رقم الحجز: '.$booking->booking_reference,
            'الخدمة: '.$booking->service_name,
            'التاريخ: '.$date,
            'الوقت: '.$time.' - '.$booking->end_time,
        ];

        if ($booking->branch?->name) {
            $lines[] = 'الفرع: '.$booking->branch->name;
        }
        if ($booking->provider?->name) {
            $lines[] = 'المختص / المقدم: '.$booking->provider->name;
        }
        if ($booking->party_size !== null && $booking->party_size > 0) {
            $lines[] = 'عدد الأشخاص: '.$booking->party_size;
        }
        if ($eventType === BookingReminder::TYPE_CANCELLED && $booking->cancellation_reason) {
            $lines[] = 'السبب: '.$booking->cancellation_reason;
        }

        return implode("\n", $lines);
    }
}
