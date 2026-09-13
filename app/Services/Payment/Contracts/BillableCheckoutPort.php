<?php

namespace App\Services\Payment\Contracts;

/**
 * Shared checkout boundary for non-Order billables (Finance invoices, etc.).
 *
 * Order POS/commerce checkout remains PaymentService::createPaymentLink(Order).
 * Non-Order billables use createBillablePaymentLink() and webhooks settle the
 * stored Payment row, then the owning product (Finance) posts through its
 * own payment service. Do not create dummy POS orders.
 */
interface BillableCheckoutPort
{
    public function createCheckout(BillableCheckoutRequest $request): BillableCheckoutResult;
}
