<?php

namespace App\Services\Finance;

use App\Enums\Finance\QuoteOutcomeStatus;
use App\Enums\Finance\QuoteStatus;
use App\Enums\Finance\TaxPriceMode;
use App\Models\Customer;
use App\Models\Finance\FinanceQuote;
use App\Models\Finance\FinanceQuoteAttachment;
use App\Models\Finance\FinanceQuoteItem;
use App\Models\Finance\FinanceSetting;
use App\Models\Product;
use App\Models\User;
use App\Models\Workspace;
use App\Services\Audit\AuditLogService;
use App\Services\Finance\Tax\TaxCalculationService;
use App\Support\Money\Money;
use App\Support\Uploads\SecureUpload;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use RuntimeException;

class QuoteService
{
    public function __construct(
        private readonly TaxCalculationService $taxCalculator,
        private readonly InvoiceService $invoiceService,
        private readonly AuditLogService $auditLogService,
    ) {}

    /**
     * @param  array<string, mixed>  $payload
     */
    public function create(Workspace $workspace, array $payload, int $actorUserId): FinanceQuote
    {
        $profile = $this->resolveTaxProfile($workspace, $payload);

        return DB::transaction(function () use ($workspace, $payload, $actorUserId, $profile): FinanceQuote {
            $customer = $this->requireCustomer((int) $workspace->id, $payload['customer_id'] ?? null);
            $taxResult = $this->calculateQuoteItems($workspace, $payload['items'] ?? [], $profile, $payload);
            $items = $taxResult['items'];
            if ($items === []) {
                throw new RuntimeException('يجب أن يحتوي عرض السعر على بند واحد على الأقل.');
            }

            $totals = $taxResult['result']->totalsArray();
            $headerProfile = $taxResult['result']->headerProfile($profile);
            $settings = $this->settingsForWorkspace((int) $workspace->id);
            $snapshots = $this->captureSnapshots($customer, $settings);
            $requested = QuoteStatus::tryFrom((string) ($payload['status'] ?? QuoteStatus::Draft->value))
                ?? QuoteStatus::Draft;

            $attributes = [
                'workspace_id' => $workspace->id,
                'customer_id' => $customer->id,
                'quote_number' => $this->nextQuoteNumber((int) $workspace->id),
                'status' => QuoteStatus::Draft->value,
                'outcome' => QuoteOutcomeStatus::Pending->value,
                'issue_date' => (string) $payload['issue_date'],
                'expiry_date' => ($payload['expiry_date'] ?? null) ?: null,
                'currency' => (string) ($payload['currency'] ?? 'SAR'),
                'subtotal' => $totals['subtotal'],
                'discount' => $totals['discount'],
                'taxable_amount' => $totals['taxable_amount'],
                'tax_amount' => $totals['tax_amount'],
                'total' => $totals['total'],
                'tax_profile_type' => $headerProfile['type'],
                'tax_rate' => Money::round($headerProfile['rate']),
                'tax_price_mode' => $taxResult['result']->priceMode->value,
                'tax_breakdown' => $taxResult['result']->categoryTotalsToArray(),
                'notes' => ($payload['notes'] ?? null) ?: null,
                'terms' => ($payload['terms'] ?? null) ?: null,
                'company_snapshot' => $snapshots['company'],
                'recipient_snapshot' => $snapshots['recipient'],
                'pdf_snapshot' => $snapshots['pdf'],
                'created_by' => $actorUserId,
            ];

            try {
                $quote = FinanceQuote::withoutGlobalScopes()->create($attributes);
            } catch (UniqueConstraintViolationException) {
                throw new RuntimeException('رقم عرض السعر مستخدم مسبقاً في هذه المنشأة.');
            }

            foreach ($items as $item) {
                $this->createQuoteItem((int) $workspace->id, (int) $quote->id, $item);
            }

            $this->storeUploadedAttachments($quote, $payload['attachments'] ?? [], $actorUserId);

            if ($requested === QuoteStatus::Issued) {
                return $this->issue($quote->fresh(['items', 'customer', 'attachments']), $actorUserId);
            }

            return $quote->load(['items', 'customer', 'attachments']);
        });
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    public function updateDraft(FinanceQuote $quote, array $payload, int $actorUserId): FinanceQuote
    {
        if (! $quote->isDraft()) {
            throw new RuntimeException('يمكن تعديل مسودات عروض الأسعار فقط.');
        }

        return DB::transaction(function () use ($quote, $payload, $actorUserId): FinanceQuote {
            $locked = FinanceQuote::withoutGlobalScopes()->whereKey($quote->id)->lockForUpdate()->firstOrFail();
            if (! $locked->isDraft()) {
                throw new RuntimeException('يمكن تعديل مسودات عروض الأسعار فقط.');
            }

            $workspace = Workspace::query()->findOrFail((int) $locked->workspace_id);
            $profile = $this->resolveTaxProfile($workspace, $payload);
            $customerId = array_key_exists('customer_id', $payload)
                ? ($payload['customer_id'] ?? null)
                : $locked->customer_id;
            $customer = $this->requireCustomer((int) $workspace->id, $customerId);
            $taxResult = $this->calculateQuoteItems($workspace, $payload['items'] ?? [], $profile, $payload);
            $items = $taxResult['items'];
            if ($items === []) {
                throw new RuntimeException('يجب أن يحتوي عرض السعر على بند واحد على الأقل.');
            }

            $totals = $taxResult['result']->totalsArray();
            $headerProfile = $taxResult['result']->headerProfile($profile);
            $settings = $this->settingsForWorkspace((int) $workspace->id);
            $snapshots = $this->captureSnapshots($customer, $settings);

            $locked->update([
                'customer_id' => $customer->id,
                'issue_date' => (string) ($payload['issue_date'] ?? $locked->issue_date?->toDateString()),
                'expiry_date' => array_key_exists('expiry_date', $payload)
                    ? (($payload['expiry_date'] ?? null) ?: null)
                    : $locked->expiry_date?->toDateString(),
                'currency' => (string) ($payload['currency'] ?? $locked->currency ?? 'SAR'),
                'subtotal' => $totals['subtotal'],
                'discount' => $totals['discount'],
                'taxable_amount' => $totals['taxable_amount'],
                'tax_amount' => $totals['tax_amount'],
                'total' => $totals['total'],
                'tax_profile_type' => $headerProfile['type'],
                'tax_rate' => Money::round($headerProfile['rate']),
                'tax_price_mode' => $taxResult['result']->priceMode->value,
                'tax_breakdown' => $taxResult['result']->categoryTotalsToArray(),
                'notes' => array_key_exists('notes', $payload) ? (($payload['notes'] ?? null) ?: null) : $locked->notes,
                'terms' => array_key_exists('terms', $payload) ? (($payload['terms'] ?? null) ?: null) : $locked->terms,
                'company_snapshot' => $snapshots['company'],
                'recipient_snapshot' => $snapshots['recipient'],
                'pdf_snapshot' => $snapshots['pdf'],
            ]);

            FinanceQuoteItem::withoutGlobalScopes()
                ->where('quote_id', $locked->id)
                ->get()
                ->each(fn (FinanceQuoteItem $item) => $item->delete());

            foreach ($items as $item) {
                $this->createQuoteItem((int) $workspace->id, (int) $locked->id, $item);
            }

            $this->storeUploadedAttachments($locked, $payload['attachments'] ?? [], $actorUserId);

            return $locked->fresh(['items', 'customer', 'attachments']);
        });
    }

    public function issue(FinanceQuote $quote, int $actorUserId): FinanceQuote
    {
        return DB::transaction(function () use ($quote, $actorUserId): FinanceQuote {
            $locked = FinanceQuote::withoutGlobalScopes()->whereKey($quote->id)->lockForUpdate()->firstOrFail();

            if ($locked->isIssued()) {
                return $locked->fresh(['items', 'customer']);
            }
            if ($locked->isCancelled()) {
                throw new RuntimeException('لا يمكن إصدار عرض سعر ملغى.');
            }

            $customer = $this->requireCustomer((int) $locked->workspace_id, $locked->customer_id);
            $settings = $this->settingsForWorkspace((int) $locked->workspace_id);
            $snapshots = $this->captureSnapshots($customer, $settings);

            $locked->update([
                'status' => QuoteStatus::Issued->value,
                'outcome' => QuoteOutcomeStatus::Pending->value,
                'issued_by' => $actorUserId,
                'issued_at' => now(config('app.timezone')),
                'company_snapshot' => $snapshots['company'],
                'recipient_snapshot' => $snapshots['recipient'],
                'pdf_snapshot' => $snapshots['pdf'],
            ]);

            return $locked->fresh(['items', 'customer']);
        });
    }

    public function cancel(FinanceQuote $quote): FinanceQuote
    {
        return DB::transaction(function () use ($quote): FinanceQuote {
            $locked = FinanceQuote::withoutGlobalScopes()->whereKey($quote->id)->lockForUpdate()->firstOrFail();

            if ($locked->isCancelled()) {
                return $locked;
            }
            if ($locked->isDraft()) {
                throw new RuntimeException('احذف المسودة بدل إلغائها، أو أصدرها أولاً.');
            }
            if ($locked->isConverted()) {
                throw new RuntimeException('لا يمكن إلغاء عرض تم تحويله إلى فاتورة.');
            }

            $locked->update([
                'status' => QuoteStatus::Cancelled->value,
                'cancelled_at' => now(config('app.timezone')),
            ]);

            return $locked->fresh(['items', 'customer']);
        });
    }

    public function deleteDraft(FinanceQuote $quote): void
    {
        DB::transaction(function () use ($quote): void {
            $locked = FinanceQuote::withoutGlobalScopes()->whereKey($quote->id)->lockForUpdate()->firstOrFail();
            if ($locked->isLocked()) {
                throw new RuntimeException('لا يمكن حذف عرض سعر صادر أو ملغى.');
            }
            $locked->delete();
        });
    }

    /**
     * Internal commercial acceptance. Does not create an invoice or post GL.
     */
    public function accept(FinanceQuote $quote, int $actorUserId): FinanceQuote
    {
        return DB::transaction(function () use ($quote, $actorUserId): FinanceQuote {
            $locked = FinanceQuote::withoutGlobalScopes()
                ->where('workspace_id', $quote->workspace_id)
                ->whereKey($quote->id)
                ->lockForUpdate()
                ->firstOrFail();

            if ($locked->isPastExpiry()) {
                throw new RuntimeException('انتهت صلاحية العرض ولا يمكن قبوله.');
            }

            if (! $locked->isAcceptable()) {
                throw new RuntimeException('يمكن قبول العروض الصادرة المعلقة فقط.');
            }

            $locked->update([
                'outcome' => QuoteOutcomeStatus::Accepted->value,
                'accepted_at' => now(config('app.timezone')),
                'accepted_by' => $actorUserId,
            ]);

            $fresh = $locked->fresh(['items', 'customer']);
            $this->recordOutcomeAudit(
                action: 'quote_accepted',
                quote: $fresh,
                actorUserId: $actorUserId,
                newValues: [
                    'outcome' => QuoteOutcomeStatus::Accepted->value,
                    'accepted_at' => optional($fresh->accepted_at)?->toIso8601String(),
                    'accepted_by' => $actorUserId,
                ],
            );

            return $fresh;
        });
    }

    /**
     * Internal commercial rejection. Does not create an invoice.
     */
    public function reject(FinanceQuote $quote, int $actorUserId, ?string $reason = null): FinanceQuote
    {
        $reason = is_string($reason) ? trim($reason) : null;
        if ($reason === '') {
            $reason = null;
        }

        return DB::transaction(function () use ($quote, $actorUserId, $reason): FinanceQuote {
            $locked = FinanceQuote::withoutGlobalScopes()
                ->where('workspace_id', $quote->workspace_id)
                ->whereKey($quote->id)
                ->lockForUpdate()
                ->firstOrFail();

            if (! $locked->isRejectable()) {
                throw new RuntimeException('يمكن رفض العروض الصادرة المعلقة فقط.');
            }

            $locked->update([
                'outcome' => QuoteOutcomeStatus::Rejected->value,
                'rejected_at' => now(config('app.timezone')),
                'rejected_by' => $actorUserId,
                'rejection_reason' => $reason,
            ]);

            $fresh = $locked->fresh(['items', 'customer']);
            $this->recordOutcomeAudit(
                action: 'quote_rejected',
                quote: $fresh,
                actorUserId: $actorUserId,
                newValues: [
                    'outcome' => QuoteOutcomeStatus::Rejected->value,
                    'rejected_at' => optional($fresh->rejected_at)?->toIso8601String(),
                    'rejected_by' => $actorUserId,
                    'rejection_reason' => $reason,
                ],
                extraMeta: ['rejection_reason' => $reason],
            );

            return $fresh;
        });
    }

    /**
     * Convert an accepted quote into a draft sales invoice via InvoiceService.
     * Does not issue, post GL, or create ZATCA snapshots.
     */
    public function convert(FinanceQuote $quote, int $actorUserId): FinanceQuote
    {
        return DB::transaction(function () use ($quote, $actorUserId): FinanceQuote {
            $locked = FinanceQuote::withoutGlobalScopes()
                ->where('workspace_id', $quote->workspace_id)
                ->whereKey($quote->id)
                ->lockForUpdate()
                ->firstOrFail();

            if ($locked->isConverted() && $locked->converted_invoice_id) {
                return $locked->fresh(['items', 'customer', 'convertedInvoice']);
            }

            if ($locked->isPastExpiry()) {
                throw new RuntimeException('انتهت صلاحية العرض ولا يمكن تحويله إلى فاتورة.');
            }

            if (! $locked->isConvertible()) {
                throw new RuntimeException('يمكن تحويل العروض الصادرة المقبولة فقط، ولمرة واحدة.');
            }

            $workspace = Workspace::query()->findOrFail((int) $locked->workspace_id);
            $this->requireCustomer((int) $workspace->id, $locked->customer_id);

            $invoice = $this->invoiceService->create(
                $workspace,
                $this->invoicePayloadFromQuote($locked),
                $actorUserId,
            );

            if ((int) $invoice->workspace_id !== (int) $locked->workspace_id) {
                throw new RuntimeException('لا يمكن إنشاء فاتورة في مساحة عمل مختلفة.');
            }

            $locked->update([
                'outcome' => QuoteOutcomeStatus::Converted->value,
                'converted_invoice_id' => $invoice->id,
                'converted_at' => now(config('app.timezone')),
                'converted_by' => $actorUserId,
            ]);

            $fresh = $locked->fresh(['items', 'customer', 'convertedInvoice']);
            $this->recordOutcomeAudit(
                action: 'quote_converted',
                quote: $fresh,
                actorUserId: $actorUserId,
                newValues: [
                    'outcome' => QuoteOutcomeStatus::Converted->value,
                    'converted_invoice_id' => $invoice->id,
                    'invoice_number' => $invoice->invoice_number,
                    'converted_at' => optional($fresh->converted_at)?->toIso8601String(),
                    'converted_by' => $actorUserId,
                ],
                extraMeta: [
                    'invoice_id' => $invoice->id,
                    'invoice_number' => $invoice->invoice_number,
                ],
            );

            return $fresh;
        });
    }

    /**
     * @return array<string, mixed>
     */
    private function invoicePayloadFromQuote(FinanceQuote $quote): array
    {
        $quoteItems = FinanceQuoteItem::withoutGlobalScopes()
            ->where('quote_id', $quote->id)
            ->orderBy('id')
            ->get();

        $items = [];
        foreach ($quoteItems as $item) {
            $items[] = [
                'product_id' => $item->product_id,
                'product_name' => $item->product_name,
                'description' => $item->description,
                'unit' => $item->unit,
                'unit_code' => $item->unit_code,
                'quantity' => $item->quantity,
                'unit_price' => $item->unit_price,
                'discount' => $item->discount,
                'tax_profile_type' => $item->tax_profile_type,
                'tax_type' => $item->tax_profile_type,
                'tax_rate' => $item->tax_rate,
                'exemption_reason' => $item->exemption_reason,
                'exemption_code' => $item->exemption_code,
            ];
        }

        $notes = trim((string) ($quote->notes ?? ''));
        $sourceNote = 'مبني على عرض السعر '.$quote->quote_number;
        $notes = $notes === '' ? $sourceNote : $notes."\n".$sourceNote;

        return [
            'type' => 'sales',
            'customer_id' => $quote->customer_id,
            'invoice_status' => 'draft',
            'status' => 'draft',
            'issue_date' => now(config('app.timezone'))->toDateString(),
            'due_date' => $quote->expiry_date?->toDateString(),
            'currency' => $quote->currency,
            'tax_profile_type' => $quote->tax_profile_type,
            'tax_rate' => $quote->tax_rate,
            'tax_price_mode' => $quote->tax_price_mode,
            'notes' => $notes,
            'payment_terms' => $quote->terms,
            'items' => $items,
        ];
    }

    /**
     * @param  array<string, mixed>  $newValues
     * @param  array<string, mixed>  $extraMeta
     */
    private function recordOutcomeAudit(
        string $action,
        FinanceQuote $quote,
        int $actorUserId,
        array $newValues,
        array $extraMeta = [],
    ): void {
        $actor = $actorUserId > 0 ? User::query()->find($actorUserId) : null;

        $this->auditLogService->log(
            action: $action,
            entityType: FinanceQuote::class,
            entityId: (int) $quote->id,
            oldValues: null,
            newValues: $newValues,
            actor: $actor instanceof User ? $actor : null,
            workspaceId: (int) $quote->workspace_id,
            meta: array_merge([
                'quote_id' => $quote->id,
                'quote_number' => $quote->quote_number,
                'workspace_id' => $quote->workspace_id,
            ], $extraMeta),
        );
    }

    /**
     * @param  array<int, mixed>  $rawItems
     * @param  array{type:string, rate:float}  $defaultProfile
     * @param  array<string, mixed>  $payload
     * @return array{items: array<int, array<string, mixed>>, result: \App\Services\Finance\Tax\TaxCalculationResult}
     */
    private function calculateQuoteItems(Workspace $workspace, array $rawItems, array $defaultProfile, array $payload): array
    {
        $prepared = $this->prepareLineInputs($rawItems, (int) $workspace->id);
        if ($prepared === []) {
            throw new RuntimeException('يجب أن يحتوي عرض السعر على بند واحد على الأقل.');
        }

        $priceMode = TaxPriceMode::tryFrom((string) ($payload['tax_price_mode'] ?? TaxPriceMode::Exclusive->value))
            ?? TaxPriceMode::Exclusive;

        $result = $this->taxCalculator->calculateDocument(
            $workspace,
            $prepared,
            $priceMode,
            $defaultProfile['type'],
            $defaultProfile['rate'],
        );

        $items = [];
        foreach ($result->lines as $index => $line) {
            $source = $prepared[$index];
            $items[] = [
                'product_id' => $source['product_id'],
                'product_name' => $source['product_name'],
                'description' => $source['description'],
                'quantity' => $line->quantity,
                'unit_price' => $line->unitPrice,
                'discount' => $line->discountAmount,
                'tax_profile_type' => $line->classification->value,
                'tax_rate' => $line->taxRate,
                'tax_amount' => $line->taxAmount,
                'taxable_amount' => $line->taxableAmount,
                'total' => $line->total,
                'exemption_reason' => $line->exemptionReason,
                'exemption_code' => $line->exemptionCode,
                'unit_code' => $source['unit_code'] ?? null,
                'unit' => $source['unit'] ?? null,
                'metadata' => $source['metadata'],
            ];
        }

        return ['items' => $items, 'result' => $result];
    }

    /**
     * @param  array<int, mixed>  $rawItems
     * @return array<int, array<string, mixed>>
     */
    private function prepareLineInputs(array $rawItems, int $workspaceId): array
    {
        $items = [];

        foreach ($rawItems as $rawItem) {
            if (! is_array($rawItem)) {
                continue;
            }

            $productName = trim((string) ($rawItem['product_name'] ?? ''));
            $description = trim((string) ($rawItem['description'] ?? ''));
            $quantity = (float) ($rawItem['quantity'] ?? 0);
            $unitPrice = (float) ($rawItem['unit_price'] ?? 0);
            $discount = (float) ($rawItem['discount'] ?? 0);
            $unit = $this->nullableDisplayUnit($rawItem['unit'] ?? null);

            if ($productName === '' && $description === '' && $quantity <= 0 && $unitPrice <= 0) {
                continue;
            }

            if ($productName === '' && $description !== '') {
                $productName = $description;
            }

            if ($productName === '') {
                throw new RuntimeException('يجب إدخال وصف أو اسم للبند.');
            }

            $productId = isset($rawItem['product_id']) ? (int) $rawItem['product_id'] : 0;
            $productId = $productId > 0 ? $productId : null;
            if ($productId) {
                $validProduct = Product::withoutGlobalScopes()
                    ->where('workspace_id', $workspaceId)
                    ->whereKey($productId)
                    ->exists();
                if (! $validProduct) {
                    throw new RuntimeException('أحد المنتجات المحددة غير صالح ضمن مساحة العمل الحالية.');
                }
            }

            $line = [
                'product_id' => $productId,
                'product_name' => $productName,
                'description' => $description !== '' ? $description : null,
                'quantity' => $quantity,
                'unit_price' => $unitPrice,
                'discount' => $discount,
                'tax_type' => $rawItem['tax_type'] ?? $rawItem['tax_profile_type'] ?? null,
                'tax_profile_type' => $rawItem['tax_profile_type'] ?? $rawItem['tax_type'] ?? null,
                'exemption_reason' => $rawItem['exemption_reason'] ?? null,
                'exemption_code' => $rawItem['exemption_code'] ?? null,
                'unit_code' => $this->nullableCode($rawItem['unit_code'] ?? null),
                'unit' => $unit,
                'metadata' => is_array($rawItem['metadata'] ?? null) ? $rawItem['metadata'] : null,
            ];

            if (array_key_exists('tax_rate', $rawItem)) {
                $line['tax_rate'] = $rawItem['tax_rate'];
            }

            $items[] = $line;
        }

        return $items;
    }

    /**
     * @param  array<string, mixed>  $item
     */
    private function createQuoteItem(int $workspaceId, int $quoteId, array $item): void
    {
        FinanceQuoteItem::withoutGlobalScopes()->create([
            'workspace_id' => $workspaceId,
            'quote_id' => $quoteId,
            'product_id' => $item['product_id'],
            'product_name' => $item['product_name'],
            'description' => $item['description'],
            'unit' => $item['unit'] ?? null,
            'unit_code' => $this->nullableCode($item['unit_code'] ?? null),
            'quantity' => $item['quantity'],
            'unit_price' => $item['unit_price'],
            'discount' => $item['discount'],
            'tax_profile_type' => $item['tax_profile_type'],
            'exemption_reason' => $item['exemption_reason'] ?? null,
            'exemption_code' => $item['exemption_code'] ?? null,
            'tax_rate' => $item['tax_rate'],
            'tax_amount' => $item['tax_amount'],
            'taxable_amount' => $item['taxable_amount'],
            'total' => $item['total'],
            'metadata' => $item['metadata'],
        ]);
    }

    private function requireCustomer(int $workspaceId, mixed $customerId): Customer
    {
        $id = (int) $customerId;
        if ($id <= 0) {
            throw new RuntimeException('عرض السعر يتطلب اختيار عميل مسجل.');
        }

        $customer = Customer::withoutGlobalScopes()
            ->where('workspace_id', $workspaceId)
            ->whereKey($id)
            ->first();

        if (! $customer) {
            throw new RuntimeException('العميل المحدد غير صالح ضمن مساحة العمل الحالية.');
        }

        return $customer;
    }

    private function nextQuoteNumber(int $workspaceId): string
    {
        $settings = FinanceSetting::withoutGlobalScopes()
            ->where('workspace_id', $workspaceId)
            ->lockForUpdate()
            ->first();

        if (! $settings) {
            $settings = FinanceSetting::withoutGlobalScopes()->create([
                'workspace_id' => $workspaceId,
                'currency' => 'SAR',
                'country_code' => 'SA',
                'invoice_prefix' => 'INV',
                'next_invoice_sequence' => 1,
                'quote_prefix' => 'Q',
                'next_quote_sequence' => 1,
                'allow_manual_invoice_numbers' => false,
                'default_vat_rate' => TaxCalculationService::FALLBACK_STANDARD_RATE,
            ]);
        }

        $prefix = Schema::hasColumn('finance_settings', 'quote_prefix')
            ? (string) ($settings->quote_prefix ?: 'Q')
            : 'Q';
        $sequence = Schema::hasColumn('finance_settings', 'next_quote_sequence')
            ? max(1, (int) $settings->next_quote_sequence)
            : 1;
        $year = now(config('app.timezone'))->format('Y');
        $attempts = 0;
        $number = '';
        $exists = true;

        while ($exists && $attempts < 100) {
            $number = sprintf('%s-%s-%04d', $prefix, $year, $sequence);
            $exists = FinanceQuote::withoutGlobalScopes()
                ->withTrashed()
                ->where('workspace_id', $workspaceId)
                ->where('quote_number', $number)
                ->exists();
            $sequence++;
            $attempts++;
        }

        if ($exists || $number === '') {
            throw new RuntimeException('تعذر توليد رقم عرض سعر فريد لهذه المنشأة.');
        }

        if (Schema::hasColumn('finance_settings', 'next_quote_sequence')) {
            $settings->update(['next_quote_sequence' => $sequence]);
        }

        return $number;
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array{type:string, rate:float}
     */
    private function resolveTaxProfile(Workspace $workspace, array $payload): array
    {
        $default = $this->taxCalculator->defaultProfileForWorkspace($workspace);
        $type = $this->taxCalculator->normalizeProfileType(
            isset($payload['tax_profile_type']) ? (string) $payload['tax_profile_type'] : $default['type'],
            $default['type']
        );

        if (array_key_exists('tax_rate', $payload) && $payload['tax_rate'] !== null && $payload['tax_rate'] !== '') {
            return [
                'type' => $type,
                'rate' => (float) $payload['tax_rate'],
            ];
        }

        return [
            'type' => $type,
            'rate' => (float) $default['rate'],
        ];
    }

    /**
     * @return array{company: array<string, mixed>, recipient: array<string, mixed>, pdf: array<string, mixed>}
     */
    private function captureSnapshots(Customer $customer, ?FinanceSetting $setting): array
    {
        $company = [
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
            'default_payment_terms' => $setting?->default_payment_terms,
            'logo_path' => $setting?->logo_path,
        ];

        $recipient = [
            'kind' => 'customer',
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
            'payment_terms' => $customer->payment_terms,
        ];

        return [
            'company' => $company,
            'recipient' => $recipient,
            'pdf' => [
                'primary_color' => $setting?->invoice_primary_color ?: '#06C2A4',
                'footer_text' => $setting?->invoice_footer_text ?: null,
                'company' => $company,
            ],
        ];
    }

    /**
     * @param  array<int, mixed>  $uploadedFiles
     */
    public function storeAttachments(FinanceQuote $quote, array $uploadedFiles, int $actorUserId): void
    {
        if ($quote->isCancelled()) {
            throw new RuntimeException('لا يمكن إضافة مرفقات إلى عرض سعر ملغى.');
        }

        $this->storeUploadedAttachments($quote, $uploadedFiles, $actorUserId);
    }

    public function deleteAttachment(FinanceQuote $quote, FinanceQuoteAttachment $attachment): void
    {
        if ((int) $attachment->quote_id !== (int) $quote->id) {
            throw new RuntimeException('المرفق غير تابع لعرض السعر.');
        }
        if ($quote->isCancelled()) {
            throw new RuntimeException('لا يمكن حذف مرفقات عرض سعر ملغى.');
        }

        $attachment->deleteFile();
        $attachment->delete();
    }

    /**
     * @param  array<int, mixed>  $uploadedFiles
     */
    private function storeUploadedAttachments(FinanceQuote $quote, array $uploadedFiles, int $actorUserId): void
    {
        if ($quote->isCancelled()) {
            throw new RuntimeException('لا يمكن تعديل مرفقات عرض سعر ملغى.');
        }

        foreach ($uploadedFiles as $file) {
            if (! $file instanceof UploadedFile) {
                continue;
            }

            $storedPath = app(SecureUpload::class)->store(
                $file,
                'workspaces/'.$quote->workspace_id.'/finance/quotes/'.$quote->id,
                'public',
                10240
            );
            FinanceQuoteAttachment::withoutGlobalScopes()->create([
                'workspace_id' => $quote->workspace_id,
                'quote_id' => $quote->id,
                'file_path' => $storedPath,
                'file_name' => $file->getClientOriginalName(),
                'file_type' => $file->getClientMimeType(),
                'file_size' => $file->getSize(),
                'uploaded_by' => $actorUserId,
            ]);
        }
    }

    private function settingsForWorkspace(int $workspaceId): ?FinanceSetting
    {
        return FinanceSetting::withoutGlobalScopes()
            ->where('workspace_id', $workspaceId)
            ->first();
    }

    private function nullableCode(mixed $value): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }

        $code = is_string($value) || is_numeric($value) ? (string) $value : null;

        return $code === '' ? null : $code;
    }

    private function nullableDisplayUnit(mixed $value): ?string
    {
        $unit = trim((string) ($value ?? ''));
        if ($unit === '') {
            return null;
        }

        return mb_substr($unit, 0, 32);
    }
}
