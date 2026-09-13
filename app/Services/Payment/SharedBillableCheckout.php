<?php

namespace App\Services\Payment;

use App\Services\Payment\Contracts\BillableCheckoutPort;
use App\Services\Payment\Contracts\BillableCheckoutRequest;
use App\Services\Payment\Contracts\BillableCheckoutResult;
use RuntimeException;

/**
 * Shared checkout adapter: Finance invoices (and future non-Order billables)
 * go through PaymentService without creating POS Orders.
 */
class SharedBillableCheckout implements BillableCheckoutPort
{
    public function __construct(
        private readonly PaymentService $paymentService,
    ) {}

    public function createCheckout(BillableCheckoutRequest $request): BillableCheckoutResult
    {
        try {
            $payment = $this->paymentService->createBillablePaymentLink($request);
        } catch (RuntimeException $exception) {
            return BillableCheckoutResult::unsupported(
                'checkout_unavailable',
                $exception->getMessage()
            );
        }

        $url = trim((string) $payment->payment_link);
        if ($url === '') {
            return BillableCheckoutResult::unsupported(
                'checkout_link_missing',
                'لم تُرجع بوابة الدفع رابطاً صالحاً.'
            );
        }

        return BillableCheckoutResult::supported($url);
    }
}
