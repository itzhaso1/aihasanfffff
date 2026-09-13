<?php

namespace App\Notifications;

use App\Contracts\Email\CentralEmailNotification;
use App\Models\Contract\Contract;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Notification;

class ContractLifecycleNotification extends Notification implements ShouldQueue, CentralEmailNotification
{
    use Queueable;

    public function __construct(
        public readonly Contract $contract,
        public readonly string $title,
        public readonly string $message,
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
            'workspace_id' => $this->contract->workspace_id,
            'data' => [
                'headline' => $this->title,
                'intro' => $this->message,
                'lines' => [
                    'رقم العقد: '.$this->contract->contract_number,
                    'العنوان: '.$this->contract->title,
                    'القيمة: '.number_format((float) $this->contract->value, 2).' '.$this->contract->currency,
                ],
                'action_text' => 'عرض العقد',
                'action_url' => url('/workspace/finance/contracts/'.$this->contract->id),
            ],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(object $notifiable): array
    {
        return [
            'type' => 'contract_event',
            'contract_id' => $this->contract->id,
            'contract_number' => $this->contract->contract_number,
            'workspace_id' => $this->contract->workspace_id,
            'title' => $this->title,
            'message' => $this->message,
        ];
    }
}
