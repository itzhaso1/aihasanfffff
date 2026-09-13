<?php

namespace App\Notifications;

use App\Contracts\Email\CentralEmailNotification;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Notification;

class LowStockNotification extends Notification implements ShouldQueue, CentralEmailNotification
{
    use Queueable;

    public function __construct(
        public readonly string $productName,
        public readonly int $stock,
    ) {}

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
            'subject' => 'تنبيه انخفاض المخزون',
            'data' => [
                'headline' => 'تنبيه انخفاض المخزون',
                'intro' => 'المنتج '.$this->productName.' وصل إلى مخزون منخفض ('.$this->stock.').',
                'action_text' => 'عرض المنتجات',
                'action_url' => url('/workspace/products'),
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
            'type' => 'low_stock',
            'product_name' => $this->productName,
            'stock' => $this->stock,
        ];
    }
}
