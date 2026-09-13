<?php

namespace App\Services\Notification;

use App\Models\Contract\Contract;
use App\Models\Finance\FinanceInvoice;
use App\Models\Order;
use App\Models\Payment;
use App\Models\User;
use App\Models\Workspace;
use App\Notifications\ContractLifecycleNotification;
use App\Notifications\FinanceInvoiceNotification;
use App\Notifications\LowStockNotification;
use App\Notifications\NewOrderNotification;
use App\Notifications\PaymentConfirmedNotification;

class DomainNotificationService
{
    public function notifyOrderCreated(Order $order): void
    {
        $recipients = $order->workspace->users()
            ->wherePivotIn('membership_role', ['owner', 'admin', 'manager'])
            ->get();

        /** @var User $recipient */
        foreach ($recipients as $recipient) {
            $recipient->notify(new NewOrderNotification($order));
        }
    }

    public function notifyPaymentConfirmed(Payment $payment): void
    {
        $recipients = $payment->workspace->users()
            ->wherePivotIn('membership_role', ['owner', 'admin', 'manager'])
            ->get();

        /** @var User $recipient */
        foreach ($recipients as $recipient) {
            $recipient->notify(new PaymentConfirmedNotification($payment));
        }
    }

    public function notifyLowStock(User $recipient, string $productName, int $stock): void
    {
        $recipient->notify(new LowStockNotification($productName, $stock));
    }

    public function notifyFinanceInvoiceEvent(FinanceInvoice $invoice, string $title, string $message, string $eventType = 'invoice_event'): void
    {
        $invoice->loadMissing('workspace');
        $workspace = $invoice->workspace;
        if (! $workspace instanceof Workspace) {
            return;
        }

        $recipients = $workspace->users()
            ->wherePivotIn('membership_role', ['owner', 'admin', 'manager', 'accountant'])
            ->wherePivot('status', 'active')
            ->get();

        /** @var User $recipient */
        foreach ($recipients as $recipient) {
            try {
                $recipient->notify(new FinanceInvoiceNotification($invoice, $title, $message, $eventType));
            } catch (\Throwable $exception) {
                \Illuminate\Support\Facades\Log::warning('finance-invoice-notification-failed', [
                    'invoice_id' => $invoice->id,
                    'user_id' => $recipient->id,
                    'error' => $exception->getMessage(),
                ]);
            }
        }
    }

    public function notifyContractEvent(Contract $contract, string $title, string $message): void
    {
        $contract->loadMissing('workspace');
        $workspace = $contract->workspace;
        if (! $workspace instanceof Workspace) {
            return;
        }

        $recipients = $workspace->users()
            ->wherePivotIn('membership_role', ['owner', 'admin', 'manager', 'accountant'])
            ->wherePivot('status', 'active')
            ->get();

        /** @var User $recipient */
        foreach ($recipients as $recipient) {
            try {
                $recipient->notify(new ContractLifecycleNotification($contract, $title, $message));
            } catch (\Throwable $exception) {
                \Illuminate\Support\Facades\Log::warning('contract-notification-failed', [
                    'contract_id' => $contract->id,
                    'user_id' => $recipient->id,
                    'error' => $exception->getMessage(),
                ]);
            }
        }
    }
}
