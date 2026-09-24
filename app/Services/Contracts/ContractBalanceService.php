<?php

namespace App\Services\Contracts;

use App\Models\Contract\Contract;
use App\Models\Finance\FinanceBillingSchedule;
use App\Models\Finance\FinanceInvoice;
use App\Models\Finance\FinanceInvoicePayment;
use App\Support\Money\Money;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Schema;

class ContractBalanceService
{
    /**
     * @return array{
     *     contract_value:float,
     *     invoiced:float,
     *     paid:float,
     *     outstanding:float,
     *     unbilled:float,
     *     overdue:float,
     *     cancelled_invoices:float,
     *     invoices:Collection,
     *     payments:Collection
     * }
     */
    public function summarize(Contract $contract): array
    {
        $contract->loadMissing(['invoices.payments']);
        $invoices = $contract->invoices;

        $active = $invoices->filter(function (FinanceInvoice $invoice): bool {
            $invoiceStatus = (string) ($invoice->invoice_status ?: $invoice->status);
            $paymentStatus = (string) ($invoice->payment_status ?: $invoice->status);

            return $invoiceStatus !== 'cancelled' && $paymentStatus !== 'cancelled';
        });

        $cancelled = $invoices->filter(function (FinanceInvoice $invoice): bool {
            $invoiceStatus = (string) ($invoice->invoice_status ?: $invoice->status);

            return $invoiceStatus === 'cancelled';
        });

        $invoiced = $this->sumMoney($active->map(fn (FinanceInvoice $invoice) => $invoice->total)->all());
        $paid = $this->sumMoney($active->map(fn (FinanceInvoice $invoice) => $invoice->amount_paid)->all());
        $outstanding = $this->sumMoney($active->map(fn (FinanceInvoice $invoice) => $invoice->amount_due)->all());
        $overdue = $this->sumMoney(
            $active
                ->filter(function (FinanceInvoice $invoice): bool {
                    $paymentStatus = (string) ($invoice->payment_status ?: $invoice->status);

                    return $paymentStatus === 'overdue' || (
                        (float) $invoice->amount_due > 0
                        && $invoice->due_date
                        && $invoice->due_date->startOfDay()->lt(now()->startOfDay())
                    );
                })
                ->map(fn (FinanceInvoice $invoice) => $invoice->amount_due)
                ->all()
        );
        $contractValue = Money::round((float) $contract->value);
        $unbilled = max(0, (float) Money::sub($contractValue, $invoiced));

        $payments = $active
            ->flatMap(fn (FinanceInvoice $invoice) => $invoice->payments ?? collect())
            ->filter(fn (FinanceInvoicePayment $payment) => ($payment->status ?? 'posted') !== 'reversed')
            ->values();

        return [
            'contract_value' => $contractValue,
            'invoiced' => $invoiced,
            'paid' => $paid,
            'outstanding' => $outstanding,
            'unbilled' => $unbilled,
            'overdue' => $overdue,
            'cancelled_invoices' => $this->sumMoney($cancelled->map(fn (FinanceInvoice $invoice) => $invoice->total)->all()),
            'invoices' => $invoices,
            'payments' => $payments,
        ];
    }

    public function committedScheduleAmount(Contract $contract, ?int $ignoreScheduleId = null): float
    {
        if (! Schema::hasTable('finance_billing_schedules')) {
            return 0.0;
        }

        $schedules = FinanceBillingSchedule::withoutGlobalScopes()
            ->where('workspace_id', $contract->workspace_id)
            ->where('contract_id', $contract->id)
            ->whereNotIn('status', [
                FinanceBillingSchedule::STATUS_CANCELLED,
                FinanceBillingSchedule::STATUS_COMPLETED,
            ])
            ->when($ignoreScheduleId, fn ($query) => $query->whereKeyNot($ignoreScheduleId))
            ->get();

        $committed = '0.00';
        foreach ($schedules as $schedule) {
            $remaining = $this->remainingOccurrences($schedule);
            $line = Money::fromMinor(Money::minor((float) $schedule->amount) * $remaining);
            $committed = Money::add($committed, $line);
        }

        return (float) $committed;
    }

    public function remainingOccurrences(FinanceBillingSchedule $schedule): int
    {
        if ($schedule->total_occurrences !== null) {
            return max(0, (int) $schedule->total_occurrences - (int) $schedule->generated_count);
        }

        if ($schedule->end_date && $schedule->next_run_on) {
            $cursor = $schedule->next_run_on->copy()->startOfDay();
            $end = $schedule->end_date->copy()->startOfDay();
            $count = 0;
            while ($cursor->lte($end) && $count < 120) {
                $count++;
                $cursor = match ($schedule->frequency) {
                    'weekly' => $cursor->addWeeks(max(1, (int) $schedule->interval_count)),
                    'quarterly' => $cursor->addMonths(3 * max(1, (int) $schedule->interval_count)),
                    'semi_annual' => $cursor->addMonths(6 * max(1, (int) $schedule->interval_count)),
                    'yearly' => $cursor->addYears(max(1, (int) $schedule->interval_count)),
                    default => $cursor->addMonths(max(1, (int) $schedule->interval_count)),
                };
            }

            return $count;
        }

        return 1;
    }

    /**
     * @param  array<int, mixed>  $values
     */
    private function sumMoney(array $values): float
    {
        $total = '0.00';
        foreach ($values as $value) {
            $total = Money::add($total, (float) $value);
        }

        return (float) $total;
    }

    public function scheduleStatus(FinanceBillingSchedule $schedule): string
    {
        if ($schedule->status === FinanceBillingSchedule::STATUS_CANCELLED) {
            return 'cancelled';
        }

        $invoices = $schedule->relationLoaded('invoices')
            ? $schedule->invoices
            : $schedule->invoices()->get();

        $active = $invoices->filter(function (FinanceInvoice $invoice): bool {
            $invoiceStatus = (string) ($invoice->invoice_status ?: $invoice->status);

            return $invoiceStatus !== 'cancelled';
        });

        if ($active->isEmpty()) {
            if (
                $schedule->status === FinanceBillingSchedule::STATUS_ACTIVE
                && $schedule->next_run_on
                && $schedule->next_run_on->startOfDay()->lt(now()->startOfDay())
            ) {
                return 'overdue';
            }

            return 'pending';
        }

        $paid = $this->sumMoney($active->map(fn (FinanceInvoice $invoice) => $invoice->amount_paid)->all());
        $due = $this->sumMoney($active->map(fn (FinanceInvoice $invoice) => $invoice->amount_due)->all());
        $overdue = $active->contains(function (FinanceInvoice $invoice): bool {
            return (float) $invoice->amount_due > 0
                && $invoice->due_date
                && $invoice->due_date->startOfDay()->lt(now()->startOfDay());
        });

        if ($due <= 0.009) {
            return 'paid';
        }
        if ($paid > 0.009) {
            return 'partially_paid';
        }
        if ($overdue) {
            return 'overdue';
        }

        return 'invoiced';
    }

    /**
     * @return array<string, float|int>
     */
    public function workspaceDashboard(): array
    {
        $base = Contract::query();
        $invoices = FinanceInvoice::query()->whereNotNull('contract_id');
        $activeInvoices = (clone $invoices)->where(function ($query): void {
            $query->where('invoice_status', '!=', 'cancelled')
                ->orWhereNull('invoice_status');
        });

        $invoiced = (float) (clone $activeInvoices)->sum('total');
        $paid = (float) (clone $activeInvoices)->sum('amount_paid');
        $outstanding = (float) (clone $activeInvoices)->sum('amount_due');
        $contractValue = (float) (clone $base)->whereNotIn('status', [Contract::STATUS_CANCELLED])->sum('value');

        return [
            'total' => (clone $base)->count(),
            'open' => (clone $base)->where('status', Contract::STATUS_OPEN)->count(),
            'draft' => (clone $base)->where('status', Contract::STATUS_DRAFT)->count(),
            'expired' => (clone $base)->where('status', Contract::STATUS_EXPIRED)->count(),
            'cancelled' => (clone $base)->where('status', Contract::STATUS_CANCELLED)->count(),
            'closed' => (clone $base)->where('status', Contract::STATUS_CLOSED)->count(),
            'expiring' => (clone $base)->where('status', Contract::STATUS_OPEN)
                ->whereNotNull('end_date')
                ->whereDate('end_date', '<=', now()->addDays(30)->toDateString())
                ->count(),
            'value_open' => (clone $base)->where('status', Contract::STATUS_OPEN)->sum('value'),
            'value_total' => $contractValue,
            'invoiced' => $invoiced,
            'paid' => $paid,
            'outstanding' => $outstanding,
            'unbilled' => max(0, (float) Money::sub($contractValue, $invoiced)),
        ];
    }
}
