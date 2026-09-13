<?php

namespace App\Services\Finance;

use App\Enums\Finance\TaxPriceMode;
use App\Models\Customer;
use App\Models\Finance\FinanceInvoice;
use App\Models\Finance\FinanceInvoiceAttachment;
use App\Models\Finance\FinanceInvoiceItem;
use App\Models\Finance\FinanceJournalEntry;
use App\Models\Finance\FinanceSetting;
use App\Models\Finance\FinanceSupplier;
use App\Models\Product;
use App\Models\Workspace;
use App\Services\EInvoicing\InvoiceIssueService;
use App\Services\Finance\Tax\TaxCalculationResult;
use App\Services\Finance\Tax\TaxCalculationService;
use App\Support\Money\Money;
use App\Support\Uploads\SecureUpload;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use RuntimeException;

class InvoiceService
{
    public function __construct(
        private readonly TaxCalculationService $taxCalculator,
        private readonly ChartOfAccountsService $chartOfAccountsService,
        private readonly AccountingService $accountingService,
        private readonly InvoiceStateService $invoiceStateService,
        private readonly FinancialPeriodGuardService $financialPeriodGuardService,
        private readonly InventoryAccountingService $inventoryAccountingService,
        private readonly IssuedSnapshotBuilder $issuedSnapshotBuilder,
        private readonly InvoiceIssueService $invoiceIssueService,
    ) {}

    /**
     * @param  array<string,mixed>  $payload
     */
    public function create(Workspace $workspace, array $payload, int $actorUserId): FinanceInvoice
    {
        $this->chartOfAccountsService->ensureDefaultAccounts($workspace);
        $profile = $this->resolveTaxProfile($workspace, $payload);

        return DB::transaction(function () use ($workspace, $payload, $actorUserId, $profile): FinanceInvoice {
            $customerId = isset($payload['customer_id']) ? (int) $payload['customer_id'] : null;
            $customerName = trim((string) ($payload['customer_name'] ?? ''));
            $supplierId = isset($payload['supplier_id']) ? (int) $payload['supplier_id'] : null;
            $type = (string) $payload['type'];
            $requestedStatus = (string) ($payload['invoice_status'] ?? $payload['status'] ?? 'issued');

            if ($type === 'sales' && ! $customerId && $customerName === '') {
                throw new RuntimeException('فاتورة المبيعات تتطلب عميلًا مسجلًا أو اسم عميل نقدي.');
            }

            if ($type === 'purchase' && ! $supplierId) {
                throw new RuntimeException('فاتورة الشراء تتطلب اختيار مورد.');
            }

            if ($customerId) {
                $exists = Customer::withoutGlobalScopes()
                    ->where('workspace_id', $workspace->id)
                    ->whereKey($customerId)
                    ->exists();
                if (! $exists) {
                    throw new RuntimeException('العميل المحدد غير صالح ضمن مساحة العمل الحالية.');
                }
            }

            if ($supplierId) {
                $exists = FinanceSupplier::withoutGlobalScopes()
                    ->where('workspace_id', $workspace->id)
                    ->whereKey($supplierId)
                    ->exists();
                if (! $exists) {
                    throw new RuntimeException('المورد المحدد غير صالح ضمن مساحة العمل الحالية.');
                }
            }

            $customer = $customerId
                ? Customer::withoutGlobalScopes()
                    ->where('workspace_id', $workspace->id)
                    ->whereKey($customerId)
                    ->first()
                : null;
            $supplier = $supplierId
                ? FinanceSupplier::withoutGlobalScopes()
                    ->where('workspace_id', $workspace->id)
                    ->whereKey($supplierId)
                    ->first()
                : null;

            $taxResult = $this->calculateInvoiceItems(
                $workspace,
                $payload['items'] ?? [],
                $profile,
                $payload
            );
            $items = $taxResult['items'];
            if ($items === []) {
                throw new RuntimeException('يجب أن تحتوي الفاتورة على بند واحد على الأقل.');
            }

            $totals = $taxResult['result']->totalsArray();
            $headerProfile = $taxResult['result']->headerProfile($profile);
            $classification = InvoiceClassification::fromPayload($payload, $type);
            $amountPaid = max(0, (float) ($payload['amount_paid'] ?? 0));
            $amountCredited = (float) ($payload['amount_credited'] ?? 0);
            $amountDebited = (float) ($payload['amount_debited'] ?? 0);
            $requestedInvoiceStatus = $this->invoiceStateService->resolveInvoiceStatus($requestedStatus);
            $shouldIssue = $requestedInvoiceStatus === 'issued';
            $draftPaymentStatus = $this->invoiceStateService->resolvePaymentStatus(
                total: $totals['total'],
                amountPaid: $amountPaid,
                dueDate: ! empty($payload['due_date']) ? (string) $payload['due_date'] : null,
                invoiceStatus: 'draft',
                amountCredited: $amountCredited,
                amountDebited: $amountDebited,
            );
            $supportsSplitStatuses = FinanceInvoice::hasSeparatedStatusColumns();
            $supportsSnapshots = FinanceInvoice::hasSnapshotColumns();

            $settings = $this->settingsForWorkspace((int) $workspace->id);
            $snapshots = $this->captureSnapshots($type, $customer, $supplier, $customerName, $settings);

            $attributes = [
                'workspace_id' => $workspace->id,
                'customer_id' => $customerId,
                'customer_name' => $type === 'sales' && $customerName !== '' ? $customerName : null,
                'supplier_id' => $supplierId,
                'invoice_number' => $this->resolveInvoiceNumber((int) $workspace->id, $payload),
                'type' => $type,
                'status' => 'draft',
                'issue_date' => (string) $payload['issue_date'],
                'due_date' => ($payload['due_date'] ?? null) ?: null,
                'currency' => (string) ($payload['currency'] ?? 'SAR'),
                'subtotal' => $totals['subtotal'],
                'discount' => $totals['discount'],
                'taxable_amount' => $totals['taxable_amount'],
                'tax_amount' => $totals['tax_amount'],
                'total' => $totals['total'],
                'amount_paid' => $this->money($amountPaid),
                'amount_due' => $this->invoiceStateService->resolveAmountDue($totals['total'], $amountPaid, $amountCredited, $amountDebited),
                'tax_profile_type' => $headerProfile['type'],
                'tax_rate' => $this->money($headerProfile['rate']),
                'payment_terms' => ($payload['payment_terms'] ?? null) ?: null,
                'notes' => ($payload['notes'] ?? null) ?: null,
                'created_by' => $actorUserId,
            ];

            if (Schema::hasColumn('finance_invoices', 'supply_date')) {
                $attributes['supply_date'] = $this->nullableDate($payload['supply_date'] ?? null);
            }

            if (FinanceInvoice::hasClassificationColumns()) {
                $attributes['tax_document_subtype'] = $classification->taxDocumentSubtype->value;
                $attributes['zatca_requirement'] = $classification->zatcaRequirement->value;
            }

            if (FinanceInvoice::hasTaxEngineColumns()) {
                $attributes['tax_price_mode'] = $taxResult['result']->priceMode->value;
                $attributes['tax_breakdown'] = $taxResult['result']->categoryTotalsToArray();
            }

            if (Schema::hasColumn('finance_invoices', 'project_id') && array_key_exists('project_id', $payload)) {
                $attributes['project_id'] = $payload['project_id'] ? (int) $payload['project_id'] : null;
            }

            if (FinanceInvoice::hasContractColumn()) {
                $attributes['contract_id'] = isset($payload['contract_id']) ? (int) $payload['contract_id'] : null;
                if (isset($payload['billing_schedule_id'])) {
                    $attributes['billing_schedule_id'] = (int) $payload['billing_schedule_id'];
                }
                if (Schema::hasColumn('finance_invoices', 'billing_occurrence_key') && isset($payload['billing_occurrence_key'])) {
                    $attributes['billing_occurrence_key'] = (string) $payload['billing_occurrence_key'];
                }
            }

            if (FinanceInvoice::hasAdjustmentColumns()) {
                $attributes['amount_credited'] = $this->money($amountCredited);
                $attributes['amount_debited'] = $this->money($amountDebited);
            }

            if ($supportsSplitStatuses) {
                $attributes['invoice_status'] = 'draft';
                $attributes['payment_status'] = $draftPaymentStatus;
                $attributes['issued_at'] = null;
            }

            if ($supportsSnapshots) {
                $attributes['company_snapshot'] = $snapshots['company'];
                $attributes['recipient_snapshot'] = $snapshots['recipient'];
                $attributes['pdf_snapshot'] = $snapshots['pdf'];
            }

            try {
                $invoice = FinanceInvoice::withoutGlobalScopes()->create($attributes);
            } catch (UniqueConstraintViolationException $exception) {
                if (isset($attributes['billing_occurrence_key'])) {
                    throw $exception;
                }

                throw new RuntimeException('رقم الفاتورة مستخدم مسبقاً في هذه المنشأة.');
            }

            foreach ($items as $item) {
                $this->createInvoiceItem((int) $workspace->id, (int) $invoice->id, $item);
            }

            $this->storeUploadedAttachments($invoice, $payload['attachments'] ?? [], $actorUserId);

            if ($shouldIssue) {
                return $this->issue($invoice->fresh(['items', 'customer', 'supplier']), $actorUserId, (bool) ($payload['skip_inventory'] ?? false));
            }

            return $invoice->load(['items', 'customer', 'supplier', 'attachments']);
        });
    }

    public function cancel(FinanceInvoice $invoice, int $actorUserId = 0): FinanceInvoice
    {
        return DB::transaction(function () use ($invoice, $actorUserId): FinanceInvoice {
            $locked = FinanceInvoice::withoutGlobalScopes()
                ->whereKey($invoice->id)
                ->lockForUpdate()
                ->firstOrFail();

            $currentInvoiceStatus = $locked->invoice_status
                ?? $this->invoiceStateService->resolveInvoiceStatus($locked->status);

            if ($currentInvoiceStatus === 'cancelled') {
                return $locked;
            }

            $postedPaid = $this->invoiceStateService->postedPaymentsSum($locked);
            if ($postedPaid > InvoiceStateService::PAYMENT_TOLERANCE) {
                throw new RuntimeException('لا يمكن إلغاء فاتورة عليها دفعات قائمة. اعكس الدفعات أولاً أو أصدر إشعار دائن.');
            }

            if ($currentInvoiceStatus === 'issued') {
                $this->reversePostedInvoiceEntry($locked, $actorUserId > 0 ? $actorUserId : (int) $locked->created_by);
            }

            $paymentStatus = $this->invoiceStateService->resolvePaymentStatus(
                total: (float) $locked->total,
                amountPaid: 0,
                dueDate: null,
                invoiceStatus: 'cancelled',
                amountCredited: (float) ($locked->amount_credited ?? 0),
                amountDebited: (float) ($locked->amount_debited ?? 0),
            );

            $attributes = [
                'status' => 'cancelled',
                'cancelled_at' => now(),
            ];
            if (FinanceInvoice::hasSeparatedStatusColumns()) {
                $attributes['invoice_status'] = 'cancelled';
                $attributes['payment_status'] = $paymentStatus;
            }

            $locked->update($attributes);

            return $locked->fresh(['items', 'customer', 'supplier']);
        });
    }

    /**
     * @param  array<string,mixed>  $payload
     */
    public function updateDraft(FinanceInvoice $invoice, array $payload, int $actorUserId): FinanceInvoice
    {
        $currentInvoiceStatus = $invoice->invoice_status
            ?? $this->invoiceStateService->resolveInvoiceStatus($invoice->status);

        if ($currentInvoiceStatus !== 'draft') {
            throw new RuntimeException('يمكن تعديل المسودات فقط. الفواتير المعتمدة وثائق مالية ثابتة.');
        }

        return DB::transaction(function () use ($invoice, $payload, $actorUserId): FinanceInvoice {
            $locked = FinanceInvoice::withoutGlobalScopes()->whereKey($invoice->id)->lockForUpdate()->firstOrFail();
            $workspaceId = (int) $locked->workspace_id;
            $workspace = Workspace::query()->findOrFail($workspaceId);
            $profile = $this->resolveTaxProfile($workspace, $payload);
            $type = (string) ($payload['type'] ?? $locked->type);

            $customerId = array_key_exists('customer_id', $payload)
                ? (isset($payload['customer_id']) ? (int) $payload['customer_id'] : null)
                : $locked->customer_id;
            $customerName = trim((string) ($payload['customer_name'] ?? $locked->customer_name ?? ''));
            $supplierId = array_key_exists('supplier_id', $payload)
                ? (isset($payload['supplier_id']) ? (int) $payload['supplier_id'] : null)
                : $locked->supplier_id;

            if ($type === 'sales' && ! $customerId && $customerName === '') {
                throw new RuntimeException('فاتورة المبيعات تتطلب عميلًا مسجلًا أو اسم عميل نقدي.');
            }
            if ($type === 'purchase' && ! $supplierId) {
                throw new RuntimeException('فاتورة الشراء تتطلب اختيار مورد.');
            }

            $taxResult = $this->calculateInvoiceItems(
                $workspace,
                $payload['items'] ?? [],
                $profile,
                $payload
            );
            $items = $taxResult['items'];
            if ($items === []) {
                throw new RuntimeException('يجب أن تحتوي الفاتورة على بند واحد على الأقل.');
            }

            $totals = $taxResult['result']->totalsArray();
            $headerProfile = $taxResult['result']->headerProfile($profile);
            $classification = InvoiceClassification::fromPayload($payload, $type);
            $customer = $customerId
                ? Customer::withoutGlobalScopes()->where('workspace_id', $workspaceId)->whereKey($customerId)->first()
                : null;
            $supplier = $supplierId
                ? FinanceSupplier::withoutGlobalScopes()->where('workspace_id', $workspaceId)->whereKey($supplierId)->first()
                : null;

            $settings = $this->settingsForWorkspace($workspaceId);
            $snapshots = $this->captureSnapshots($type, $customer, $supplier, $customerName, $settings);
            $updates = [
                'customer_id' => $customerId,
                'customer_name' => $type === 'sales' && $customerName !== '' ? $customerName : null,
                'supplier_id' => $supplierId,
                'type' => $type,
                'issue_date' => (string) ($payload['issue_date'] ?? $locked->issue_date?->toDateString()),
                'due_date' => ($payload['due_date'] ?? null) ?: null,
                'currency' => (string) ($payload['currency'] ?? $locked->currency ?? 'SAR'),
                'subtotal' => $totals['subtotal'],
                'discount' => $totals['discount'],
                'taxable_amount' => $totals['taxable_amount'],
                'tax_amount' => $totals['tax_amount'],
                'total' => $totals['total'],
                'amount_due' => $totals['total'],
                'tax_profile_type' => $headerProfile['type'],
                'tax_rate' => $this->money($headerProfile['rate']),
                'payment_terms' => ($payload['payment_terms'] ?? null) ?: null,
                'notes' => ($payload['notes'] ?? null) ?: null,
            ];

            if (Schema::hasColumn('finance_invoices', 'supply_date') && array_key_exists('supply_date', $payload)) {
                $updates['supply_date'] = $this->nullableDate($payload['supply_date']);
            }

            if (FinanceInvoice::hasSnapshotColumns()) {
                $updates['company_snapshot'] = $snapshots['company'];
                $updates['recipient_snapshot'] = $snapshots['recipient'];
                $updates['pdf_snapshot'] = $snapshots['pdf'];
            }

            if (FinanceInvoice::hasClassificationColumns()) {
                $updates['tax_document_subtype'] = $classification->taxDocumentSubtype->value;
                $updates['zatca_requirement'] = $classification->zatcaRequirement->value;
            }

            if (FinanceInvoice::hasTaxEngineColumns()) {
                $updates['tax_price_mode'] = $taxResult['result']->priceMode->value;
                $updates['tax_breakdown'] = $taxResult['result']->categoryTotalsToArray();
            }

            if (array_key_exists('invoice_number', $payload) && trim((string) $payload['invoice_number']) !== '') {
                $updates['invoice_number'] = $this->resolveInvoiceNumber(
                    $workspaceId,
                    $payload,
                    (int) $locked->id,
                    (string) $locked->invoice_number
                );
            }

            if (Schema::hasColumn('finance_invoices', 'project_id') && array_key_exists('project_id', $payload)) {
                $updates['project_id'] = $payload['project_id'] ? (int) $payload['project_id'] : null;
            }

            if (FinanceInvoice::hasContractColumn() && array_key_exists('contract_id', $payload)) {
                $updates['contract_id'] = $payload['contract_id'] ? (int) $payload['contract_id'] : null;
            }

            $locked->update($updates);
            FinanceInvoiceItem::withoutGlobalScopes()
                ->where('invoice_id', $locked->id)
                ->get()
                ->each(fn (FinanceInvoiceItem $item) => $item->delete());
            foreach ($items as $item) {
                $this->createInvoiceItem($workspaceId, (int) $locked->id, $item);
            }

            $this->storeUploadedAttachments($locked, $payload['attachments'] ?? [], $actorUserId);

            return $locked->fresh(['items', 'customer', 'supplier', 'attachments']);
        });
    }

    public function issue(FinanceInvoice $invoice, int $actorUserId, bool $skipInventory = false): FinanceInvoice
    {
        return DB::transaction(function () use ($invoice, $actorUserId, $skipInventory): FinanceInvoice {
            $locked = FinanceInvoice::withoutGlobalScopes()->whereKey($invoice->id)->lockForUpdate()->firstOrFail();
            $currentInvoiceStatus = $locked->invoice_status
                ?? $this->invoiceStateService->resolveInvoiceStatus($locked->status);

            if ($currentInvoiceStatus === 'issued') {
                $issued = $locked->fresh(['items', 'customer', 'supplier', 'attachments', 'contract']);
                $this->connectIssuedInvoice($issued);

                return $issued;
            }
            if ($currentInvoiceStatus === 'cancelled') {
                throw new RuntimeException('لا يمكن إصدار فاتورة ملغاة.');
            }

            $this->financialPeriodGuardService->assertDateIsOpen(
                workspaceId: (int) $locked->workspace_id,
                date: $locked->issue_date?->toDateString() ?? now(config('app.timezone'))->toDateString(),
                context: 'إصدار الفاتورة'
            );

            $this->assertIssuedTaxReconciles($locked);

            $paid = $this->invoiceStateService->postedPaymentsSum($locked);
            $credited = (float) ($locked->amount_credited ?? 0);
            $debited = (float) ($locked->amount_debited ?? 0);
            $due = $this->invoiceStateService->resolveAmountDue((float) $locked->total, $paid, $credited, $debited);
            $paymentStatus = $this->invoiceStateService->resolvePaymentStatus(
                total: (float) $locked->total,
                amountPaid: $paid,
                dueDate: $locked->due_date?->toDateString(),
                invoiceStatus: 'issued',
                amountCredited: $credited,
                amountDebited: $debited,
            );

            $customer = $locked->customer_id
                ? Customer::withoutGlobalScopes()
                    ->where('workspace_id', $locked->workspace_id)
                    ->whereKey($locked->customer_id)
                    ->first()
                : null;
            $supplier = $locked->supplier_id
                ? FinanceSupplier::withoutGlobalScopes()
                    ->where('workspace_id', $locked->workspace_id)
                    ->whereKey($locked->supplier_id)
                    ->first()
                : null;
            $settings = $this->settingsForWorkspace((int) $locked->workspace_id);
            $snapshots = $this->captureSnapshots(
                (string) $locked->type,
                $customer,
                $supplier,
                (string) ($locked->customer_name ?? ''),
                $settings
            );

            $issuedAt = now(config('app.timezone'));
            $attributes = [
                'status' => $this->invoiceStateService->toLegacyStatus('issued', $paymentStatus),
                'issued_by' => $actorUserId,
                'amount_paid' => $paid,
                'amount_due' => $due,
            ];

            if (FinanceInvoice::hasSeparatedStatusColumns()) {
                $attributes['invoice_status'] = 'issued';
                $attributes['payment_status'] = $paymentStatus;
                $attributes['issued_at'] = $issuedAt;
            }

            if (FinanceInvoice::hasSnapshotColumns()) {
                $attributes['company_snapshot'] = $snapshots['company'];
                $attributes['recipient_snapshot'] = $snapshots['recipient'];
                $attributes['pdf_snapshot'] = $snapshots['pdf'];
            }

            $locked->update($attributes);

            $this->postInvoiceEntry($locked->fresh(), $actorUserId, $skipInventory);

            $issued = $locked->fresh(['items', 'customer', 'supplier', 'attachments', 'contract']);
            $this->connectIssuedInvoice($issued);

            return $issued;
        });
    }

    private function connectIssuedInvoice(FinanceInvoice $invoice): void
    {
        $snapshot = $this->issuedSnapshotBuilder->captureInvoice($invoice);
        $this->invoiceIssueService->prepareFromSnapshot($snapshot);
    }

    public function deleteDraft(FinanceInvoice $invoice): void
    {
        DB::transaction(function () use ($invoice): void {
            $locked = FinanceInvoice::withoutGlobalScopes()
                ->whereKey($invoice->id)
                ->lockForUpdate()
                ->firstOrFail();

            if ($locked->isFinanciallyLocked()) {
                throw new RuntimeException('لا يمكن حذف فاتورة معتمدة أو ملغاة. استخدم الإلغاء أو إشعار دائن.');
            }

            $locked->delete();
        });
    }

    public function deleteAttachment(FinanceInvoiceAttachment $attachment): void
    {
        $invoice = FinanceInvoice::withoutGlobalScopes()->find($attachment->invoice_id);
        if ($invoice?->isFinanciallyLocked()) {
            throw new RuntimeException('لا يمكن حذف مرفقات فاتورة معتمدة أو ملغاة.');
        }

        $attachment->deleteFile();
        $attachment->delete();
    }

    /**
     * @param  array<int, mixed>  $uploadedFiles
     */
    public function storeAttachments(FinanceInvoice $invoice, array $uploadedFiles, int $actorUserId): void
    {
        if ($invoice->isFinanciallyLocked()) {
            throw new RuntimeException('لا يمكن إضافة مرفقات إلى فاتورة معتمدة أو ملغاة.');
        }

        $this->storeUploadedAttachments($invoice, $uploadedFiles, $actorUserId);
    }

    /**
     * @param  array<int, mixed>  $rawItems
     * @param  array{type:string, rate:float}  $defaultProfile
     * @param  array<string, mixed>  $payload
     * @return array{items: array<int, array<string, mixed>>, result: TaxCalculationResult|null}
     */
    private function calculateInvoiceItems(Workspace $workspace, array $rawItems, array $defaultProfile, array $payload): array
    {
        $prepared = $this->prepareLineInputs($rawItems, (int) $workspace->id);
        if ($prepared === []) {
            return ['items' => [], 'result' => null];
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

    private function assertIssuedTaxReconciles(FinanceInvoice $invoice): void
    {
        $invoice->loadMissing('items');
        $workspace = Workspace::query()->findOrFail((int) $invoice->workspace_id);
        $priceMode = TaxPriceMode::tryFrom((string) ($invoice->tax_price_mode ?? TaxPriceMode::Exclusive->value))
            ?? TaxPriceMode::Exclusive;

        $result = $this->taxCalculator->calculateFromPersistedLines($workspace, $invoice->items, $priceMode);
        if (! $result->matchesPersistedInvoice($invoice)) {
            throw new RuntimeException('نتيجة الضريبة غير متسقة ولا يمكن إصدار الفاتورة.');
        }

        if (FinanceInvoice::hasTaxEngineColumns() && $invoice->tax_breakdown === null) {
            $invoice->update([
                'tax_breakdown' => $result->categoryTotalsToArray(),
                'tax_price_mode' => $result->priceMode->value,
            ]);
        }
    }

    public function refreshIssuedPaymentStatuses(?int $workspaceId = null): int
    {
        return $this->invoiceStateService->refreshIssuedStatuses($workspaceId);
    }

    public function syncPaymentStatus(FinanceInvoice $invoice): FinanceInvoice
    {
        $invoiceStatus = $invoice->invoice_status
            ?? $this->invoiceStateService->resolveInvoiceStatus($invoice->status);

        if ($invoiceStatus !== 'issued') {
            return $invoice;
        }

        $paid = $this->invoiceStateService->postedPaymentsSum($invoice);
        $credited = (float) ($invoice->amount_credited ?? 0);
        $debited = (float) ($invoice->amount_debited ?? 0);
        $due = $this->invoiceStateService->resolveAmountDue((float) $invoice->total, $paid, $credited, $debited);
        $paymentStatus = $this->invoiceStateService->resolvePaymentStatus(
            total: (float) $invoice->total,
            amountPaid: $paid,
            dueDate: $invoice->due_date?->toDateString(),
            invoiceStatus: $invoiceStatus,
            amountCredited: $credited,
            amountDebited: $debited,
        );
        $legacyStatus = $this->invoiceStateService->toLegacyStatus($invoiceStatus, $paymentStatus);

        $attributes = [
            'amount_paid' => $paid,
            'amount_due' => $due,
            'status' => $legacyStatus,
        ];
        if (FinanceInvoice::hasSeparatedStatusColumns()) {
            $attributes['invoice_status'] = $invoiceStatus;
            $attributes['payment_status'] = $paymentStatus;
        }

        if (
            (float) $invoice->amount_paid !== $paid
            || (float) $invoice->amount_due !== $due
            || $invoice->status !== $legacyStatus
            || (
                FinanceInvoice::hasSeparatedStatusColumns()
                && (
                    $invoice->payment_status !== $paymentStatus
                    || $invoice->invoice_status !== $invoiceStatus
                )
            )
        ) {
            $invoice->update($attributes);

            return $invoice->fresh();
        }

        return $invoice;
    }

    /**
     * @param  array<string,mixed>  $payload
     * @return array{type:string,rate:float}
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

    private function postInvoiceEntry(FinanceInvoice $invoice, int $actorUserId, bool $skipInventory = false): void
    {
        $entryType = $invoice->type === 'sales' ? 'sales_invoice' : 'purchase_invoice';
        $alreadyPosted = FinanceJournalEntry::withoutGlobalScopes()
            ->where('workspace_id', $invoice->workspace_id)
            ->where('type', $entryType)
            ->where('reference_type', FinanceInvoice::class)
            ->where('reference_id', $invoice->id)
            ->whereNull('reverses_entry_id')
            ->exists();
        if ($alreadyPosted) {
            return;
        }

        $workspaceId = (int) $invoice->workspace_id;
        $ar = $this->chartOfAccountsService->byCode('1200', $workspaceId);
        $ap = $this->chartOfAccountsService->byCode('2000', $workspaceId);
        $sales = $this->chartOfAccountsService->byCode('4000', $workspaceId);
        $generalExpense = $this->chartOfAccountsService->byCode('5900', $workspaceId);
        $outputVat = $this->chartOfAccountsService->byCode('2100', $workspaceId);
        $inputVat = $this->chartOfAccountsService->byCode('1400', $workspaceId);

        if (! $ar || ! $ap || ! $sales || ! $generalExpense || ! $outputVat || ! $inputVat) {
            throw new RuntimeException('دليل الحسابات غير مكتمل ولا يمكن ترحيل الفاتورة محاسبيًا.');
        }

        $invoice->loadMissing('items');

        if ($invoice->type === 'sales') {
            $lines = [
                [
                    'account_id' => $ar->id,
                    'debit' => $invoice->total,
                    'credit' => 0,
                    'description' => 'Sales invoice receivable',
                    'entity_type' => FinanceInvoice::class,
                    'entity_id' => $invoice->id,
                ],
                [
                    'account_id' => $sales->id,
                    'debit' => 0,
                    'credit' => $invoice->taxable_amount,
                    'description' => 'Sales revenue',
                    'entity_type' => FinanceInvoice::class,
                    'entity_id' => $invoice->id,
                ],
            ];

            if ((float) $invoice->tax_amount > 0) {
                $lines[] = [
                    'account_id' => $outputVat->id,
                    'debit' => 0,
                    'credit' => $invoice->tax_amount,
                    'description' => 'Output VAT',
                    'entity_type' => FinanceInvoice::class,
                    'entity_id' => $invoice->id,
                ];
            }
        } else {
            $expenseAmount = $this->inventoryAccountingService->purchaseExpenseAmount($invoice);
            $lines = [];

            if (! Money::isZero($expenseAmount)) {
                $lines[] = [
                    'account_id' => $generalExpense->id,
                    'debit' => $expenseAmount,
                    'credit' => 0,
                    'description' => 'Purchase expense',
                    'entity_type' => FinanceInvoice::class,
                    'entity_id' => $invoice->id,
                ];
            }

            if ((float) $invoice->tax_amount > 0) {
                $lines[] = [
                    'account_id' => $inputVat->id,
                    'debit' => $invoice->tax_amount,
                    'credit' => 0,
                    'description' => 'Input VAT',
                    'entity_type' => FinanceInvoice::class,
                    'entity_id' => $invoice->id,
                ];
            }

            $lines[] = [
                'account_id' => $ap->id,
                'debit' => 0,
                'credit' => $invoice->total,
                'description' => 'Accounts payable',
                'entity_type' => FinanceInvoice::class,
                'entity_id' => $invoice->id,
            ];
        }

        $lines = array_merge($lines, $this->inventoryAccountingService->journalLines($invoice));

        $this->accountingService->createEntry(
            workspaceId: $workspaceId,
            entryDate: $invoice->issue_date?->toDateString() ?? now()->toDateString(),
            type: $entryType,
            lines: $lines,
            description: 'Invoice '.$invoice->invoice_number,
            referenceType: FinanceInvoice::class,
            referenceId: $invoice->id,
            postedBy: $actorUserId
        );

        if (! $skipInventory) {
            $this->inventoryAccountingService->applyStock($invoice, $actorUserId);
        }
    }

    private function reversePostedInvoiceEntry(FinanceInvoice $invoice, int $actorUserId): void
    {
        $entryType = $invoice->type === 'sales' ? 'sales_invoice' : 'purchase_invoice';
        $entry = FinanceJournalEntry::withoutGlobalScopes()
            ->where('workspace_id', $invoice->workspace_id)
            ->where('type', $entryType)
            ->where('reference_type', FinanceInvoice::class)
            ->where('reference_id', $invoice->id)
            ->whereNull('reverses_entry_id')
            ->latest('id')
            ->first();

        if (! $entry) {
            return;
        }

        $already = FinanceJournalEntry::withoutGlobalScopes()
            ->where('workspace_id', $invoice->workspace_id)
            ->where('reverses_entry_id', $entry->id)
            ->exists();
        if ($already) {
            return;
        }

        $this->accountingService->reverseEntry(
            entry: $entry,
            type: 'invoice_reversal',
            actorUserId: $actorUserId,
            description: 'عكس قيد الفاتورة '.$invoice->invoice_number,
            referenceType: FinanceInvoice::class,
            referenceId: $invoice->id,
        );

        $this->inventoryAccountingService->reverseStock($invoice, $actorUserId);
    }

    /**
     * @param  array<int, mixed>  $uploadedFiles
     */
    private function storeUploadedAttachments(FinanceInvoice $invoice, array $uploadedFiles, int $actorUserId): void
    {
        if ($invoice->isFinanciallyLocked()) {
            throw new RuntimeException('لا يمكن تعديل مرفقات فاتورة معتمدة أو ملغاة.');
        }

        foreach ($uploadedFiles as $file) {
            if (! $file instanceof UploadedFile) {
                continue;
            }

            $storedPath = app(SecureUpload::class)->store(
                $file,
                'workspaces/'.$invoice->workspace_id.'/finance/invoices/'.$invoice->id,
                'public',
                10240
            );
            FinanceInvoiceAttachment::withoutGlobalScopes()->create([
                'workspace_id' => $invoice->workspace_id,
                'invoice_id' => $invoice->id,
                'file_path' => $storedPath,
                'file_name' => $file->getClientOriginalName(),
                'file_type' => $file->getClientMimeType(),
                'file_size' => $file->getSize(),
                'uploaded_by' => $actorUserId,
            ]);
        }
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function resolveInvoiceNumber(
        int $workspaceId,
        array $payload,
        ?int $ignoreInvoiceId = null,
        ?string $currentNumber = null
    ): string {
        $requested = trim((string) ($payload['invoice_number'] ?? ''));
        if ($requested === '') {
            return $this->nextInvoiceNumber($workspaceId);
        }

        $settings = FinanceSetting::withoutGlobalScopes()
            ->where('workspace_id', $workspaceId)
            ->lockForUpdate()
            ->first();

        if (! $settings?->allowsManualInvoiceNumbers()) {
            throw new RuntimeException('الترقيم اليدوي للفواتير غير مفعّل لهذه المنشأة.');
        }

        if ($currentNumber !== null && $requested === $currentNumber) {
            return $currentNumber;
        }

        $duplicate = FinanceInvoice::withoutGlobalScopes()
            ->withTrashed()
            ->where('workspace_id', $workspaceId)
            ->where('invoice_number', $requested)
            ->when($ignoreInvoiceId, fn ($query) => $query->whereKeyNot($ignoreInvoiceId))
            ->exists();

        if ($duplicate) {
            throw new RuntimeException('رقم الفاتورة مستخدم مسبقاً في هذه المنشأة.');
        }

        return $requested;
    }

    /**
     * @param  array<string, mixed>  $item
     */
    private function createInvoiceItem(int $workspaceId, int $invoiceId, array $item): void
    {
        $attributes = [
            'workspace_id' => $workspaceId,
            'invoice_id' => $invoiceId,
            'product_id' => $item['product_id'],
            'product_name' => $item['product_name'],
            'description' => $item['description'],
            'quantity' => $item['quantity'],
            'unit_price' => $item['unit_price'],
            'discount' => $item['discount'],
            'tax_rate' => $item['tax_rate'],
            'tax_amount' => $item['tax_amount'],
            'taxable_amount' => $item['taxable_amount'],
            'total' => $item['total'],
            'metadata' => $item['metadata'],
        ];

        if (FinanceInvoiceItem::hasTaxProfileColumn()) {
            $attributes['tax_profile_type'] = $item['tax_profile_type'];
        }

        if (FinanceInvoiceItem::hasExemptionColumns()) {
            $attributes['exemption_reason'] = $item['exemption_reason'] ?? null;
            $attributes['exemption_code'] = $item['exemption_code'] ?? null;
        }

        if (FinanceInvoiceItem::hasUnitCodeColumn()) {
            $attributes['unit_code'] = $this->nullableCode($item['unit_code'] ?? null);
        }

        if (FinanceInvoiceItem::hasUnitColumn()) {
            $attributes['unit'] = $item['unit'] ?? null;
        }

        FinanceInvoiceItem::withoutGlobalScopes()->create($attributes);
    }

    private function settingsForWorkspace(int $workspaceId): ?FinanceSetting
    {
        return FinanceSetting::withoutGlobalScopes()
            ->where('workspace_id', $workspaceId)
            ->first();
    }

    /**
     * @return array{company: array<string, mixed>, recipient: array<string, mixed>, pdf: array<string, mixed>}
     */
    private function captureSnapshots(
        string $type,
        ?Customer $customer,
        ?FinanceSupplier $supplier,
        string $cashCustomerName,
        ?FinanceSetting $setting
    ): array {
        $company = $this->buildCompanySnapshot($setting);

        return [
            'company' => $company,
            'recipient' => $this->buildRecipientSnapshot($type, $customer, $supplier, $cashCustomerName),
            'pdf' => $this->buildPdfSnapshot($setting, $company),
        ];
    }

    private function nextInvoiceNumber(int $workspaceId): string
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
                'allow_manual_invoice_numbers' => false,
                'default_vat_rate' => TaxCalculationService::FALLBACK_STANDARD_RATE,
            ]);
        }

        $prefix = $settings->invoice_prefix ?: 'INV';
        $sequence = max(1, (int) $settings->next_invoice_sequence);
        $attempts = 0;
        $number = '';
        $exists = true;

        while ($exists && $attempts < 100) {
            $number = sprintf('%s-%06d', $prefix, $sequence);
            $exists = FinanceInvoice::withoutGlobalScopes()
                ->withTrashed()
                ->where('workspace_id', $workspaceId)
                ->where('invoice_number', $number)
                ->exists();
            $sequence++;
            $attempts++;
        }

        if ($exists || $number === '') {
            throw new RuntimeException('تعذر توليد رقم فاتورة فريد لهذه المنشأة.');
        }

        $settings->update(['next_invoice_sequence' => $sequence]);

        return $number;
    }

    private function money(float $value): float
    {
        return Money::round($value);
    }

    private function nullableDate(mixed $value): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }

        return (string) $value;
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

    /**
     * @return array<string, mixed>
     */
    private function buildCompanySnapshot(?FinanceSetting $setting): array
    {
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
            'invoice_prefix' => $setting?->invoice_prefix,
            'default_payment_terms' => $setting?->default_payment_terms,
            // Store path only to avoid binary snapshot bloat.
            'logo_path' => $setting?->logo_path,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function buildRecipientSnapshot(
        string $type,
        ?Customer $customer,
        ?FinanceSupplier $supplier,
        string $cashCustomerName
    ): array {
        if ($type === 'purchase') {
            return [
                'kind' => 'supplier',
                'name' => $supplier?->name,
                'name_ar' => $supplier?->arabic_name,
                'vat_number' => $supplier?->vat_number,
                'commercial_registration' => $supplier?->commercial_registration,
                'address' => $supplier?->address,
                'phone' => $supplier?->phone,
                'email' => $supplier?->email,
            ];
        }

        return [
            'kind' => 'customer',
            'name' => $customer?->name ?: $cashCustomerName,
            'vat_number' => $customer?->vat_number,
            'commercial_registration' => $customer?->commercial_registration,
            'address' => $customer?->address,
            'building_number' => $customer?->building_number,
            'street' => $customer?->street,
            'district' => $customer?->district,
            'city' => $customer?->city,
            'postal_code' => $customer?->postal_code,
            'country_code' => $customer?->country_code,
            'additional_number' => $customer?->additional_number,
            'phone' => $customer?->phone,
            'email' => $customer?->email,
            'payment_terms' => $customer?->payment_terms,
        ];
    }

    /**
     * @param  array<string, mixed>  $companySnapshot
     * @return array<string, mixed>
     */
    private function buildPdfSnapshot(?FinanceSetting $setting, array $companySnapshot): array
    {
        return [
            'primary_color' => $setting?->invoice_primary_color ?: '#06C2A4',
            'footer_text' => $setting?->invoice_footer_text ?: null,
            'company' => $companySnapshot,
        ];
    }
}
