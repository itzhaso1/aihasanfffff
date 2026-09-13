<?php

namespace App\Jobs;

use App\Models\Order;
use App\Services\Payment\PaymentService;
use App\Support\Tenancy\WorkspaceContext;
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
        $order = Order::withoutGlobalScopes()->find($this->orderId);
        if (! $order) {
            return;
        }

        app(WorkspaceContext::class)->runForId((int) $order->workspace_id, function () use ($paymentService, $order): void {
            $paymentService->createPaymentLink($order);
        });
    }
}
