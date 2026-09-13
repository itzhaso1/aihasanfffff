<?php

namespace App\Services\Finance;

use App\Models\Finance\FinanceInvoice;
use App\Models\Payment;
use App\Models\Workspace;
use App\Services\Merchant\MerchantPaymentEligibilityService;
use App\Services\Payment\Contracts\BillableCheckoutPort;
use App\Services\Payment\Contracts\BillableCheckoutRequest;
use App\Services\Payment\Contracts\BillableCheckoutResult;
use App\Services\Payment\PaymentService;

/**
 * Finance-side checkout request. Does not own a gateway.
 * Confirmation must never be inferred from generating a URL.
 */
class InvoiceCheckoutService
{
    public function __construct(
        private readonly BillableCheckoutPort $billableCheckoutPort,
        private readonly PaymentService $paymentService,
        private readonly MerchantPaymentEligibilityService $merchantPaymentEligibilityService,
    ) {}

    public function availability(FinanceInvoice $invoice): BillableCheckoutResult
    {
        $blocked = $this->collectibility($invoice);
        if ($blocked !== null) {
            return $blocked;
        }

        $existing = $this->pendingPayment($invoice);
        if ($existing && filled($existing->payment_link)) {
            return BillableCheckoutResult::supported((string) $existing->payment_link);
        }

        $workspace = Workspace::query()->find($invoice->workspace_id);
        if ($workspace && ! $this->merchantPaymentEligibilityService->canAcceptCustomerPayments($workspace)) {
            $snapshot = $this->merchantPaymentEligibilityService->statusSnapshot($workspace);
            $message = implode(' ', $snapshot['blockers']);

            return BillableCheckoutResult::unsupported(
                'merchant_not_eligible',
                $message !== '' ? $message : 'لا يمكن إنشاء رابط دفع إلكتروني حالياً.'
            );
        }

        return BillableCheckoutResult::ready(
            'يمكن إنشاء رابط دفع إلكتروني لهذه الفاتورة. إنشاء الرابط لا يعني أن الفاتورة دُفعت.'
        );
    }

    public function createCheckout(FinanceInvoice $invoice): BillableCheckoutResult
    {
        $blocked = $this->collectibility($invoice);
        if ($blocked !== null) {
            return $blocked;
        }

        $existing = $this->pendingPayment($invoice);
        $due = round((float) $invoice->amount_due, 2);
        if (
            $existing
            && filled($existing->payment_link)
            && abs((float) $existing->amount - $due) <= InvoiceStateService::PAYMENT_TOLERANCE
        ) {
            return BillableCheckoutResult::supported((string) $existing->payment_link);
        }

        return $this->billableCheckoutPort->createCheckout($this->requestFor($invoice));
    }

    public function pendingPayment(FinanceInvoice $invoice): ?Payment
    {
        return $this->paymentService->findPendingBillablePayment(
            Payment::BILLABLE_FINANCE_INVOICE,
            (int) $invoice->id,
            (int) $invoice->workspace_id,
        );
    }

    private function collectibility(FinanceInvoice $invoice): ?BillableCheckoutResult
    {
        if ((string) $invoice->type !== 'sales') {
            return BillableCheckoutResult::unsupported(
                'not_sales_invoice',
                'رابط التحصيل متاح لفواتير المبيعات الصادرة فقط.'
            );
        }

        if ($invoice->trashed() || $invoice->isCancelled() || ! $invoice->isIssued()) {
            return BillableCheckoutResult::unsupported(
                'invoice_not_collectible',
                'لا يمكن إنشاء رابط دفع إلا لفاتورة مبيعات صادرة وغير ملغاة.'
            );
        }

        if ((float) $invoice->amount_due <= InvoiceStateService::PAYMENT_TOLERANCE) {
            return BillableCheckoutResult::unsupported(
                'invoice_paid',
                'لا يوجد مبلغ مستحق على هذه الفاتورة.'
            );
        }

        return null;
    }

    private function requestFor(FinanceInvoice $invoice): BillableCheckoutRequest
    {
        return new BillableCheckoutRequest(
            billableType: Payment::BILLABLE_FINANCE_INVOICE,
            billableId: (int) $invoice->id,
            workspaceId: (int) $invoice->workspace_id,
            reference: (string) $invoice->invoice_number,
            amount: round((float) $invoice->amount_due, 2),
            currency: strtoupper((string) ($invoice->currency ?: 'SAR')),
            metadata: [
                'invoice_id' => $invoice->id,
                'invoice_number' => $invoice->invoice_number,
                'customer_id' => $invoice->customer_id,
            ],
        );
    }
}
