<?php

namespace App\Notifications;

use App\Contracts\Email\CentralEmailNotification;
use App\Models\Order;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Notification;

class NewOrderNotification extends Notification implements ShouldQueue, CentralEmailNotification
{
    use Queueable;

    public function __construct(public readonly Order $order) {}

    /**
     * Get the notification's delivery channels.
     *
     * @return array<int, string>
     */
    public function via(object $notifiable): array
    {
        return ['database', 'central_mail'];
    }

    /**
     * @return array<string, mixed>
     */
    public function toCentralEmail(object $notifiable): array
    {
        return [
            'template' => 'general_notification',
            'subject' => 'طلب جديد '.$this->order->order_number,
            'workspace_id' => $this->order->workspace_id,
            'data' => [
                'headline' => 'طلب جديد '.$this->order->order_number,
                'intro' => 'تم إنشاء طلب جديد بقيمة '.$this->order->total_amount.' '.$this->order->currency,
                'action_text' => 'عرض الطلبات',
                'action_url' => url('/workspace/orders'),
                'footer' => 'HASEM',
            ],
        ];
    }

    /**
     * Get the array representation of the notification.
     *
     * @return array<string, mixed>
     */
    public function toArray(object $notifiable): array
    {
        return [
            'type' => 'order_created',
            'order_id' => $this->order->id,
            'order_number' => $this->order->order_number,
            'amount' => $this->order->total_amount,
            'currency' => $this->order->currency,
            'workspace_id' => $this->order->workspace_id,
        ];
    }
}
