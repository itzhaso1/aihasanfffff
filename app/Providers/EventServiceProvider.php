<?php

namespace App\Providers;

use App\Events\OrderCreated;
use App\Events\PaymentConfirmed;
use App\Listeners\SendOrderCreatedNotifications;
use App\Listeners\SendPaymentConfirmedNotifications;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\ServiceProvider;

class EventServiceProvider extends ServiceProvider
{
    /**
     * Register services.
     */
    public function register(): void
    {
        //
    }

    /**
     * Bootstrap services.
     */
    public function boot(): void
    {
        Event::listen(OrderCreated::class, SendOrderCreatedNotifications::class);
        Event::listen(PaymentConfirmed::class, SendPaymentConfirmedNotifications::class);
    }
}
