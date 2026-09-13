<?php

namespace App\Services\Finance;

use App\Models\Finance\FinanceInvoice;
use App\Services\Notification\DomainNotificationService;
use App\Support\Tenancy\WorkspaceContext;
use Illuminate\Support\Carbon;

class InvoiceReminderService
{
    public function __construct(
        private readonly InvoiceStateService $invoiceStateService,
        private readonly DomainNotificationService $domainNotificationService,
        private readonly InvoiceReminderEmailService $invoiceReminderEmailService,
    ) {}

    /**
     * @return array{upcoming:int,due:int,overdue:int}
     */
    public function dispatchDueReminders(?int $workspaceId = null, int $upcomingDays = 3): array
    {
        $this->invoiceStateService->refreshIssuedStatuses($workspaceId);

        $counts = ['upcoming' => 0, 'due' => 0, 'overdue' => 0];
        $today = now()->startOfDay();

        $query = FinanceInvoice::withoutGlobalScopes()
            ->where(function ($builder): void {
                $builder->where('invoice_status', 'issued')
                    ->orWhere(function ($legacy): void {
                        $legacy->whereNull('invoice_status')
                            ->whereIn('status', ['sent', 'unpaid', 'partial', 'paid', 'overdue']);
                    });
            })
            ->whereNotNull('due_date')
            ->whereIn('payment_status', ['unpaid', 'partial', 'overdue']);

        if ($workspaceId) {
            $query->where('workspace_id', $workspaceId);
        }

        $query->with('customer')->orderBy('id')->chunkById(100, function ($invoices) use (&$counts, $today, $upcomingDays): void {
            foreach ($invoices as $invoice) {
                $due = $invoice->due_date?->copy()->startOfDay();
                if (! $due) {
                    continue;
                }

                $stage = null;
                if ($due->lt($today)) {
                    $stage = 'overdue';
                } elseif ($due->equalTo($today)) {
                    $stage = 'due';
                } elseif ($due->lte($today->copy()->addDays($upcomingDays))) {
                    $stage = 'upcoming';
                }

                if ($stage === null || $invoice->reminder_stage === $stage) {
                    continue;
                }

                if ((float) $invoice->amount_due <= InvoiceStateService::PAYMENT_TOLERANCE) {
                    continue;
                }

                app(WorkspaceContext::class)->runForId((int) $invoice->workspace_id, function () use ($invoice, $stage): void {
                    $this->domainNotificationService->notifyFinanceInvoiceEvent(
                        $invoice,
                        $this->titleForStage($stage, $invoice),
                        $this->messageForStage($stage, $invoice),
                    );

                    $this->maybeEmailCustomer($invoice, $stage);

                    $invoice->update([
                        'reminder_stage' => $stage,
                        'last_reminder_sent_at' => now(),
                    ]);
                });

                $counts[$stage]++;
            }
        });

        return $counts;
    }

    private function maybeEmailCustomer(FinanceInvoice $invoice, string $stage): void
    {
        if ((string) $invoice->type !== 'sales') {
            return;
        }

        $email = trim((string) ($invoice->customer?->email ?? ''));
        if ($email === '' || filter_var($email, FILTER_VALIDATE_EMAIL) === false) {
            return;
        }

        try {
            $this->invoiceReminderEmailService->send($invoice, [
                'email' => $email,
                'subject' => $this->titleForStage($stage, $invoice),
                'message' => $this->messageForStage($stage, $invoice),
                'attach_pdf' => true,
                'source' => 'scheduled',
            ], 0);
        } catch (\Throwable) {
            // Staff notification already recorded; customer email is best-effort.
        }
    }

    private function titleForStage(string $stage, FinanceInvoice $invoice): string
    {
        return match ($stage) {
            'upcoming' => 'فاتورة قاربت على الاستحقاق '.$invoice->invoice_number,
            'due' => 'فاتورة مستحقة اليوم '.$invoice->invoice_number,
            default => 'فاتورة متأخرة '.$invoice->invoice_number,
        };
    }

    private function messageForStage(string $stage, FinanceInvoice $invoice): string
    {
        $due = $invoice->due_date?->toDateString() ?? '-';
        $amount = number_format((float) $invoice->amount_due, 2).' '.$invoice->currency;

        return match ($stage) {
            'upcoming' => "الفاتورة {$invoice->invoice_number} تستحق في {$due}. المتبقي {$amount}.",
            'due' => "الفاتورة {$invoice->invoice_number} مستحقة اليوم. المتبقي {$amount}.",
            default => "الفاتورة {$invoice->invoice_number} تجاوزت تاريخ الاستحقاق {$due}. المتبقي {$amount}.",
        };
    }
}
