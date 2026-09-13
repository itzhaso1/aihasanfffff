<?php

namespace App\Services\Finance;

use App\Models\Customer;
use App\Models\Finance\FinanceCreditNote;
use App\Models\Finance\FinanceInvoice;
use App\Models\Finance\FinanceInvoiceItem;
use App\Models\Finance\FinanceSetting;
use App\Models\Finance\IssuedDocumentSnapshot;
use App\Models\Order;
use App\Models\PosCashierInvoice;
use App\Models\PosCashierInvoiceItem;
use App\Models\Workspace;
use App\Support\Money\Money;
use App\Support\Tenancy\WorkspaceContext;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use RuntimeException;

class IssuedSnapshotBuilder
{
    public function captureInvoice(FinanceInvoice $invoice): IssuedDocumentSnapshot
    {
        $this->assertSameWorkspace((int) $invoice->workspace_id);

        if (! $invoice->isIssued()) {
            throw new RuntimeException('لا يمكن إنشاء لقطة إلا لمستند صادر.');
        }

        return $this->persist(
            workspaceId: (int) $invoice->workspace_id,
            sourceType: IssuedDocumentSnapshot::SOURCE_FINANCE_INVOICE,
            sourceId: (int) $invoice->id,
            documentNumber: (string) $invoice->invoice_number,
            issueDate: $invoice->issue_date?->toDateString() ?? now(config('app.timezone'))->toDateString(),
            issuedAt: $invoice->issued_at,
            currency: (string) ($invoice->currency ?: 'SAR'),
            payload: $this->invoicePayload($invoice),
        );
    }

    public function captureCreditNote(FinanceCreditNote $note): IssuedDocumentSnapshot
    {
        $this->assertSameWorkspace((int) $note->workspace_id);

        if ($note->status !== FinanceCreditNote::STATUS_ISSUED) {
            throw new RuntimeException('لا يمكن إنشاء لقطة إلا لمستند صادر.');
        }

        $sourceType = $note->isCredit()
            ? IssuedDocumentSnapshot::SOURCE_FINANCE_CREDIT_NOTE
            : IssuedDocumentSnapshot::SOURCE_FINANCE_DEBIT_NOTE;

        return $this->persist(
            workspaceId: (int) $note->workspace_id,
            sourceType: $sourceType,
            sourceId: (int) $note->id,
            documentNumber: (string) $note->note_number,
            issueDate: $note->issue_date?->toDateString() ?? now(config('app.timezone'))->toDateString(),
            issuedAt: $note->issued_at,
            currency: (string) ($note->currency ?: 'SAR'),
            payload: $this->creditNotePayload($note),
        );
    }

    public function capturePosCashierInvoice(PosCashierInvoice $invoice): IssuedDocumentSnapshot
    {
        $this->assertSameWorkspace((int) $invoice->workspace_id);

        if ($invoice->status !== 'closed') {
            throw new RuntimeException('لا يمكن إنشاء لقطة إلا لمستند صادر.');
        }

        $invoice->loadMissing([
            'items',
            'orders.customer',
            'closer',
            'session',
            'table',
        ]);

        $this->assertPosRelationsSameWorkspace($invoice);

        return $this->persist(
            workspaceId: (int) $invoice->workspace_id,
            sourceType: IssuedDocumentSnapshot::SOURCE_POS_CASHIER_INVOICE,
            sourceId: (int) $invoice->id,
            documentNumber: (string) $invoice->invoice_number,
            issueDate: $invoice->closed_at?->toDateString() ?? now(config('app.timezone'))->toDateString(),
            issuedAt: $invoice->closed_at,
            currency: (string) ($invoice->currency ?: 'USD'),
            payload: $this->posCashierInvoicePayload($invoice),
        );
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function persist(
        int $workspaceId,
        string $sourceType,
        int $sourceId,
        string $documentNumber,
        string $issueDate,
        mixed $issuedAt,
        string $currency,
        array $payload,
    ): IssuedDocumentSnapshot {
        $existing = $this->findExisting($workspaceId, $sourceType, $sourceId);
        if ($existing) {
            return $existing;
        }

        try {
            return DB::transaction(function () use (
                $workspaceId,
                $sourceType,
                $sourceId,
                $documentNumber,
                $issueDate,
                $issuedAt,
                $currency,
                $payload
            ): IssuedDocumentSnapshot {
                $existing = $this->findExisting($workspaceId, $sourceType, $sourceId);
                if ($existing) {
                    return $existing;
                }

                return IssuedDocumentSnapshot::withoutGlobalScopes()->create([
                    'workspace_id' => $workspaceId,
                    'source_type' => $sourceType,
                    'source_id' => $sourceId,
                    'document_number' => $documentNumber,
                    'issue_date' => $issueDate,
                    'issued_at' => $issuedAt,
                    'currency' => $currency,
                    'payload' => $payload,
                ]);
            });
        } catch (UniqueConstraintViolationException $exception) {
            $existing = $this->findExisting($workspaceId, $sourceType, $sourceId);
            if ($existing) {
                return $existing;
            }

            Log::warning('issued_snapshot.duplicate_unresolved', [
                'workspace_id' => $workspaceId,
                'source_type' => $sourceType,
                'source_id' => $sourceId,
            ]);

            throw $exception;
        } catch (\Throwable $exception) {
            Log::error('issued_snapshot.persist_failed', [
                'workspace_id' => $workspaceId,
                'source_type' => $sourceType,
                'source_id' => $sourceId,
                'operation' => 'persist',
                'error' => $exception->getMessage(),
            ]);

            throw $exception;
        }
    }

    private function findExisting(int $workspaceId, string $sourceType, int $sourceId): ?IssuedDocumentSnapshot
    {
        return IssuedDocumentSnapshot::withoutGlobalScopes()
            ->where('workspace_id', $workspaceId)
            ->where('source_type', $sourceType)
            ->where('source_id', $sourceId)
            ->first();
    }

    /**
     * @return array<string, mixed>
     */
    private function invoicePayload(FinanceInvoice $invoice): array
    {
        $invoice->loadMissing(['items', 'contract', 'customer', 'supplier', 'payments']);

        $seller = $this->withStructuredAddress(
            is_array($invoice->company_snapshot) ? $invoice->company_snapshot : []
        );
        $buyer = $this->withStructuredAddress(
            is_array($invoice->recipient_snapshot) ? $invoice->recipient_snapshot : []
        );
        $pdf = is_array($invoice->pdf_snapshot) ? $invoice->pdf_snapshot : [];
        $subtype = $this->financeInvoiceSubtype($invoice);

        return [
            'document' => [
                'number' => $invoice->invoice_number,
                'issue_date' => $invoice->issue_date?->toDateString(),
                'issued_at' => $invoice->issued_at?->toIso8601String(),
                'due_date' => $invoice->due_date?->toDateString(),
                'supply_date' => $invoice->supply_date?->toDateString(),
                'status' => $invoice->resolvedInvoiceStatus(),
                'type' => $invoice->type,
                'tax_document_subtype' => $subtype,
                'subtype' => $subtype,
                'currency' => $invoice->currency,
                'payment_terms' => $invoice->payment_terms,
                'notes' => $invoice->notes,
            ],
            'seller' => $seller,
            'buyer' => $buyer,
            'contract' => $this->contractSection($invoice),
            'reference' => null,
            'lines' => $invoice->items->map(fn (FinanceInvoiceItem $item): array => $this->invoiceLine($item))->values()->all(),
            'tax' => [
                'profile_type' => $invoice->tax_profile_type,
                'rate' => $this->money($invoice->tax_rate),
                'price_mode' => $invoice->tax_price_mode,
                'amount' => $this->money($invoice->tax_amount),
                'breakdown' => is_array($invoice->tax_breakdown) ? $invoice->tax_breakdown : null,
            ],
            'totals' => [
                'subtotal' => $this->money($invoice->subtotal),
                'discount' => $this->money($invoice->discount),
                'taxable_amount' => $this->money($invoice->taxable_amount),
                'tax_amount' => $this->money($invoice->tax_amount),
                'total' => $this->money($invoice->total),
                'amount_paid' => $this->money($invoice->amount_paid),
                'amount_due' => $this->money($invoice->amount_due),
                'amount_credited' => $this->money($invoice->amount_credited ?? 0),
                'amount_debited' => $this->money($invoice->amount_debited ?? 0),
            ],
            'payment' => $this->invoicePaymentSection($invoice),
            'metadata' => [
                'schema_version' => IssuedDocumentSnapshot::SCHEMA_VERSION,
                'source_type' => IssuedDocumentSnapshot::SOURCE_FINANCE_INVOICE,
                'source_id' => (int) $invoice->id,
                'workspace_id' => (int) $invoice->workspace_id,
                'pdf' => $pdf,
                'legacy_invoice_snapshots' => true,
            ],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function creditNotePayload(FinanceCreditNote $note): array
    {
        $note->loadMissing(['items', 'invoice', 'customer']);
        $invoice = $note->invoice;
        if ($invoice && (int) $invoice->workspace_id !== (int) $note->workspace_id) {
            throw new RuntimeException('Cross-workspace credit note reference is not allowed.');
        }

        $seller = $this->withStructuredAddress(
            is_array($invoice?->company_snapshot) && $invoice->company_snapshot !== []
                ? $invoice->company_snapshot
                : $this->sellerFromSettings((int) $note->workspace_id)
        );
        $buyer = $this->withStructuredAddress(
            is_array($invoice?->recipient_snapshot) && $invoice->recipient_snapshot !== []
                ? $invoice->recipient_snapshot
                : $this->customerParty($note->customer)
        );
        $subtype = $this->noteSubtypeFromOriginal($invoice);

        return [
            'document' => [
                'number' => $note->note_number,
                'issue_date' => $note->issue_date?->toDateString(),
                'issued_at' => $note->issued_at?->toIso8601String(),
                'supply_date' => null,
                'status' => $note->status,
                'type' => $note->type,
                'tax_document_subtype' => $subtype,
                'subtype' => $subtype,
                'reason' => $this->nullableSnapshotString($note->reason),
                'currency' => $note->currency,
                'notes' => $note->notes,
            ],
            'seller' => $seller,
            'buyer' => $buyer,
            'contract' => $invoice ? $this->contractSection($invoice) : null,
            'reference' => [
                'invoice_id' => $invoice?->id,
                'invoice_number' => $invoice?->invoice_number,
                'invoice_issue_date' => $invoice?->issue_date?->toDateString(),
                'invoice_type' => $invoice?->type,
                'invoice_tax_document_subtype' => $subtype,
            ],
            'lines' => $note->items->map(fn ($item): array => [
                'description' => $item->description ?: $item->product_name,
                'product_name' => $item->product_name,
                'quantity' => $this->quantity($item->quantity),
                'unit_code' => $this->nullableSnapshotString($item->unit_code ?? null),
                'unit_price' => $this->money($item->unit_price),
                'discount' => $this->money($item->discount),
                'taxable_amount' => $this->money($item->taxable_amount),
                'tax_profile_type' => $item->tax_profile_type,
                'tax_rate' => $this->money($item->tax_rate),
                'tax_amount' => $this->money($item->tax_amount),
                'total' => $this->money($item->total),
                'exemption_reason' => $item->exemption_reason ?? null,
                'exemption_code' => $item->exemption_code ?? null,
            ])->values()->all(),
            'tax' => [
                'profile_type' => $note->tax_profile_type,
                'rate' => $this->money($note->tax_rate),
                'price_mode' => $note->tax_price_mode,
                'amount' => $this->money($note->tax_amount),
                'breakdown' => is_array($note->tax_breakdown) ? $note->tax_breakdown : null,
            ],
            'totals' => [
                'subtotal' => $this->money($note->subtotal),
                'discount' => $this->money($note->discount),
                'taxable_amount' => $this->money($note->taxable_amount),
                'tax_amount' => $this->money($note->tax_amount),
                'total' => $this->money($note->total),
            ],
            'payment' => [
                'business_methods' => [],
                'regulatory_code' => null,
            ],
            'metadata' => [
                'schema_version' => IssuedDocumentSnapshot::SCHEMA_VERSION,
                'source_type' => $note->isCredit()
                    ? IssuedDocumentSnapshot::SOURCE_FINANCE_CREDIT_NOTE
                    : IssuedDocumentSnapshot::SOURCE_FINANCE_DEBIT_NOTE,
                'source_id' => (int) $note->id,
                'workspace_id' => (int) $note->workspace_id,
            ],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function posCashierInvoicePayload(PosCashierInvoice $invoice): array
    {
        $orders = $invoice->orders
            ->filter(fn (Order $order): bool => (int) $order->workspace_id === (int) $invoice->workspace_id)
            ->values();
        $taxAmount = $this->money(
            $invoice->tax_amount !== null
                ? $invoice->tax_amount
                : $orders->sum(fn (Order $order): float => (float) $order->tax_amount)
        );
        $subtotal = $this->money($invoice->subtotal);
        $discount = $this->money($invoice->discount_amount);
        $total = $this->money($invoice->total_amount);
        $taxable = $this->money(
            $invoice->taxable_amount !== null
                ? $invoice->taxable_amount
                : max(0, (float) $invoice->subtotal - (float) $invoice->discount_amount)
        );
        $allPaid = $orders->isNotEmpty()
            && $orders->every(fn (Order $order): bool => $order->payment_status === 'paid');
        $amountPaid = $allPaid ? $total : $this->money(0);
        $amountDue = $allPaid ? $this->money(0) : $total;
        $workspace = Workspace::query()->find((int) $invoice->workspace_id);
        $configuredRate = $this->posPersistedTaxRate($invoice, $orders);
        $subtype = $this->nullableSnapshotString($invoice->tax_document_subtype ?? null);
        $paymentMethods = $orders
            ->map(fn (Order $order): ?string => is_array($order->metadata)
                ? (isset($order->metadata['payment_method']) ? (string) $order->metadata['payment_method'] : null)
                : null)
            ->filter()
            ->unique()
            ->values()
            ->all();

        return [
            'document' => [
                'number' => $invoice->invoice_number,
                'issue_date' => $invoice->closed_at?->toDateString(),
                'issued_at' => $invoice->closed_at?->toIso8601String(),
                'supply_date' => null,
                'status' => $invoice->status,
                'type' => 'pos_cashier_invoice',
                'tax_document_subtype' => $subtype,
                'subtype' => $subtype,
                'currency' => $invoice->currency,
                'source' => 'pos',
                'notes' => is_array($invoice->metadata) ? ($invoice->metadata['notes'] ?? null) : null,
                'local_invoice_number' => is_array($invoice->metadata)
                    ? ($invoice->metadata['local_invoice_number'] ?? null)
                    : null,
            ],
            'seller' => $this->posSeller($invoice, $workspace),
            'buyer' => $this->posBuyer($invoice, $orders),
            'contract' => null,
            'reference' => [
                'order_ids' => $orders->pluck('id')->map(fn ($id): int => (int) $id)->values()->all(),
                'order_numbers' => $orders->pluck('order_number')->filter()->values()->all(),
                'client_references' => $orders->pluck('client_reference')->filter()->values()->all(),
                'table_session_id' => $invoice->table_session_id ? (int) $invoice->table_session_id : null,
                'dining_table_id' => $invoice->dining_table_id ? (int) $invoice->dining_table_id : null,
                'table_name' => $invoice->table?->name
                    ?? (is_array($invoice->metadata) ? ($invoice->metadata['table_name'] ?? null) : null),
            ],
            'lines' => $invoice->items
                ->map(fn (PosCashierInvoiceItem $item): array => $this->posInvoiceLine($item))
                ->values()
                ->all(),
            'tax' => [
                'engine' => 'pos',
                'configured_rate' => $configuredRate > 0 ? $this->money($configuredRate) : $this->money(0),
                'amount' => $taxAmount,
                'profile_type' => null,
                'price_mode' => null,
                'breakdown' => null,
            ],
            'totals' => [
                'subtotal' => $subtotal,
                'discount' => $discount,
                'taxable_amount' => $taxable,
                'tax_amount' => $taxAmount,
                'total' => $total,
                'amount_paid' => $amountPaid,
                'amount_due' => $amountDue,
            ],
            'payment' => [
                'payment_status' => $this->posPaymentStatus($orders),
                'payment_methods' => $paymentMethods,
                'business_methods' => $paymentMethods,
                'regulatory_code' => null,
                'amount_paid' => $amountPaid,
                'amount_due' => $amountDue,
            ],
            'cashier' => [
                'closed_by_user_id' => $invoice->closed_by_user_id ? (int) $invoice->closed_by_user_id : null,
                'closed_by_name' => $invoice->closer?->name,
                'table_session_id' => $invoice->table_session_id ? (int) $invoice->table_session_id : null,
                'dining_table_id' => $invoice->dining_table_id ? (int) $invoice->dining_table_id : null,
            ],
            'metadata' => [
                'schema_version' => IssuedDocumentSnapshot::SCHEMA_VERSION,
                'source_type' => IssuedDocumentSnapshot::SOURCE_POS_CASHIER_INVOICE,
                'source_id' => (int) $invoice->id,
                'workspace_id' => (int) $invoice->workspace_id,
                'orders_count' => $orders->count(),
            ],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function posInvoiceLine(PosCashierInvoiceItem $item): array
    {
        return [
            'description' => $item->item_name,
            'pos_menu_item_id' => $item->pos_menu_item_id ? (int) $item->pos_menu_item_id : null,
            'product_name' => $item->item_name,
            'item_type' => $item->item_type,
            'size_label' => $item->size_label,
            'quantity' => $this->quantity($item->quantity),
            'unit_code' => $this->nullableSnapshotString($item->unit_code ?? null),
            'unit_price' => $this->money($item->unit_price),
            'discount' => $this->money($item->discount_amount),
            'subtotal' => $this->money($item->total_amount),
            'tax_rate' => $item->tax_rate !== null ? $this->money($item->tax_rate) : null,
            'tax_amount' => $item->tax_amount !== null ? $this->money($item->tax_amount) : null,
            'total' => $this->money($item->total_amount),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function posSeller(PosCashierInvoice $invoice, ?Workspace $workspace): array
    {
        $seller = $this->sellerFromSettings((int) $invoice->workspace_id);
        $seller['workspace_id'] = (int) $invoice->workspace_id;
        $seller['workspace_name'] = $workspace?->name;
        if (blank($seller['company_name'] ?? null)) {
            $seller['company_name'] = $workspace?->name;
        }

        return $this->withStructuredAddress($seller);
    }

    /**
     * @param  Collection<int, Order>  $orders
     * @return array<string, mixed>
     */
    private function posBuyer(PosCashierInvoice $invoice, Collection $orders): array
    {
        $customer = $orders
            ->map(fn (Order $order): ?Customer => $order->customer)
            ->filter(fn (?Customer $customer): bool => $customer !== null
                && (int) $customer->workspace_id === (int) $invoice->workspace_id)
            ->first();

        if (! $customer) {
            return $this->withStructuredAddress([
                'kind' => 'anonymous',
                'walk_in' => true,
                'name' => null,
                'vat_number' => null,
                'commercial_registration' => null,
                'address' => null,
                'phone' => null,
                'email' => null,
            ]);
        }

        return $this->withStructuredAddress($this->customerParty($customer, walkIn: false));
    }

    /**
     * @param  Collection<int, Order>  $orders
     */
    private function posPersistedTaxRate(PosCashierInvoice $invoice, Collection $orders): float
    {
        if ($invoice->tax_rate !== null && $invoice->tax_rate !== '') {
            return (float) $invoice->tax_rate;
        }

        $rates = $orders
            ->pluck('tax_rate')
            ->filter(fn ($rate): bool => $rate !== null && $rate !== '')
            ->map(fn ($rate): float => (float) $rate)
            ->unique()
            ->values();

        if ($rates->count() === 1) {
            return (float) $rates->first();
        }

        return 0.0;
    }

    /**
     * @param  Collection<int, Order>  $orders
     */
    private function posPaymentStatus(Collection $orders): string
    {
        if ($orders->isEmpty()) {
            return 'pending';
        }

        if ($orders->every(fn (Order $order): bool => $order->payment_status === 'paid')) {
            return 'paid';
        }

        if ($orders->contains(fn (Order $order): bool => $order->payment_status === 'paid')) {
            return 'mixed';
        }

        return (string) ($orders->first()?->payment_status ?: 'pending');
    }

    private function assertPosRelationsSameWorkspace(PosCashierInvoice $invoice): void
    {
        foreach ($invoice->orders as $order) {
            if ((int) $order->workspace_id !== (int) $invoice->workspace_id) {
                throw new RuntimeException('Cross-workspace POS snapshot creation is not allowed.');
            }
        }
    }

    /**
     * @return array<string, mixed>
     */
    private function invoiceLine(FinanceInvoiceItem $item): array
    {
        return [
            'description' => $item->description ?: $item->product_name,
            'product_id' => $item->product_id,
            'product_name' => $item->product_name,
            'quantity' => $this->quantity($item->quantity),
            'unit_code' => $this->nullableSnapshotString($item->unit_code ?? null),
            'unit' => FinanceInvoiceItem::hasUnitColumn()
                ? $this->nullableSnapshotString($item->unit ?? null)
                : null,
            'unit_price' => $this->money($item->unit_price),
            'discount' => $this->money($item->discount),
            'taxable_amount' => $this->money($item->taxable_amount),
            'tax_profile_type' => $item->tax_profile_type,
            'tax_rate' => $this->money($item->tax_rate),
            'tax_amount' => $this->money($item->tax_amount),
            'total' => $this->money($item->total),
            'exemption_reason' => $item->exemption_reason ?? null,
            'exemption_code' => $item->exemption_code ?? null,
        ];
    }

    /**
     * @return array<string, mixed>|null
     */
    private function contractSection(FinanceInvoice $invoice): ?array
    {
        $contract = $invoice->contract;
        if (! $contract) {
            return $invoice->contract_id ? ['id' => (int) $invoice->contract_id] : null;
        }

        return [
            'id' => (int) $contract->id,
            'contract_number' => $contract->contract_number,
            'title' => $contract->title,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function sellerFromSettings(int $workspaceId): array
    {
        $setting = FinanceSetting::forWorkspaceId($workspaceId);

        return [
            'company_name' => $setting?->company_name,
            'company_name_ar' => $setting?->company_name_ar,
            'vat_number' => $setting?->vat_number,
            'commercial_registration' => $setting?->commercial_registration,
            'address_line' => $setting?->address_line,
            'building_number' => $setting?->building_number,
            'street' => $setting?->street,
            'district' => $setting?->district,
            'city' => $setting?->city,
            'postal_code' => $setting?->postal_code,
            'country_code' => $setting?->country_code,
            'phone' => $setting?->phone,
            'email' => $setting?->email,
            'website' => $setting?->website,
            'currency' => $setting?->currency,
            'logo_path' => $setting?->logo_path,
        ];
    }

    private function assertSameWorkspace(int $documentWorkspaceId): void
    {
        $contextId = app(WorkspaceContext::class)->workspaceId();
        if ($contextId !== null && (int) $contextId !== $documentWorkspaceId) {
            throw new RuntimeException('Cross-workspace snapshot creation is not allowed.');
        }
    }

    /**
     * @return array<string, mixed>
     */
    private function invoicePaymentSection(FinanceInvoice $invoice): array
    {
        $methods = $invoice->payments
            ->filter(fn ($payment): bool => method_exists($payment, 'isPosted') ? $payment->isPosted() : true)
            ->map(fn ($payment): ?string => $this->nullableSnapshotString($payment->method ?? null))
            ->filter()
            ->unique()
            ->values()
            ->all();

        return [
            'payment_status' => $invoice->payment_status,
            'amount_paid' => $this->money($invoice->amount_paid),
            'amount_due' => $this->money($invoice->amount_due),
            'business_methods' => $methods,
            'regulatory_code' => null,
        ];
    }

    private function financeInvoiceSubtype(FinanceInvoice $invoice): string
    {
        $raw = $this->nullableSnapshotString($invoice->tax_document_subtype);

        return $raw ?? 'standard';
    }

    private function noteSubtypeFromOriginal(?FinanceInvoice $invoice): ?string
    {
        if ($invoice === null) {
            return null;
        }

        return $this->financeInvoiceSubtype($invoice);
    }

    /**
     * @return array<string, mixed>
     */
    private function customerParty(?Customer $customer, bool $walkIn = false): array
    {
        if ($customer === null) {
            return [
                'kind' => 'customer',
                'walk_in' => $walkIn,
                'name' => null,
                'vat_number' => null,
                'commercial_registration' => null,
                'address' => null,
                'phone' => null,
                'email' => null,
            ];
        }

        return [
            'kind' => 'customer',
            'walk_in' => $walkIn,
            'id' => (int) $customer->id,
            'name' => $customer->name,
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
            'phone' => $customer->phone,
            'email' => $customer->email,
        ];
    }

    /**
     * @param  array<string, mixed>  $party
     * @return array<string, mixed>
     */
    private function withStructuredAddress(array $party): array
    {
        $existing = $party['address'] ?? null;
        $line = is_string($existing)
            ? $existing
            : ($party['address_line'] ?? (is_array($existing) ? ($existing['line'] ?? null) : null));

        $address = [
            'line' => $this->nullableSnapshotString($line),
            'street' => $this->nullableSnapshotString($party['street'] ?? (is_array($existing) ? ($existing['street'] ?? null) : null)),
            'building_number' => $this->nullableSnapshotString($party['building_number'] ?? (is_array($existing) ? ($existing['building_number'] ?? null) : null)),
            'additional_number' => $this->nullableSnapshotString($party['additional_number'] ?? (is_array($existing) ? ($existing['additional_number'] ?? null) : null)),
            'district' => $this->nullableSnapshotString($party['district'] ?? (is_array($existing) ? ($existing['district'] ?? null) : null)),
            'city' => $this->nullableSnapshotString($party['city'] ?? (is_array($existing) ? ($existing['city'] ?? null) : null)),
            'postal_code' => $this->nullableSnapshotString($party['postal_code'] ?? (is_array($existing) ? ($existing['postal_code'] ?? null) : null)),
            'country_code' => $this->nullableSnapshotString($party['country_code'] ?? (is_array($existing) ? ($existing['country_code'] ?? null) : null)),
        ];

        $party['address'] = $address;
        $party['address_line'] = $party['address_line'] ?? $address['line'];
        $party['street'] = $address['street'];
        $party['building_number'] = $address['building_number'];
        $party['additional_number'] = $address['additional_number'];
        $party['district'] = $address['district'];
        $party['city'] = $address['city'];
        $party['postal_code'] = $address['postal_code'];
        $party['country_code'] = $address['country_code'];

        return $party;
    }

    private function nullableSnapshotString(mixed $value): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }

        if (! is_string($value) && ! is_numeric($value)) {
            return null;
        }

        $string = (string) $value;

        return $string === '' ? null : $string;
    }

    private function money(mixed $value): string
    {
        return Money::of($value ?? 0);
    }

    private function quantity(mixed $value): string
    {
        return number_format((float) $value, 3, '.', '');
    }
}
