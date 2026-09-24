<?php

use App\Jobs\Stories\ExpireStoriesJob;
use App\Services\Finance\BillingScheduleService;
use App\Services\Finance\InvoiceReminderService;
use App\Services\Finance\InvoiceService;
use App\Services\Subscription\SubscriptionLifecycleService;
use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

Artisan::command('finance:invoices:refresh-payment-status', function (InvoiceService $service) {
    $updated = $service->refreshIssuedPaymentStatuses();
    $this->info("تم تحديث {$updated} فاتورة لحالة الدفع الفعلية.");
})->purpose('Recalculate issued invoices payment status and overdue state');

Artisan::command('finance:invoices:send-reminders', function (InvoiceReminderService $service) {
    $counts = $service->dispatchDueReminders();
    $this->info(sprintf(
        'تذكيرات الفواتير: قادمة %d، مستحقة اليوم %d، متأخرة %d.',
        $counts['upcoming'],
        $counts['due'],
        $counts['overdue']
    ));
})->purpose('Send upcoming, due-today, and overdue finance invoice reminders');

Artisan::command('finance:billing-schedules:generate', function (BillingScheduleService $service) {
    $generated = $service->generateDueInvoices();
    $this->info("تم توليد {$generated} فاتورة من جداول الفوترة المستحقة.");
})->purpose('Generate due finance invoices from activated billing schedules');

Artisan::command('finance:invoices:status-audit', function () {
    $rows = DB::table('finance_invoices')
        ->selectRaw('status, COUNT(*) as total')
        ->groupBy('status')
        ->orderBy('status')
        ->get();

    if ($rows->isEmpty()) {
        $this->warn('لا توجد فواتير حالية لتحليل الحالات.');

        return;
    }

    $this->line('Legacy status distribution:');
    foreach ($rows as $row) {
        $this->line(sprintf('- %s: %d', $row->status, $row->total));
    }
})->purpose('Show current finance_invoices legacy status counts before migration');

Artisan::command('finance:invoices:integrity-report', function () {
    $invoiceCount = DB::table('finance_invoices')->count();
    $paymentCount = DB::table('finance_invoice_payments')->count();
    $total = (float) DB::table('finance_invoices')->sum('total');
    $paid = (float) DB::table('finance_invoices')->sum('amount_paid');
    $due = (float) DB::table('finance_invoices')->sum('amount_due');

    $this->line('Invoice integrity metrics:');
    $this->line(sprintf('- invoices_count: %d', $invoiceCount));
    $this->line(sprintf('- payments_count: %d', $paymentCount));
    $this->line(sprintf('- totals_sum: %.2f', $total));
    $this->line(sprintf('- amount_paid_sum: %.2f', $paid));
    $this->line(sprintf('- amount_due_sum: %.2f', $due));
})->purpose('Output baseline invoice counters and totals for migration integrity checks');

Schedule::command('finance:invoices:refresh-payment-status')->hourly()->withoutOverlapping(50)->onOneServer();
Schedule::command('finance:invoices:send-reminders')->dailyAt('08:00')->withoutOverlapping(120)->onOneServer();
Schedule::command('finance:billing-schedules:generate')->dailyAt('01:15')->withoutOverlapping(120)->onOneServer();
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
Schedule::command('backup:database')->everySixHours()->withoutOverlapping(120)->onOneServer();
Schedule::command('backup:verify-latest')->weeklyOn(1, '03:30')->withoutOverlapping(120)->onOneServer();
Schedule::command('ops:alerts:evaluate')->everyFiveMinutes()->withoutOverlapping(10)->onOneServer();
Schedule::command('ops:retention:webhook-events')->dailyAt('02:45')->withoutOverlapping(60)->onOneServer();
