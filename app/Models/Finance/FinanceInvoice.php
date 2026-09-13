<?php

namespace App\Models\Finance;

use App\Enums\Finance\FinanceDocumentType;
use App\Models\Concerns\BelongsToWorkspace;
use App\Models\Contract\Contract;
use App\Models\Customer;
use App\Models\Projects\FinanceProject;
use App\Models\User;
use App\Models\WorkspaceScopedModel;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Facades\Schema;
use RuntimeException;

#[Fillable([
    'workspace_id',
    'customer_id',
    'customer_name',
    'supplier_id',
    'contract_id',
    'project_id',
    'billing_schedule_id',
    'billing_occurrence_key',
    'invoice_number',
    'type',
    'tax_document_subtype',
    'zatca_requirement',
    'status',
    'invoice_status',
    'payment_status',
    'issued_at',
    'issue_date',
    'due_date',
    'supply_date',
    'currency',
    'subtotal',
    'discount',
    'taxable_amount',
    'tax_amount',
    'total',
    'amount_paid',
    'amount_due',
    'amount_credited',
    'amount_debited',
    'tax_profile_type',
    'tax_rate',
    'tax_price_mode',
    'tax_breakdown',
    'payment_terms',
    'notes',
    'company_snapshot',
    'recipient_snapshot',
    'pdf_snapshot',
    'zatca_uuid',
    'zatca_qr_code',
    'zatca_xml_hash',
    'created_by',
    'issued_by',
    'cancelled_at',
    'last_reminder_sent_at',
    'reminder_stage',
])]
class FinanceInvoice extends WorkspaceScopedModel
{
    use BelongsToWorkspace, HasFactory, SoftDeletes;

    /** @var array<string,bool> */
    private static array $schemaFlags = [];

    /** @var array<int,string> */
    private const LEGACY_ISSUED_STATUSES = ['sent', 'unpaid', 'partial', 'paid', 'overdue'];

    /**
     * Fields that may change after issue/cancel. Everything else is frozen
     * financial content. ZATCA placeholder columns stay writable for a future
     * e-invoicing layer and are not populated in this phase.
     *
     * @var array<int, string>
     */
    private const MUTABLE_WHEN_LOCKED = [
        'status',
        'payment_status',
        'amount_paid',
        'amount_due',
        'amount_credited',
        'amount_debited',
        'last_reminder_sent_at',
        'reminder_stage',
        'cancelled_at',
        'notes',
        'zatca_uuid',
        'zatca_qr_code',
        'zatca_xml_hash',
        'updated_at',
    ];

    protected function casts(): array
    {
        return [
            'issue_date' => 'date',
            'due_date' => 'date',
            'supply_date' => 'date',
            'issued_at' => 'datetime',
            'subtotal' => 'decimal:2',
            'discount' => 'decimal:2',
            'taxable_amount' => 'decimal:2',
            'tax_amount' => 'decimal:2',
            'total' => 'decimal:2',
            'amount_paid' => 'decimal:2',
            'amount_due' => 'decimal:2',
            'amount_credited' => 'decimal:2',
            'amount_debited' => 'decimal:2',
            'tax_rate' => 'decimal:2',
            'tax_breakdown' => 'array',
            'company_snapshot' => 'array',
            'recipient_snapshot' => 'array',
            'pdf_snapshot' => 'array',
            'cancelled_at' => 'datetime',
            'last_reminder_sent_at' => 'datetime',
        ];
    }

    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }

    public function supplier(): BelongsTo
    {
        return $this->belongsTo(FinanceSupplier::class, 'supplier_id');
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function items(): HasMany
    {
        return $this->hasMany(FinanceInvoiceItem::class, 'invoice_id');
    }

    public function payments(): HasMany
    {
        return $this->hasMany(FinanceInvoicePayment::class, 'invoice_id');
    }

    public function postedPayments(): HasMany
    {
        $query = $this->payments();
        if (self::hasPaymentStatusColumn()) {
            $query->where(function ($builder): void {
                $builder->whereNull('status')->orWhere('status', 'posted');
            });
        }

        return $query;
    }

    public function attachments(): HasMany
    {
        return $this->hasMany(FinanceInvoiceAttachment::class, 'invoice_id')->latest('id');
    }

    public function creditNotes(): HasMany
    {
        return $this->hasMany(FinanceCreditNote::class, 'invoice_id')->latest('id');
    }

    public function contract(): BelongsTo
    {
        return $this->belongsTo(Contract::class);
    }

    public function project(): BelongsTo
    {
        return $this->belongsTo(FinanceProject::class, 'project_id');
    }

    public function billingSchedule(): BelongsTo
    {
        return $this->belongsTo(FinanceBillingSchedule::class, 'billing_schedule_id');
    }

    public function issuer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'issued_by');
    }

    public function deliveries(): HasMany
    {
        return $this->hasMany(FinanceDocumentDelivery::class, 'document_id')
            ->whereIn('document_type', [
                FinanceDocumentType::Invoice->value,
                FinanceDocumentType::InvoiceReminder->value,
            ])
            ->latest('id');
    }

    public function receipts(): HasMany
    {
        return $this->hasMany(FinanceReceipt::class, 'invoice_id')->latest('id');
    }

    public function issuedSnapshot(): HasOne
    {
        return $this->hasOne(IssuedDocumentSnapshot::class, 'source_id')
            ->where('source_type', IssuedDocumentSnapshot::SOURCE_FINANCE_INVOICE);
    }

    protected static function booted(): void
    {
        parent::booted();

        static::updating(function (FinanceInvoice $invoice): void {
            $invoice->assertFinancialContentNotMutated();
        });

        static::deleting(function (FinanceInvoice $invoice): void {
            if ($invoice->isFinanciallyLocked()) {
                throw new RuntimeException('لا يمكن حذف فاتورة معتمدة أو ملغاة. استخدم الإلغاء أو إشعار دائن.');
            }
        });
    }

    public function isDraft(): bool
    {
        return $this->resolvedInvoiceStatus() === 'draft';
    }

    public function isIssued(): bool
    {
        return $this->resolvedInvoiceStatus() === 'issued';
    }

    public function isCancelled(): bool
    {
        return $this->resolvedInvoiceStatus() === 'cancelled';
    }

    public function isSendable(): bool
    {
        return $this->isIssued()
            && ! $this->trashed()
            && (string) $this->type === 'sales';
    }

    public function isRemindable(): bool
    {
        return $this->isSendable()
            && (float) $this->amount_due > 0.009;
    }

    public function isFinanciallyLocked(): bool
    {
        return in_array($this->resolvedInvoiceStatus(), ['issued', 'cancelled'], true);
    }

    public function snapshotsAreAuthoritative(): bool
    {
        return $this->isFinanciallyLocked();
    }

    public function resolvedInvoiceStatus(): string
    {
        $stored = $this->attributes['invoice_status'] ?? null;
        if (is_string($stored) && $stored !== '') {
            return $stored;
        }

        return $this->invoice_status;
    }

    private function assertFinancialContentNotMutated(): void
    {
        $originalStatus = (string) ($this->getOriginal('invoice_status') ?: $this->resolveLegacyOriginalStatus());
        if (! in_array($originalStatus, ['issued', 'cancelled'], true)) {
            return;
        }

        $dirty = $this->getDirty();
        if (array_key_exists('invoice_status', $dirty)) {
            $next = (string) $dirty['invoice_status'];
            if (! ($originalStatus === 'issued' && $next === 'cancelled')) {
                throw new RuntimeException('لا يمكن تغيير حالة الفاتورة المعتمدة إلا بالإلغاء.');
            }
            unset($dirty['invoice_status']);
        }

        $blocked = array_diff(array_keys($dirty), self::MUTABLE_WHEN_LOCKED);
        if ($blocked !== []) {
            throw new RuntimeException('الفاتورة المعتمدة أو الملغاة وثيقة مالية ثابتة ولا يمكن تعديل بياناتها.');
        }
    }

    private function resolveLegacyOriginalStatus(): string
    {
        $legacy = (string) ($this->getOriginal('status') ?? 'draft');
        if ($legacy === 'cancelled' || $legacy === 'draft') {
            return $legacy;
        }

        return 'issued';
    }

    public function getInvoiceStatusAttribute(?string $value): string
    {
        if (is_string($value) && $value !== '') {
            return $value;
        }

        $legacy = (string) ($this->attributes['status'] ?? 'draft');
        if ($legacy === 'cancelled') {
            return 'cancelled';
        }

        if ($legacy === 'draft') {
            return 'draft';
        }

        return 'issued';
    }

    public function getPaymentStatusAttribute(?string $value): string
    {
        if (is_string($value) && $value !== '') {
            return $value;
        }

        $legacy = (string) ($this->attributes['status'] ?? 'unpaid');

        return in_array($legacy, ['unpaid', 'partial', 'paid', 'overdue'], true)
            ? $legacy
            : 'unpaid';
    }

    public function scopeWhereInvoiceStatus(Builder $query, string $invoiceStatus): Builder
    {
        if (self::hasSeparatedStatusColumns()) {
            return $query->where('invoice_status', $invoiceStatus);
        }

        return match ($invoiceStatus) {
            'draft' => $query->where('status', 'draft'),
            'cancelled' => $query->where('status', 'cancelled'),
            'issued' => $query->whereIn('status', self::LEGACY_ISSUED_STATUSES),
            default => $query,
        };
    }

    public function scopeWherePaymentStatus(Builder $query, string $paymentStatus): Builder
    {
        if (self::hasSeparatedStatusColumns()) {
            return $query->where('payment_status', $paymentStatus);
        }

        return in_array($paymentStatus, ['unpaid', 'partial', 'paid', 'overdue'], true)
            ? $query->where('status', $paymentStatus)
            : $query;
    }

    public function scopeWhereIssued(Builder $query): Builder
    {
        if (self::hasSeparatedStatusColumns()) {
            return $query->where('invoice_status', 'issued');
        }

        return $query->whereIn('status', self::LEGACY_ISSUED_STATUSES);
    }

    public static function hasSeparatedStatusColumns(): bool
    {
        if (! array_key_exists('split_statuses', self::$schemaFlags)) {
            self::$schemaFlags['split_statuses'] = Schema::hasColumn('finance_invoices', 'invoice_status')
                && Schema::hasColumn('finance_invoices', 'payment_status');
        }

        return self::$schemaFlags['split_statuses'];
    }

    public static function hasSnapshotColumns(): bool
    {
        if (! array_key_exists('snapshots', self::$schemaFlags)) {
            self::$schemaFlags['snapshots'] = Schema::hasColumn('finance_invoices', 'company_snapshot')
                && Schema::hasColumn('finance_invoices', 'recipient_snapshot')
                && Schema::hasColumn('finance_invoices', 'pdf_snapshot');
        }

        return self::$schemaFlags['snapshots'];
    }

    public static function hasAdjustmentColumns(): bool
    {
        if (! array_key_exists('adjustments', self::$schemaFlags)) {
            self::$schemaFlags['adjustments'] = Schema::hasColumn('finance_invoices', 'amount_credited')
                && Schema::hasColumn('finance_invoices', 'amount_debited');
        }

        return self::$schemaFlags['adjustments'];
    }

    public static function hasPaymentStatusColumn(): bool
    {
        if (! array_key_exists('payment_row_status', self::$schemaFlags)) {
            self::$schemaFlags['payment_row_status'] = Schema::hasColumn('finance_invoice_payments', 'status');
        }

        return self::$schemaFlags['payment_row_status'];
    }

    public static function hasContractColumn(): bool
    {
        if (! array_key_exists('contract', self::$schemaFlags)) {
            self::$schemaFlags['contract'] = Schema::hasColumn('finance_invoices', 'contract_id');
        }

        return self::$schemaFlags['contract'];
    }

    public static function hasClassificationColumns(): bool
    {
        if (! array_key_exists('classification', self::$schemaFlags)) {
            self::$schemaFlags['classification'] = Schema::hasColumn('finance_invoices', 'tax_document_subtype')
                && Schema::hasColumn('finance_invoices', 'zatca_requirement');
        }

        return self::$schemaFlags['classification'];
    }

    public static function hasTaxEngineColumns(): bool
    {
        if (! array_key_exists('tax_engine', self::$schemaFlags)) {
            self::$schemaFlags['tax_engine'] = Schema::hasColumn('finance_invoices', 'tax_price_mode')
                && Schema::hasColumn('finance_invoices', 'tax_breakdown');
        }

        return self::$schemaFlags['tax_engine'];
    }
}
