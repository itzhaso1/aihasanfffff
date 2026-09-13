<?php

namespace App\Services\Finance\Api;

use App\Models\AuditLog;
use App\Models\Contract\Contract;
use App\Models\Crm\CrmLead;
use App\Models\Customer;
use App\Models\Finance\FinanceAccount;
use App\Models\Finance\FinanceAccountingPeriod;
use App\Models\Finance\FinanceBankStatement;
use App\Models\Finance\FinanceBankStatementLine;
use App\Models\Finance\FinanceCreditNote;
use App\Models\Finance\FinanceEmployee;
use App\Models\Finance\FinanceEmployeePayrollRecord;
use App\Models\Finance\FinancePayrollAdjustment;
use App\Models\Finance\FinanceSalaryAdvance;
use App\Models\Finance\FinanceSalaryAdvanceRepayment;
use App\Models\Finance\FinanceDocumentDelivery;
use App\Models\Finance\FinanceExpense;
use App\Models\Finance\FinanceFiscalYear;
use App\Models\Finance\FinanceInvoice;
use App\Models\Finance\FinanceInvoiceItem;
use App\Models\Finance\FinanceInvoicePayment;
use App\Models\Finance\FinanceJournalEntry;
use App\Models\Finance\FinancePriceList;
use App\Models\Finance\FinancePriceListItem;
use App\Models\Finance\FinancePurchaseOrder;
use App\Models\Finance\FinancePurchaseOrderItem;
use App\Models\Finance\FinanceQuote;
use App\Models\Finance\FinanceQuoteItem;
use App\Models\Finance\FinanceReceipt;
use App\Models\Finance\FinanceSetting;
use App\Models\Finance\FinanceSupplier;
use App\Models\Finance\FinanceTaxRate;
use App\Models\Finance\FinanceTreasuryAccount;
use App\Models\Finance\FinanceTreasuryTransfer;
use App\Models\InventoryMovement;
use App\Models\Product;
use App\Models\Projects\FinanceProject;
use App\Models\User;
use App\Models\Workspace;
use App\Services\Finance\EInvoiceArtifactService;
use App\Services\Payment\Contracts\BillableCheckoutResult;
use App\Support\Money\Money;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Storage;

class FinanceClientPresenter
{
    public function __construct(
        private readonly EInvoiceArtifactService $eInvoiceArtifactService,
    ) {}

    public function money(mixed $value): string
    {
        return Money::of($value ?? 0);
    }

    /**
     * @param  array<string, mixed>|null  $extra
     * @return array<string, mixed>
     */
    public function customer(Customer $customer, ?float $outstanding = null, ?array $extra = null): array
    {
        $payload = [
            'id' => (int) $customer->id,
            'name' => $customer->name,
            'party_type' => $customer->partyType(),
            'email' => $customer->email,
            'phone' => $customer->phone,
            'whatsapp' => $customer->whatsapp,
            'vat_number' => $customer->vat_number,
            'commercial_registration' => $customer->commercial_registration,
            'address' => $customer->address,
            'building_number' => $customer->building_number,
            'street' => $customer->street,
            'district' => $customer->district,
            'city' => $customer->city,
            'postal_code' => $customer->postal_code,
            'country_code' => $customer->country_code,
            'additional_number' => $customer->additional_number,
            'payment_terms' => $customer->payment_terms,
            'notes' => $customer->notes,
            'outstanding_balance' => $this->money($outstanding ?? 0),
        ];

        if ($extra !== null) {
            $payload = array_merge($payload, $extra);
        }

        return $payload;
    }

    /**
     * @return array<string, mixed>
     */
    public function invoiceSummary(FinanceInvoice $invoice): array
    {
        return [
            'id' => (int) $invoice->id,
            'invoice_number' => $invoice->invoice_number,
            'type' => $invoice->type,
            'customer_id' => $invoice->customer_id ? (int) $invoice->customer_id : null,
            'customer_name' => $invoice->customer?->name ?? $invoice->customer_name,
            'supplier_id' => $invoice->supplier_id ? (int) $invoice->supplier_id : null,
            'supplier_name' => $invoice->supplier?->name,
            'issue_date' => $this->date($invoice->issue_date),
            'due_date' => $this->date($invoice->due_date),
            'supply_date' => Schema::hasColumn('finance_invoices', 'supply_date')
                ? $this->date($invoice->supply_date)
                : null,
            'issued_at' => $invoice->issued_at?->timezone(config('app.timezone'))->toIso8601String(),
            'currency' => $invoice->currency ?: 'SAR',
            'contract_id' => $invoice->contract_id ? (int) $invoice->contract_id : null,
            'contract_number' => $invoice->contract?->contract_number,
            'contract_title' => $invoice->contract?->title,
            'project_id' => $invoice->project_id ? (int) $invoice->project_id : null,
            'project_name' => $invoice->project?->name,
            'subtotal' => $this->money($invoice->subtotal),
            'discount' => $this->money($invoice->discount),
            'taxable_amount' => $this->money($invoice->taxable_amount),
            'tax_amount' => $this->money($invoice->tax_amount),
            'total' => $this->money($invoice->total),
            'amount_paid' => $this->money($invoice->amount_paid),
            'amount_due' => $this->money($invoice->amount_due),
            'amount_credited' => $this->money($invoice->amount_credited ?? 0),
            'amount_debited' => $this->money($invoice->amount_debited ?? 0),
            'document_status' => $invoice->invoice_status ?: $invoice->status,
            'payment_status' => $invoice->payment_status,
            'delivery_status' => $this->latestDeliveryStatus($invoice->relationLoaded('deliveries') ? $invoice->deliveries : null),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function invoiceDetail(FinanceInvoice $invoice, ?BillableCheckoutResult $checkout = null): array
    {
        $payload = $this->invoiceSummary($invoice);
        $payload['notes'] = $invoice->notes;
        $payload['payment_terms'] = $invoice->payment_terms;
        $payload['tax_profile_type'] = $invoice->tax_profile_type;
        $payload['tax_rate'] = $this->money($invoice->tax_rate ?? 0);
        $payload['tax_price_mode'] = $invoice->tax_price_mode;
        $payload['tax_breakdown'] = is_array($invoice->tax_breakdown) ? $invoice->tax_breakdown : [];
        $payload['company_snapshot'] = is_array($invoice->company_snapshot) ? $invoice->company_snapshot : null;
        $payload['recipient_snapshot'] = is_array($invoice->recipient_snapshot) ? $invoice->recipient_snapshot : null;
        $payload['lines'] = $invoice->items?->map(fn ($item) => $this->invoiceItem($item))->values()->all() ?? [];
        $payload['payments'] = $invoice->payments?->map(fn ($payment) => $this->payment($payment))->values()->all() ?? [];
        $payload['receipts'] = $invoice->receipts?->map(fn ($receipt) => $this->receiptSummary($receipt))->values()->all() ?? [];
        $payload['credit_notes'] = $invoice->creditNotes?->map(fn ($note) => $this->noteSummary($note))->values()->all() ?? [];
        $payload['deliveries'] = $invoice->deliveries?->map(fn ($delivery) => $this->delivery($delivery))->values()->all() ?? [];
        $payload['checkout'] = $checkout ? $this->checkout($checkout) : null;
        $payload['attachments'] = $invoice->attachments?->map(fn ($attachment) => [
            'id' => (int) $attachment->id,
            'file_name' => $attachment->file_name,
            'file_type' => $attachment->file_type,
            'file_size' => $attachment->file_size,
        ])->values()->all() ?? [];
        $payload['zatca'] = $this->eInvoiceArtifactService->availabilityForInvoice($invoice);

        return $payload;
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function relatedAuditLogs(FinanceInvoice $invoice): array
    {
        return AuditLog::query()
            ->with('user')
            ->where('workspace_id', $invoice->workspace_id)
            ->where('entity_type', FinanceInvoice::class)
            ->where('entity_id', $invoice->id)
            ->latest('id')
            ->limit(30)
            ->get()
            ->map(fn (AuditLog $log) => $this->audit($log))
            ->values()
            ->all();
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function relatedJournalEntries(FinanceInvoice $invoice): array
    {
        $paymentIds = $invoice->relationLoaded('payments')
            ? $invoice->payments->pluck('id')->all()
            : $invoice->payments()->pluck('id')->all();
        $creditNoteIds = $invoice->relationLoaded('creditNotes')
            ? $invoice->creditNotes->pluck('id')->all()
            : $invoice->creditNotes()->pluck('id')->all();

        return FinanceJournalEntry::query()
            ->with(['lines.account'])
            ->where(function ($query) use ($invoice, $paymentIds, $creditNoteIds): void {
                $query->where(function ($inner) use ($invoice): void {
                    $inner->where('reference_type', FinanceInvoice::class)
                        ->where('reference_id', $invoice->id);
                });

                if ($paymentIds !== []) {
                    $query->orWhere(function ($inner) use ($paymentIds): void {
                        $inner->where('reference_type', FinanceInvoicePayment::class)
                            ->whereIn('reference_id', $paymentIds);
                    });
                }

                if ($creditNoteIds !== []) {
                    $query->orWhere(function ($inner) use ($creditNoteIds): void {
                        $inner->where('reference_type', FinanceCreditNote::class)
                            ->whereIn('reference_id', $creditNoteIds);
                    });
                }
            })
            ->latest('id')
            ->limit(50)
            ->get()
            ->map(fn (FinanceJournalEntry $entry) => $this->journalEntry($entry))
            ->values()
            ->all();
    }

    /**
     * @return array<string, mixed>
     */
    public function invoiceItem(FinanceInvoiceItem|FinanceQuoteItem $item): array
    {
        return [
            'id' => (int) $item->id,
            'product_id' => $item->product_id ? (int) $item->product_id : null,
            'product_name' => $item->product_name,
            'description' => $item->description,
            'unit' => $item->unit,
            'quantity' => (string) $item->quantity,
            'unit_price' => $this->money($item->unit_price),
            'discount' => $this->money($item->discount),
            'tax_rate' => $this->money($item->tax_rate ?? 0),
            'tax_amount' => $this->money($item->tax_amount),
            'taxable_amount' => $this->money($item->taxable_amount),
            'total' => $this->money($item->total),
            'tax_profile_type' => $item->tax_profile_type,
            'exemption_reason' => $item->exemption_reason ?? null,
            'exemption_code' => $item->exemption_code ?? null,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function quoteSummary(FinanceQuote $quote): array
    {
        return [
            'id' => (int) $quote->id,
            'quote_number' => $quote->quote_number,
            'customer_id' => $quote->customer_id ? (int) $quote->customer_id : null,
            'customer_name' => $quote->customer?->name,
            'issue_date' => $this->date($quote->issue_date),
            'expiry_date' => $this->date($quote->expiry_date),
            'currency' => $quote->currency ?: 'SAR',
            'subtotal' => $this->money($quote->subtotal),
            'discount' => $this->money($quote->discount),
            'taxable_amount' => $this->money($quote->taxable_amount),
            'tax_amount' => $this->money($quote->tax_amount),
            'total' => $this->money($quote->total),
            'document_status' => $quote->status,
            'outcome' => $quote->outcome,
            'delivery_status' => $this->latestDeliveryStatus($quote->relationLoaded('deliveries') ? $quote->deliveries : null),
            'converted_invoice_id' => $quote->converted_invoice_id ? (int) $quote->converted_invoice_id : null,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function quoteDetail(FinanceQuote $quote): array
    {
        $payload = $this->quoteSummary($quote);
        $payload['notes'] = $quote->notes;
        $payload['terms'] = $quote->terms;
        $payload['tax_profile_type'] = $quote->tax_profile_type;
        $payload['tax_rate'] = $this->money($quote->tax_rate ?? 0);
        $payload['tax_price_mode'] = $quote->tax_price_mode;
        $payload['rejection_reason'] = $quote->rejection_reason;
        $payload['converted_invoice_number'] = $quote->convertedInvoice?->invoice_number;
        $payload['lines'] = $quote->items?->map(fn ($item) => $this->invoiceItem($item))->values()->all() ?? [];
        $payload['deliveries'] = $quote->deliveries?->map(fn ($delivery) => $this->delivery($delivery))->values()->all() ?? [];
        $payload['attachments'] = $quote->attachments?->map(fn ($attachment) => [
            'id' => (int) $attachment->id,
            'file_name' => $attachment->file_name,
            'file_type' => $attachment->file_type,
            'file_size' => $attachment->file_size,
        ])->values()->all() ?? [];

        return $payload;
    }

    /**
     * @return array<string, mixed>
     */
    public function payment(FinanceInvoicePayment $payment): array
    {
        return [
            'id' => (int) $payment->id,
            'invoice_id' => $payment->invoice_id ? (int) $payment->invoice_id : null,
            'invoice_number' => $payment->invoice?->invoice_number,
            'customer_id' => $payment->invoice?->customer_id ? (int) $payment->invoice->customer_id : null,
            'customer_name' => $payment->invoice?->customer?->name ?? $payment->invoice?->customer_name,
            'payment_date' => $this->date($payment->payment_date),
            'amount' => $this->money($payment->amount),
            'method' => $payment->method,
            'reference' => $payment->reference,
            'status' => $payment->status ?: FinanceInvoicePayment::STATUS_POSTED,
            'notes' => $payment->notes,
            'reversed_at' => $payment->reversed_at?->toIso8601String(),
            'reversal_reason' => $payment->reversal_reason,
            'receipt_id' => $payment->receipt?->id,
            'receipt_number' => $payment->receipt?->receipt_number,
            'receipt_status' => $payment->receipt?->status,
            'treasury_account_id' => $payment->treasury_account_id ? (int) $payment->treasury_account_id : null,
            'treasury_account_name' => $payment->treasuryAccount?->name,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function receiptSummary(FinanceReceipt $receipt): array
    {
        return [
            'id' => (int) $receipt->id,
            'receipt_number' => $receipt->receipt_number,
            'invoice_id' => $receipt->invoice_id ? (int) $receipt->invoice_id : null,
            'invoice_number' => $receipt->invoice?->invoice_number,
            'customer_id' => $receipt->customer_id ? (int) $receipt->customer_id : null,
            'customer_name' => $receipt->customer?->name,
            'payment_id' => $receipt->payment_id ? (int) $receipt->payment_id : null,
            'payment_date' => $this->date($receipt->payment_date),
            'amount' => $this->money($receipt->amount),
            'method' => $receipt->method,
            'reference' => $receipt->reference,
            'currency' => $receipt->currency ?: 'SAR',
            'status' => $receipt->status ?: FinanceReceipt::STATUS_POSTED,
            'voided_at' => $receipt->voided_at?->toIso8601String(),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function receiptDetail(FinanceReceipt $receipt): array
    {
        $payload = $this->receiptSummary($receipt);
        $payload['payment'] = $receipt->payment ? $this->payment($receipt->payment) : null;
        $payload['deliveries'] = $receipt->deliveries?->map(fn ($delivery) => $this->delivery($delivery))->values()->all() ?? [];

        return $payload;
    }

    /**
     * @return array<string, mixed>
     */
    public function noteSummary(FinanceCreditNote $note): array
    {
        return [
            'id' => (int) $note->id,
            'note_number' => $note->note_number,
            'type' => $note->type,
            'status' => $note->status,
            'invoice_id' => $note->invoice_id ? (int) $note->invoice_id : null,
            'invoice_number' => $note->invoice?->invoice_number,
            'customer_id' => $note->customer_id ? (int) $note->customer_id : null,
            'customer_name' => $note->customer?->name,
            'issue_date' => $this->date($note->issue_date),
            'reason' => $note->reason,
            'currency' => $note->currency ?: 'SAR',
            'tax_amount' => $this->money($note->tax_amount),
            'total' => $this->money($note->total),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function noteDetail(FinanceCreditNote $note): array
    {
        $payload = $this->noteSummary($note);
        $payload['notes'] = $note->notes;
        $payload['subtotal'] = $this->money($note->subtotal);
        $payload['lines'] = $note->items?->map(function ($item): array {
            return [
                'id' => (int) $item->id,
                'product_id' => $item->product_id ? (int) $item->product_id : null,
                'product_name' => $item->product_name ?? $item->description,
                'description' => $item->description ?? $item->product_name,
                'unit' => $item->unit ?? $item->unit_code,
                'quantity' => (string) $item->quantity,
                'unit_price' => $this->money($item->unit_price),
                'discount' => $this->money($item->discount ?? 0),
                'tax_rate' => $this->money($item->tax_rate ?? 0),
                'tax_amount' => $this->money($item->tax_amount ?? 0),
                'taxable_amount' => $this->money($item->taxable_amount ?? 0),
                'total' => $this->money($item->total),
                'tax_profile_type' => $item->tax_profile_type,
            ];
        })->values()->all() ?? [];

        return $payload;
    }

    /**
     * @return array<string, mixed>
     */
    public function expense(FinanceExpense $expense): array
    {
        return [
            'id' => (int) $expense->id,
            'expense_number' => $expense->expense_number,
            'expense_date' => $this->date($expense->expense_date),
            'description' => $expense->description,
            'category_id' => $expense->category_id ? (int) $expense->category_id : null,
            'category_name' => $expense->category?->name,
            'supplier_id' => $expense->supplier_id ? (int) $expense->supplier_id : null,
            'supplier_name' => $expense->supplier?->name,
            'amount' => $this->money($expense->amount),
            'tax_rate' => $this->money($expense->tax_rate ?? 0),
            'tax_amount' => $this->money($expense->tax_amount),
            'total' => $this->money($expense->total),
            'currency' => $expense->currency ?: 'SAR',
            'payment_method' => $expense->payment_method,
            'status' => $expense->status,
            'has_attachment' => filled($expense->attachment_path),
            'treasury_account_id' => $expense->treasury_account_id ? (int) $expense->treasury_account_id : null,
            'treasury_account_name' => $expense->treasuryAccount?->name,
            'is_recurring' => (bool) $expense->is_recurring,
            'recurring_frequency' => $expense->recurring_frequency,
            'next_due_date' => $this->date($expense->next_due_date),
            'tax_profile_type' => $expense->tax_profile_type,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function supplier(FinanceSupplier $supplier): array
    {
        return [
            'id' => (int) $supplier->id,
            'name' => $supplier->name,
            'arabic_name' => $supplier->arabic_name,
            'vat_number' => $supplier->vat_number,
            'commercial_registration' => $supplier->commercial_registration,
            'address' => $supplier->address,
            'phone' => $supplier->phone,
            'email' => $supplier->email,
            'payment_terms' => $supplier->payment_terms,
            'status' => $supplier->status,
            'opening_balance' => $this->money($supplier->opening_balance ?? 0),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function contract(Contract $contract): array
    {
        return [
            'id' => (int) $contract->id,
            'contract_number' => $contract->contract_number,
            'title' => $contract->title,
            'status' => $contract->status,
            'customer_id' => $contract->customer_id ? (int) $contract->customer_id : null,
            'customer_name' => $contract->customer?->name,
            'start_date' => $this->date($contract->start_date),
            'end_date' => $this->date($contract->end_date),
            'value' => $this->money($contract->value ?? 0),
            'currency' => $contract->currency ?: 'SAR',
            'notes' => $contract->notes,
            'terms' => $contract->terms,
            'items' => $contract->relationLoaded('items')
                ? $contract->items->map(fn ($item) => [
                    'id' => (int) $item->id,
                    'title' => $item->title,
                    'description' => $item->description,
                    'quantity' => (string) $item->quantity,
                    'unit_price' => $this->money($item->unit_price),
                    'total' => $this->money($item->total),
                ])->values()->all()
                : [],
            'billing_schedules' => $contract->relationLoaded('billingSchedules')
                ? $contract->billingSchedules->map(fn ($schedule) => [
                    'id' => (int) $schedule->id,
                    'title' => $schedule->title,
                    'frequency' => $schedule->frequency,
                    'status' => $schedule->status,
                    'start_date' => $this->date($schedule->start_date),
                    'end_date' => $this->date($schedule->end_date),
                    'next_run_on' => $this->date($schedule->next_run_on),
                    'interval_count' => $schedule->interval_count,
                    'total_occurrences' => $schedule->total_occurrences,
                    'generated_count' => $schedule->generated_count,
                    'amount' => $this->money($schedule->amount ?? 0),
                    'currency' => $schedule->currency ?: ($contract->currency ?: 'SAR'),
                    'auto_issue' => (bool) $schedule->auto_issue,
                    'notes' => $schedule->notes,
                ])->values()->all()
                : [],
            'attachments' => $contract->relationLoaded('attachments')
                ? $contract->attachments->map(fn ($attachment) => $this->contractAttachment($attachment))->values()->all()
                : [],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function contractAttachment(mixed $attachment): array
    {
        return [
            'id' => (int) $attachment->id,
            'file_name' => $attachment->file_name,
            'file_type' => $attachment->file_type,
            'file_size' => $attachment->file_size,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function delivery(FinanceDocumentDelivery $delivery): array
    {
        return [
            'id' => (int) $delivery->id,
            'document_type' => $delivery->document_type,
            'channel' => $delivery->channel,
            'recipient' => $delivery->recipient,
            'status' => $delivery->status,
            'subject' => $delivery->subject,
            'error' => $delivery->error,
            'sent_at' => $delivery->sent_at?->toIso8601String(),
            'sender_name' => $delivery->sender?->name,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function checkout(BillableCheckoutResult $result): array
    {
        return [
            'supported' => $result->supported,
            'checkout_url' => $result->checkoutUrl,
            'code' => $result->code,
            'message' => $result->message,
        ];
    }

    /**
     * @param  array<string, mixed>  $statement
     * @return array<string, mixed>
     */
    public function statement(array $statement): array
    {
        /** @var Customer $customer */
        $customer = $statement['customer'];

        return [
            'customer' => $this->customer($customer),
            'from' => $statement['from'],
            'to' => $statement['to'],
            'opening_balance' => $this->money($statement['opening_balance'] ?? 0),
            'closing_balance' => $this->money($statement['closing_balance'] ?? 0),
            'invoices_total' => $this->money($statement['invoices_total'] ?? 0),
            'payments_total' => $this->money($statement['payments_total'] ?? 0),
            'credits_total' => $this->money($statement['credits_total'] ?? 0),
            'debits_total' => $this->money($statement['debits_total'] ?? 0),
            'lines' => collect($statement['lines'] ?? [])->map(function (array $line): array {
                return [
                    'date' => $line['date'] ?? null,
                    'kind' => $line['kind'] ?? null,
                    'reference' => $line['reference'] ?? null,
                    'description' => $line['description'] ?? null,
                    'debit' => $this->money($line['debit'] ?? 0),
                    'credit' => $this->money($line['credit'] ?? 0),
                    'balance' => $this->money($line['balance'] ?? 0),
                    'invoice_id' => isset($line['invoice_id']) ? (int) $line['invoice_id'] : null,
                ];
            })->values()->all(),
        ];
    }

    /**
     * @param  array<string, mixed>  $metrics
     * @return array<string, mixed>
     */
    public function dashboard(array $metrics, string $paidThisPeriod): array
    {
        $cards = $metrics['cards'] ?? [];

        return [
            'cards' => [
                'outstanding_customer_balance' => $this->money($cards['receivables_total'] ?? 0),
                'invoices_due' => (int) ($cards['unpaid_invoices'] ?? 0),
                'overdue_invoices' => (int) ($cards['overdue_invoices'] ?? 0),
                'paid_this_period' => $paidThisPeriod,
                'sales' => $this->money($cards['sales_total'] ?? 0),
                'purchases' => $this->money($cards['purchases_total'] ?? 0),
                'expenses' => $this->money($cards['expenses_total'] ?? 0),
                'receivables' => $this->money($cards['receivables_total'] ?? 0),
                'payables' => $this->money($cards['payables_total'] ?? 0),
                'net_profit' => $this->money($cards['net_profit'] ?? 0),
                'output_vat' => $this->money($cards['output_vat'] ?? 0),
                'input_vat' => $this->money($cards['input_vat'] ?? 0),
                'net_vat' => $this->money($cards['net_vat'] ?? 0),
                'cash_balance' => $this->money($cards['cash_balance'] ?? 0),
                'bank_balance' => $this->money($cards['bank_balance'] ?? 0),
                'active_contracts_count' => (int) ($cards['active_contracts_count'] ?? 0),
                'company_employees' => (int) ($cards['company_employees'] ?? 0),
                'payroll_paid_total' => $this->money($cards['payroll_paid_total'] ?? 0),
                'allowances_bonuses_total' => $this->money($cards['allowances_bonuses_total'] ?? 0),
                'deductions_total' => $this->money($cards['deductions_total'] ?? 0),
                'open_advances_total' => $this->money($cards['open_advances_total'] ?? 0),
            ],
            'recent_invoices' => collect($metrics['latest']['invoices'] ?? [])
                ->map(fn ($invoice) => $this->invoiceSummary($invoice))
                ->values()
                ->all(),
            'recent_payments' => collect($metrics['latest']['payments'] ?? [])
                ->map(fn ($payment) => $this->payment($payment))
                ->values()
                ->all(),
            'recent_expenses' => collect($metrics['latest']['expenses'] ?? [])
                ->map(fn ($expense) => $this->expense($expense))
                ->values()
                ->all(),
            'overdue_invoices' => collect($metrics['latest']['overdue_invoices'] ?? [])
                ->map(fn ($invoice) => $this->invoiceSummary($invoice))
                ->values()
                ->all(),
        ];
    }

    /**
     * JSON-safe decision dashboard. Totals stay server-formatted; Laravel route hrefs are omitted.
     *
     * @param  array<string, mixed>  $analytics
     * @return array<string, mixed>
     */
    public function analytics(array $analytics): array
    {
        $kpis = function (iterable $rows): array {
            return collect($rows)->map(fn ($row): array => [
                'key' => (string) ($row['key'] ?? ''),
                'label' => (string) ($row['label'] ?? ''),
                'value' => $this->money($row['value'] ?? 0),
                'previous' => $this->money($row['previous'] ?? 0),
                'delta' => $this->money($row['delta'] ?? 0),
                'direction' => (int) ($row['direction'] ?? 0),
                'hint' => (string) ($row['hint'] ?? ''),
            ])->values()->all();
        };

        $named = function (iterable $rows): array {
            return collect($rows)->map(function ($row): array {
                $data = is_array($row) ? $row : (array) $row;

                return [
                    'id' => isset($data['id']) ? (int) $data['id'] : null,
                    'name' => (string) ($data['name'] ?? $data['title'] ?? ''),
                    'total' => $this->money($data['total'] ?? 0),
                    'due' => $this->money($data['due'] ?? 0),
                    'invoices' => (int) ($data['invoices'] ?? 0),
                    'quantity' => $this->money($data['quantity'] ?? 0),
                ];
            })->values()->all();
        };

        return [
            'from' => $analytics['from'] ?? null,
            'to' => $analytics['to'] ?? null,
            'previous_from' => $analytics['previous_from'] ?? null,
            'previous_to' => $analytics['previous_to'] ?? null,
            'hero' => $kpis($analytics['hero'] ?? []),
            'secondary' => $kpis($analytics['secondary'] ?? []),
            'attention' => collect($analytics['attention'] ?? [])->map(fn ($row): array => [
                'title' => (string) ($row['title'] ?? ''),
                'reason' => (string) ($row['reason'] ?? ''),
            ])->values()->all(),
            'top_customers' => $named($analytics['top_customers'] ?? []),
            'overdue_customers' => $named($analytics['overdue_customers'] ?? []),
            'products' => $named($analytics['products'] ?? []),
            'projects' => collect($analytics['projects'] ?? [])->map(fn ($row): array => [
                'id' => (int) ($row['id'] ?? 0),
                'name' => (string) ($row['name'] ?? ''),
                'revenue' => $this->money($row['revenue'] ?? 0),
                'costs' => $this->money($row['costs'] ?? 0),
                'profit' => $this->money($row['profit'] ?? 0),
            ])->values()->all(),
            'expenses_by_category' => $named($analytics['expenses_by_category'] ?? []),
            'series' => collect($analytics['series'] ?? [])->map(fn ($row): array => [
                'month' => (string) ($row['month'] ?? ''),
                'sales' => $this->money($row['sales'] ?? 0),
                'expenses' => $this->money($row['expenses'] ?? 0),
                'profit' => $this->money($row['profit'] ?? 0),
            ])->values()->all(),
            'cash_flow' => [
                'opening_cash' => $this->money(data_get($analytics, 'cash_flow.opening_cash', 0)),
                'net_change' => $this->money(data_get($analytics, 'cash_flow.net_change', 0)),
                'closing_cash' => $this->money(data_get($analytics, 'cash_flow.closing_cash', 0)),
            ],
            'ledger_profit' => [
                'revenue' => $this->money(data_get($analytics, 'ledger_profit.revenue', 0)),
                'cogs' => $this->money(data_get($analytics, 'ledger_profit.cogs', 0)),
                'gross_profit' => $this->money(data_get($analytics, 'ledger_profit.gross_profit', 0)),
                'operating_expenses' => $this->money(data_get($analytics, 'ledger_profit.operating_expenses', 0)),
                'other_expenses' => $this->money(data_get($analytics, 'ledger_profit.other_expenses', 0)),
                'net_profit' => $this->money(data_get($analytics, 'ledger_profit.net_profit', 0)),
            ],
            'expiring_contracts' => collect($analytics['expiring_contracts'] ?? [])->map(function ($contract): array {
                $end = data_get($contract, 'end_date');

                return [
                    'id' => (int) data_get($contract, 'id'),
                    'title' => (string) (data_get($contract, 'title') ?: data_get($contract, 'contract_number') ?: ''),
                    'end_date' => $end instanceof \DateTimeInterface ? $end->format('Y-m-d') : (string) $end,
                ];
            })->values()->all(),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function settings(FinanceSetting $setting): array
    {
        return [
            'company_name' => $setting->company_name,
            'company_name_ar' => $setting->company_name_ar,
            'vat_number' => $setting->vat_number,
            'commercial_registration' => $setting->commercial_registration,
            'address_line' => $setting->address_line,
            'building_number' => $setting->building_number,
            'street' => $setting->street,
            'district' => $setting->district,
            'city' => $setting->city,
            'postal_code' => $setting->postal_code,
            'country_code' => $setting->country_code,
            'phone' => $setting->phone,
            'email' => $setting->email,
            'website' => $setting->website,
            'currency' => $setting->currency ?: 'SAR',
            'invoice_prefix' => $setting->invoice_prefix,
            'invoice_primary_color' => $setting->invoice_primary_color ?: '#06C2A4',
            'invoice_footer_text' => $setting->invoice_footer_text,
            'default_vat_rate' => $this->money($setting->default_vat_rate ?? 0),
            'default_payment_terms' => $setting->default_payment_terms,
            'allow_manual_invoice_numbers' => (bool) $setting->allow_manual_invoice_numbers,
            'zatca_integration_mode' => $setting->zatca_integration_mode,
            'has_logo' => filled($setting->logo_path),
            'logo_url' => $this->publicFileUrl($setting->logo_path),
            'tax_rates' => FinanceTaxRate::query()
                ->orderByDesc('is_default')
                ->orderBy('id')
                ->get()
                ->map(fn (FinanceTaxRate $rate) => $this->taxRate($rate))
                ->values()
                ->all(),
            'treasury_accounts' => FinanceTreasuryAccount::query()
                ->with('linkedAccount')
                ->orderBy('type')
                ->orderBy('name')
                ->get()
                ->map(fn (FinanceTreasuryAccount $account) => $this->treasuryAccount($account))
                ->values()
                ->all(),
            'finance_accounts' => FinanceAccount::query()
                ->orderBy('code')
                ->get(['id', 'code', 'name', 'type'])
                ->map(fn (FinanceAccount $account) => [
                    'id' => (int) $account->id,
                    'code' => $account->code,
                    'name' => $account->name,
                    'type' => $account->type,
                ])
                ->values()
                ->all(),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function audit(AuditLog $log): array
    {
        return [
            'id' => (int) $log->id,
            'action' => $log->action,
            'created_at' => $log->occurred_at?->toIso8601String(),
            'actor_name' => $log->user?->name,
        ];
    }

    /**
     * @param  Collection<int, Workspace>  $workspaces
     * @return list<array<string, mixed>>
     */
    public function workspaces($workspaces, callable $financeEnabled): array
    {
        return $workspaces
            ->map(function ($workspace) use ($financeEnabled): array {
                return [
                    'id' => $workspace->id,
                    'name' => $workspace->name,
                    'slug' => $workspace->slug ?? null,
                    'type' => $workspace->type,
                    'finance_enabled' => (bool) $financeEnabled($workspace),
                ];
            })
            ->values()
            ->all();
    }

    /**
     * @return array<string, mixed>
     */
    public function user(User $user): array
    {
        return [
            'id' => $user->id,
            'name' => $user->name,
            'email' => $user->email,
            'phone' => $user->phone,
            'locale' => $user->locale,
        ];
    }

    /**
     * @param  array{sold_qty?:mixed,sold_total?:mixed}|null  $sales
     * @return array<string, mixed>
     */
    public function product(Product $product, ?array $sales = null): array
    {
        return [
            'id' => (int) $product->id,
            'name' => $product->name,
            'sku' => $product->sku,
            'barcode' => $product->barcode,
            'description' => $product->description,
            'price' => $this->money($product->price ?? 0),
            'sale_price' => $product->sale_price !== null ? $this->money($product->sale_price) : null,
            'cost_price' => $product->cost_price !== null ? $this->money($product->cost_price) : null,
            'vat_rate' => $this->money($product->vat_rate ?? 0),
            'currency' => $product->currency ?: 'SAR',
            'stock' => $product->stock,
            'inventory_tracking' => (bool) $product->inventory_tracking,
            'status' => $product->status,
            'product_kind' => $product->product_kind,
            'brand' => $product->brand,
            'category_id' => $product->category_id ? (int) $product->category_id : null,
            'category_name' => $product->category?->name,
            'sold_qty' => $this->money($sales['sold_qty'] ?? 0),
            'sold_total' => $this->money($sales['sold_total'] ?? 0),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function inventoryMovement(InventoryMovement $movement): array
    {
        return [
            'id' => (int) $movement->id,
            'product_id' => $movement->product_id ? (int) $movement->product_id : null,
            'product_name' => $movement->product?->name,
            'type' => $movement->type,
            'quantity' => (string) $movement->quantity,
            'before_quantity' => (string) $movement->before_quantity,
            'after_quantity' => (string) $movement->after_quantity,
            'reference_type' => $movement->reference_type,
            'reference_id' => $movement->reference_id ? (int) $movement->reference_id : null,
            'notes' => $movement->notes,
            'actor_name' => $movement->user?->name,
            'created_at' => $movement->created_at?->toIso8601String(),
        ];
    }

    /**
     * @param  array<string, mixed>|null  $profit
     * @return array<string, mixed>
     */
    public function project(FinanceProject $project, ?array $profit = null): array
    {
        return [
            'id' => (int) $project->id,
            'name' => $project->name,
            'status' => $project->status,
            'customer_id' => $project->customer_id ? (int) $project->customer_id : null,
            'customer_name' => $project->customer?->name,
            'budget' => $this->money($project->budget ?? 0),
            'starts_on' => $this->date($project->starts_on),
            'ends_on' => $this->date($project->ends_on),
            'notes' => $project->notes,
            'revenue' => $this->money($profit['revenue'] ?? 0),
            'costs' => $this->money($profit['costs'] ?? 0),
            'profit' => $this->money($profit['profit'] ?? 0),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function priceList(FinancePriceList $list, bool $withItems = false): array
    {
        $payload = [
            'id' => (int) $list->id,
            'name' => $list->name,
            'code' => $list->code,
            'currency' => $list->currency ?: 'SAR',
            'status' => $list->status,
            'effective_from' => $this->date($list->effective_from),
            'effective_to' => $this->date($list->effective_to),
            'notes' => $list->notes,
            'items_count' => $list->relationLoaded('items') ? $list->items->count() : (int) ($list->items_count ?? 0),
        ];

        if ($withItems) {
            $payload['items'] = $list->items?->map(fn (FinancePriceListItem $item) => $this->priceListItem($item))->values()->all() ?? [];
        }

        return $payload;
    }

    /**
     * @return array<string, mixed>
     */
    public function priceListItem(FinancePriceListItem $item): array
    {
        return [
            'id' => (int) $item->id,
            'price_list_id' => (int) $item->price_list_id,
            'product_id' => $item->product_id ? (int) $item->product_id : null,
            'product_name' => $item->product_name,
            'sku' => $item->sku,
            'min_quantity' => (string) $item->min_quantity,
            'price' => $this->money($item->price),
            'tax_rate' => $this->money($item->tax_rate ?? 0),
            'is_active' => (bool) $item->is_active,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function purchaseOrder(FinancePurchaseOrder $order): array
    {
        return [
            'id' => (int) $order->id,
            'po_number' => $order->po_number,
            'status' => $order->status,
            'supplier_id' => $order->supplier_id ? (int) $order->supplier_id : null,
            'supplier_name' => $order->supplier?->name,
            'order_date' => $this->date($order->order_date),
            'expected_date' => $this->date($order->expected_date),
            'currency' => $order->currency ?: 'SAR',
            'subtotal' => $this->money($order->subtotal),
            'tax_amount' => $this->money($order->tax_amount),
            'total' => $this->money($order->total),
            'notes' => $order->notes,
            'invoice_id' => $order->finance_invoice_id ? (int) $order->finance_invoice_id : null,
            'items' => $order->items?->map(fn (FinancePurchaseOrderItem $item) => [
                'id' => (int) $item->id,
                'product_id' => $item->product_id ? (int) $item->product_id : null,
                'product_name' => $item->product_name,
                'quantity' => (string) $item->quantity,
                'received_quantity' => (string) $item->received_quantity,
                'unit_price' => $this->money($item->unit_price),
                'tax_rate' => $this->money($item->tax_rate ?? 0),
                'tax_amount' => $this->money($item->tax_amount),
                'taxable_amount' => $this->money($item->taxable_amount),
                'total' => $this->money($item->total),
            ])->values()->all() ?? [],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function lead(CrmLead $lead): array
    {
        return [
            'id' => (int) $lead->id,
            'name' => $lead->name,
            'company_name' => $lead->company_name,
            'email' => $lead->email,
            'phone' => $lead->phone,
            'source' => $lead->source,
            'status' => $lead->status,
            'estimated_value' => $this->money($lead->estimated_value ?? 0),
            'currency' => $lead->currency ?: 'SAR',
            'notes' => $lead->notes,
            'customer_id' => $lead->customer_id ? (int) $lead->customer_id : null,
            'converted_at' => $lead->converted_at?->toIso8601String(),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function treasuryAccount(FinanceTreasuryAccount $account): array
    {
        return [
            'id' => (int) $account->id,
            'name' => $account->name,
            'type' => $account->type,
            'account_number' => $account->account_number,
            'iban' => $account->iban,
            'bank_name' => $account->bank_name,
            'currency' => $account->currency ?: 'SAR',
            'opening_balance' => $this->money($account->opening_balance ?? 0),
            'current_balance' => $this->money($account->current_balance ?? 0),
            'linked_finance_account_id' => $account->linked_finance_account_id ? (int) $account->linked_finance_account_id : null,
            'linked_account_code' => $account->linkedAccount?->code,
            'linked_account_name' => $account->linkedAccount?->name,
            'is_active' => (bool) $account->is_active,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function bankStatement(FinanceBankStatement $statement): array
    {
        return [
            'id' => (int) $statement->id,
            'treasury_account_id' => (int) $statement->treasury_account_id,
            'treasury_account_name' => $statement->treasuryAccount?->name,
            'statement_date' => $this->date($statement->statement_date),
            'opening_balance' => $this->money($statement->opening_balance ?? 0),
            'closing_balance' => $this->money($statement->closing_balance ?? 0),
            'status' => $statement->status,
            'notes' => $statement->notes,
            'reconciled_at' => $statement->reconciled_at?->toIso8601String(),
            'lines' => $statement->relationLoaded('lines')
                ? $statement->lines->map(fn (FinanceBankStatementLine $line) => $this->bankStatementLine($line))->values()->all()
                : [],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function bankStatementLine(FinanceBankStatementLine $line): array
    {
        return [
            'id' => (int) $line->id,
            'posted_date' => $this->date($line->posted_date),
            'description' => $line->description,
            'reference' => $line->reference,
            'amount' => $this->money($line->amount ?? 0),
            'status' => $line->status,
            'suggested_type' => $line->suggested_type,
            'suggested_id' => $line->suggested_id ? (int) $line->suggested_id : null,
            'suggestion_confidence' => $line->suggestion_confidence,
            'suggestion_reason' => $line->suggestion_reason,
            'matched_type' => $line->matched_type,
            'matched_id' => $line->matched_id ? (int) $line->matched_id : null,
        ];
    }

    public function treasuryTransfer(FinanceTreasuryTransfer $transfer): array
    {
        return [
            'id' => (int) $transfer->id,
            'from_treasury_account_id' => (int) $transfer->from_treasury_account_id,
            'from_account_name' => $transfer->fromAccount?->name,
            'to_treasury_account_id' => (int) $transfer->to_treasury_account_id,
            'to_account_name' => $transfer->toAccount?->name,
            'amount' => $this->money($transfer->amount),
            'transfer_date' => $this->date($transfer->transfer_date),
            'reference' => $transfer->reference,
            'status' => $transfer->status,
            'notes' => $transfer->notes,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function fiscalYear(FinanceFiscalYear $year, bool $withPeriods = false): array
    {
        $payload = [
            'id' => (int) $year->id,
            'name' => $year->name,
            'start_date' => $this->date($year->start_date),
            'end_date' => $this->date($year->end_date),
            'status' => $year->status,
            'periods_count' => $year->relationLoaded('periods') ? $year->periods->count() : (int) ($year->periods_count ?? 0),
        ];

        if ($withPeriods) {
            $payload['periods'] = $year->periods?->map(fn (FinanceAccountingPeriod $period) => $this->accountingPeriod($period))->values()->all() ?? [];
        }

        return $payload;
    }

    /**
     * @return array<string, mixed>
     */
    public function accountingPeriod(FinanceAccountingPeriod $period): array
    {
        return [
            'id' => (int) $period->id,
            'fiscal_year_id' => (int) $period->fiscal_year_id,
            'name' => $period->name,
            'start_date' => $this->date($period->start_date),
            'end_date' => $this->date($period->end_date),
            'status' => $period->status,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function taxRate(FinanceTaxRate $rate): array
    {
        return [
            'id' => (int) $rate->id,
            'name' => $rate->name,
            'code' => $rate->code,
            'type' => $rate->type,
            'rate' => $this->money($rate->rate ?? 0),
            'is_default' => (bool) $rate->is_default,
            'is_active' => (bool) $rate->is_active,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function ledgerAccount(FinanceAccount $account): array
    {
        $debit = $account->debit_total ?? 0;
        $credit = $account->credit_total ?? 0;

        return [
            'id' => (int) $account->id,
            'code' => $account->code,
            'name' => $account->name,
            'type' => $account->type,
            'debit_total' => $this->money($debit),
            'credit_total' => $this->money($credit),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function journalEntry(FinanceJournalEntry $entry): array
    {
        return [
            'id' => (int) $entry->id,
            'entry_number' => $entry->entry_number,
            'entry_date' => $this->date($entry->entry_date),
            'type' => $entry->type,
            'description' => $entry->description,
            'status' => $entry->status,
            'lines' => $entry->lines?->map(fn ($line) => [
                'account_code' => $line->account?->code,
                'account_name' => $line->account?->name,
                'debit' => $this->money($line->debit ?? 0),
                'credit' => $this->money($line->credit ?? 0),
            ])->values()->all() ?? [],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function financeEmployee(FinanceEmployee $employee, bool $detailed = false): array
    {
        $payload = [
            'id' => (int) $employee->id,
            'employee_code' => $employee->employee_code,
            'full_name' => $employee->full_name,
            'job_title' => $employee->job_title,
            'basic_salary' => $this->money($employee->basic_salary ?? 0),
            'hire_date' => $this->date($employee->hire_date),
            'status' => $employee->status,
            'phone' => $employee->phone,
            'email' => $employee->email,
            'address' => $employee->address,
            'emergency_contact' => $employee->emergency_contact,
            'notes' => $employee->notes,
            'payroll_records_count' => (int) ($employee->payroll_records_count ?? $employee->payrollRecords()->count()),
            'financial_summary' => $this->employeeFinancialSummary($employee),
        ];

        if ($detailed) {
            $payload['payroll_records'] = $employee->payrollRecords
                ->map(fn (FinanceEmployeePayrollRecord $record) => $this->payrollRecord($record))
                ->values()
                ->all();
            $payload['advances'] = $employee->salaryAdvances
                ->map(fn (FinanceSalaryAdvance $advance) => $this->salaryAdvance($advance))
                ->values()
                ->all();
            $payload['adjustments'] = $employee->payrollAdjustments
                ->map(fn (FinancePayrollAdjustment $adjustment) => $this->payrollAdjustment($adjustment))
                ->values()
                ->all();
        }

        return $payload;
    }

    /**
     * @return array<string, mixed>
     */
    public function employeeFinancialSummary(FinanceEmployee $employee): array
    {
        $records = FinanceEmployeePayrollRecord::query()
            ->where('finance_employee_id', $employee->id)
            ->where('workspace_id', $employee->workspace_id)
            ->get();

        $totalOwed = (float) $records->whereNotIn('payment_status', ['cancelled'])->sum('net_amount');
        $totalPaid = (float) $records->where('payment_status', 'paid')->sum('net_amount');
        $outstandingPayroll = (float) $records->whereIn('payment_status', ['draft', 'pending', 'partial'])->sum('net_amount');

        $advances = FinanceSalaryAdvance::query()
            ->where('finance_employee_id', $employee->id)
            ->where('workspace_id', $employee->workspace_id)
            ->get();
        $advanceIssued = (float) $advances->sum('amount');
        $advanceRemaining = (float) $advances->where('status', 'open')->sum('remaining_amount');
        $advanceSettled = round(max(0, $advanceIssued - (float) $advances->sum('remaining_amount')), 2);

        $adjustments = FinancePayrollAdjustment::query()
            ->where('finance_employee_id', $employee->id)
            ->where('workspace_id', $employee->workspace_id)
            ->where('status', 'posted')
            ->get();

        return [
            'total_owed' => $this->money($totalOwed),
            'total_paid' => $this->money($totalPaid),
            'remaining' => $this->money($outstandingPayroll),
            'advance_issued' => $this->money($advanceIssued),
            'advance_settled' => $this->money($advanceSettled),
            'advance_remaining' => $this->money($advanceRemaining),
            'allowances_total' => $this->money((float) $adjustments->where('type', 'allowance')->sum('amount')),
            'bonuses_total' => $this->money((float) $adjustments->where('type', 'bonus')->sum('amount')),
            'deductions_total' => $this->money((float) $adjustments->where('type', 'deduction')->sum('amount')),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function payrollRecord(FinanceEmployeePayrollRecord $record): array
    {
        $outstanding = in_array($record->payment_status, ['draft', 'pending', 'partial'], true)
            ? (float) $record->net_amount
            : 0.0;

        return [
            'id' => (int) $record->id,
            'finance_employee_id' => (int) $record->finance_employee_id,
            'employee_name' => $record->employee?->full_name,
            'employee_code' => $record->employee?->employee_code,
            'period_start' => $this->date($record->period_start),
            'period_end' => $this->date($record->period_end),
            'basic_salary' => $this->money($record->basic_salary ?? 0),
            'allowances_total' => $this->money($record->allowances_total ?? 0),
            'deductions_total' => $this->money($record->deductions_total ?? 0),
            'gross_amount' => $this->money($record->gross_amount ?? 0),
            'net_amount' => $this->money($record->net_amount ?? 0),
            'remaining' => $this->money($outstanding),
            'payment_status' => $record->payment_status,
            'paid_at' => $this->date($record->paid_at),
            'notes' => $record->notes,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function salaryAdvance(FinanceSalaryAdvance $advance): array
    {
        $settled = round(max(0, (float) $advance->amount - (float) $advance->remaining_amount), 2);

        return [
            'id' => (int) $advance->id,
            'finance_employee_id' => $advance->finance_employee_id ? (int) $advance->finance_employee_id : null,
            'employee_name' => $advance->financeEmployee?->full_name,
            'employee_code' => $advance->financeEmployee?->employee_code,
            'amount' => $this->money($advance->amount ?? 0),
            'remaining_amount' => $this->money($advance->remaining_amount ?? 0),
            'settled_amount' => $this->money($settled),
            'issued_at' => $this->date($advance->issued_at),
            'status' => $advance->status,
            'type' => $advance->type,
            'payment_method' => $advance->payment_method,
            'notes' => $advance->notes,
            'repayments' => $advance->relationLoaded('repayments')
                ? $advance->repayments->map(fn (FinanceSalaryAdvanceRepayment $repayment) => $this->salaryAdvanceRepayment($repayment))->values()->all()
                : [],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function salaryAdvanceRepayment(FinanceSalaryAdvanceRepayment $repayment): array
    {
        return [
            'id' => (int) $repayment->id,
            'payment_date' => $this->date($repayment->payment_date),
            'amount' => $this->money($repayment->amount ?? 0),
            'method' => $repayment->method,
            'status' => $repayment->status,
            'notes' => $repayment->notes,
            'treasury_account_id' => $repayment->treasury_account_id ? (int) $repayment->treasury_account_id : null,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function payrollAdjustment(FinancePayrollAdjustment $adjustment): array
    {
        return [
            'id' => (int) $adjustment->id,
            'finance_employee_id' => $adjustment->finance_employee_id ? (int) $adjustment->finance_employee_id : null,
            'employee_name' => $adjustment->financeEmployee?->full_name,
            'employee_code' => $adjustment->financeEmployee?->employee_code,
            'type' => $adjustment->type,
            'title' => $adjustment->title,
            'amount' => $this->money($adjustment->amount ?? 0),
            'effective_date' => $this->date($adjustment->effective_date),
            'status' => $adjustment->status,
            'notes' => $adjustment->notes,
            'payroll_run_id' => $adjustment->payroll_run_id ? (int) $adjustment->payroll_run_id : null,
            'posted_journal_entry_id' => $adjustment->posted_journal_entry_id ? (int) $adjustment->posted_journal_entry_id : null,
            'approved_at' => $this->date($adjustment->approved_at),
            'posted_at' => $this->date($adjustment->posted_at),
        ];
    }

    /**
     * @param  array<string, mixed>  $cards
     * @return array<string, mixed>
     */
    public function payrollOverview(array $cards, iterable $latestRecords): array
    {
        return [
            'cards' => [
                'company_employees' => (int) ($cards['company_employees'] ?? 0),
                'payroll_paid_total' => $this->money($cards['payroll_paid_total'] ?? 0),
                'allowances_bonuses_total' => $this->money($cards['allowances_bonuses_total'] ?? 0),
                'deductions_total' => $this->money($cards['deductions_total'] ?? 0),
                'open_advances_total' => $this->money($cards['open_advances_total'] ?? 0),
            ],
            'latest_records' => collect($latestRecords)
                ->map(fn (FinanceEmployeePayrollRecord $record) => $this->payrollRecord($record))
                ->values()
                ->all(),
        ];
    }

    private function publicFileUrl(?string $path): ?string
    {
        if (! filled($path)) {
            return null;
        }

        $url = Storage::disk('public')->url($path);
        if (str_starts_with($url, 'http://') || str_starts_with($url, 'https://')) {
            return $url;
        }

        return rtrim((string) config('app.url'), '/').'/'.ltrim($url, '/');
    }

    private function date(mixed $value): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }
        if ($value instanceof Carbon) {
            return $value->toDateString();
        }

        return (string) $value;
    }

    private function latestDeliveryStatus(mixed $deliveries): ?string
    {
        if ($deliveries === null) {
            return null;
        }

        $first = collect($deliveries)->first();

        return $first?->status;
    }
}
