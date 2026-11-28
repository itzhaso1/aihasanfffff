<?php

declare(strict_types=1);

namespace App\Notifications;

use App\Models\Booking;
use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Notification;
use Illuminate\Support\Facades\Route;

class BookingEventNotification extends Notification
{
    use Queueable;

    public function __construct(
        public readonly Booking $booking,
        public readonly string $eventType,
    ) {}

    /**
     * @return list<string>
     */
    public function via(object $notifiable): array
    {
        return ['database'];
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(object $notifiable): array
    {
        $title = match ($this->eventType) {
            'created' => 'حجز جديد #'.$this->booking->booking_reference,
            'confirmed' => 'تأكيد حجز #'.$this->booking->booking_reference,
            'rescheduled' => 'إعادة جدولة حجز #'.$this->booking->booking_reference,
            'cancelled' => 'إلغاء حجز #'.$this->booking->booking_reference,
            'updated' => 'تعديل حجز #'.$this->booking->booking_reference,
            default => 'تحديث حجز #'.$this->booking->booking_reference,
        };

        return [
            'title' => $title,
            'body' => $this->booking->customer_name.' — '.$this->booking->service_name.' ('.$this->booking->booking_date?->format('Y-m-d').' '.$this->booking->start_time.')',
            'workspace_id' => $this->booking->workspace_id,
            'booking_id' => $this->booking->id,
            'booking_reference' => $this->booking->booking_reference,
            'event_type' => $this->eventType,
            'url' => Route::has('workspace.bookings.show')
                ? route('workspace.bookings.show', $this->booking)
                : url('/workspace/bookings/'.$this->booking->id),
        ];
    }
}
