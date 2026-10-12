<?php

namespace App\Notifications;

use App\Contracts\Email\CentralEmailNotification;
use App\Models\Payment;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Notification;

class PaymentConfirmedNotification extends Notification implements CentralEmailNotification, ShouldQueue
{
    use Queueable;

    public function __construct(public readonly Payment $payment) {}

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
            'template' => 'finance_notification',
            'subject' => 'تم تأكيد عملية الدفع',
            'workspace_id' => $this->payment->workspace_id,
            'data' => [
                'headline' => 'تم تأكيد عملية الدفع',
                'intro' => 'تم تأكيد دفعة للمرجع '.$this->paymentReference(),
                'lines' => [
                    'المبلغ: '.$this->payment->amount.' '.$this->payment->currency,
                ],
                'action_text' => 'عرض المدفوعات',
                'action_url' => url('/workspace/payments'),
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
            'type' => 'payment_confirmed',
            'payment_id' => $this->payment->id,
            'order_id' => $this->payment->order_id,
            'billable_type' => $this->payment->billable_type,
            'billable_id' => $this->payment->billable_id,
            'amount' => $this->payment->amount,
            'currency' => $this->payment->currency,
            'workspace_id' => $this->payment->workspace_id,
        ];
    }

    private function paymentReference(): string
    {
        $orderNumber = $this->payment->order?->order_number;
        if (is_string($orderNumber) && $orderNumber !== '') {
            return $orderNumber;
        }

        $checkoutReference = (string) ($this->payment->checkout_reference ?? '');
        if ($checkoutReference !== '') {
            return $checkoutReference;
        }

        return '#'.$this->payment->id;
    }
}
