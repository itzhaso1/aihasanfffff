<?php

use App\Jobs\Stories\ExpireStoriesJob;
use App\Services\Subscription\SubscriptionLifecycleService;
use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

Schedule::job(new ExpireStoriesJob)->hourly()->withoutOverlapping(50)->onOneServer();

Artisan::command('subscriptions:expire-trials', function (SubscriptionLifecycleService $lifecycle) {
    $expired = $lifecycle->expireTrials();
    $this->info("Expired {$expired} trial subscriptions.");
})->purpose('Expire ended trials unless a verified paid checkout is waiting');

Artisan::command('subscriptions:process-renewals', function (SubscriptionLifecycleService $lifecycle) {
    $attempts = $lifecycle->processRenewals();
    $this->info("Created {$attempts} renewal payment attempts. No charges were made.");
})->purpose('Create renewal attempts and mark unpaid ended periods past_due');

Artisan::command('subscriptions:process-dunning', function (SubscriptionLifecycleService $lifecycle) {
    $expired = $lifecycle->processDunning();
    $this->info("Expired {$expired} past-due subscriptions after grace.");
})->purpose('Expire checkouts and past-due subscriptions after grace');

Schedule::command('subscriptions:expire-trials')->hourly()->withoutOverlapping(50)->onOneServer();
Schedule::command('subscriptions:process-renewals')->hourly()->withoutOverlapping(50)->onOneServer();
Schedule::command('subscriptions:process-dunning')->hourly()->withoutOverlapping(50)->onOneServer();
Schedule::command('worker:engagements:expire')->everyMinute()->withoutOverlapping(10)->onOneServer();
Schedule::command('backup:database')->everySixHours()->withoutOverlapping(120)->onOneServer();
Schedule::command('backup:verify-latest')->weeklyOn(1, '03:30')->withoutOverlapping(120)->onOneServer();
Schedule::command('ops:alerts:evaluate')->everyFiveMinutes()->withoutOverlapping(10)->onOneServer();
Schedule::command('ops:retention:webhook-events')->dailyAt('02:45')->withoutOverlapping(60)->onOneServer();
