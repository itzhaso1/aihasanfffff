<?php

namespace App\Listeners;

use App\Events\PaymentConfirmed;
use App\Services\Notification\DomainNotificationService;
use Illuminate\Contracts\Queue\ShouldQueue;

class SendPaymentConfirmedNotifications implements ShouldQueue
{
    public function __construct(private readonly DomainNotificationService $domainNotificationService) {}

    /**
     * Handle the event.
     */
    public function handle(PaymentConfirmed $event): void
    {
        $this->domainNotificationService->notifyPaymentConfirmed($event->payment);
    }
}
