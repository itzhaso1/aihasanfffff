<?php

namespace App\Listeners;

use App\Events\OrderCreated;
use App\Services\Notification\DomainNotificationService;
use Illuminate\Contracts\Queue\ShouldQueue;

class SendOrderCreatedNotifications implements ShouldQueue
{
    public function __construct(private readonly DomainNotificationService $domainNotificationService) {}

    /**
     * Handle the event.
     */
    public function handle(OrderCreated $event): void
    {
        $this->domainNotificationService->notifyOrderCreated($event->order);
    }
}
