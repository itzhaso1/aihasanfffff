<?php

namespace App\Notifications;

use App\Contracts\Email\CentralEmailNotification;
use App\Models\Finance\FinanceInvoice;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Notification;

class FinanceInvoiceNotification extends Notification implements ShouldQueue, CentralEmailNotification
{
    use Queueable;

    public function __construct(
        public readonly FinanceInvoice $invoice,
        public readonly string $title,
        public readonly string $message,
        public readonly string $eventType = 'invoice_event',
    ) {}

    /**
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
            'subject' => $this->title,
            'workspace_id' => $this->invoice->workspace_id,
            'data' => [
                'headline' => $this->title,
                'intro' => $this->message,
                'lines' => [
                    'رقم الفاتورة: '.$this->invoice->invoice_number,
                    'الإجمالي: '.number_format((float) $this->invoice->total, 2).' '.$this->invoice->currency,
                    'المتبقي: '.number_format((float) $this->invoice->amount_due, 2).' '.$this->invoice->currency,
                ],
                'action_text' => 'عرض الفاتورة',
                'action_url' => url('/workspace/finance/invoices/'.$this->invoice->id),
            ],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(object $notifiable): array
    {
        return [
            'type' => $this->eventType,
            'invoice_id' => $this->invoice->id,
            'invoice_number' => $this->invoice->invoice_number,
            'workspace_id' => $this->invoice->workspace_id,
            'title' => $this->title,
            'message' => $this->message,
        ];
    }
}
