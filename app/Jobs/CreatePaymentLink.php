<?php

namespace App\Jobs;

use App\Services\Payment\PaymentService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

class CreatePaymentLink implements ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    public int $timeout = 120;

    public function __construct(public readonly int $orderId) {}

    /**
     * Execute the job.
     */
    public function handle(PaymentService $paymentService): void
    {
        unset($paymentService);
    }
}
