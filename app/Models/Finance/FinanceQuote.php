<?php

namespace App\Models\Finance;

use App\Enums\Finance\FinanceDocumentType;
use App\Enums\Finance\QuoteOutcomeStatus;
use App\Enums\Finance\QuoteStatus;
use App\Models\Concerns\BelongsToWorkspace;
use App\Models\Customer;
use App\Models\User;
use App\Models\WorkspaceScopedModel;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use RuntimeException;

#[Fillable([
    'workspace_id',
    'customer_id',
    'quote_number',
    'status',
    'outcome',
    'issue_date',
    'expiry_date',
    'currency',
    'subtotal',
    'discount',
    'taxable_amount',
    'tax_amount',
    'total',
    'tax_profile_type',
    'tax_rate',
    'tax_price_mode',
    'tax_breakdown',
    'notes',
    'terms',
    'company_snapshot',
    'recipient_snapshot',
    'pdf_snapshot',
    'created_by',
    'issued_by',
    'issued_at',
    'cancelled_at',
    'accepted_at',
    'accepted_by',
    'rejected_at',
    'rejected_by',
    'rejection_reason',
    'converted_invoice_id',
    'converted_at',
    'converted_by',
])]
class FinanceQuote extends WorkspaceScopedModel
{
    use BelongsToWorkspace, SoftDeletes;

    /**
     * Non-financial fields that may change after issue/cancel.
     *
     * @var array<int, string>
     */
    private const MUTABLE_WHEN_LOCKED = [
        'status',
        'cancelled_at',
        'notes',
        'updated_at',
        'outcome',
        'accepted_at',
        'accepted_by',
        'rejected_at',
        'rejected_by',
        'rejection_reason',
        'converted_invoice_id',
        'converted_at',
        'converted_by',
    ];

    protected function casts(): array
    {
        return [
            'issue_date' => 'date',
            'expiry_date' => 'date',
            'issued_at' => 'datetime',
            'cancelled_at' => 'datetime',
            'accepted_at' => 'datetime',
            'rejected_at' => 'datetime',
            'converted_at' => 'datetime',
            'subtotal' => 'decimal:2',
            'discount' => 'decimal:2',
            'taxable_amount' => 'decimal:2',
            'tax_amount' => 'decimal:2',
            'total' => 'decimal:2',
            'tax_rate' => 'decimal:2',
            'tax_breakdown' => 'array',
            'company_snapshot' => 'array',
            'recipient_snapshot' => 'array',
            'pdf_snapshot' => 'array',
        ];
    }

    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }

    public function items(): HasMany
    {
        return $this->hasMany(FinanceQuoteItem::class, 'quote_id');
    }

    public function deliveries(): HasMany
    {
        return $this->hasMany(FinanceDocumentDelivery::class, 'document_id')
            ->where('document_type', FinanceDocumentType::Quote->value)
            ->latest('id');
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function issuer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'issued_by');
    }

    public function acceptedByUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'accepted_by');
    }

    public function rejectedByUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'rejected_by');
    }

    public function convertedByUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'converted_by');
    }

    public function convertedInvoice(): BelongsTo
    {
        return $this->belongsTo(FinanceInvoice::class, 'converted_invoice_id');
    }

    public function attachments(): HasMany
    {
        return $this->hasMany(FinanceQuoteAttachment::class, 'quote_id')->latest('id');
    }

    protected static function booted(): void
    {
        parent::booted();

        static::updating(function (FinanceQuote $quote): void {
            $quote->assertFinancialContentNotMutated();
        });

        static::deleting(function (FinanceQuote $quote): void {
            if ($quote->isLocked()) {
                throw new RuntimeException('لا يمكن حذف عرض سعر صادر أو ملغى. استخدم الإلغاء للصادر.');
            }
        });
    }

    public function quoteStatus(): QuoteStatus
    {
        return QuoteStatus::tryFrom((string) $this->status) ?? QuoteStatus::Draft;
    }

    public function isDraft(): bool
    {
        return $this->quoteStatus() === QuoteStatus::Draft;
    }

    public function isIssued(): bool
    {
        return $this->quoteStatus() === QuoteStatus::Issued;
    }

    public function isCancelled(): bool
    {
        return $this->quoteStatus() === QuoteStatus::Cancelled;
    }

    public function isLocked(): bool
    {
        return $this->quoteStatus()->isLocked();
    }

    public function isSendable(): bool
    {
        return $this->isIssued() && ! $this->trashed();
    }

    public function quoteOutcome(): QuoteOutcomeStatus
    {
        return QuoteOutcomeStatus::tryFrom((string) ($this->outcome ?? QuoteOutcomeStatus::Pending->value))
            ?? QuoteOutcomeStatus::Pending;
    }

    public function isPendingOutcome(): bool
    {
        return $this->quoteOutcome() === QuoteOutcomeStatus::Pending;
    }

    public function isAccepted(): bool
    {
        return $this->quoteOutcome() === QuoteOutcomeStatus::Accepted;
    }

    public function isRejected(): bool
    {
        return $this->quoteOutcome() === QuoteOutcomeStatus::Rejected;
    }

    public function isConverted(): bool
    {
        return $this->quoteOutcome() === QuoteOutcomeStatus::Converted
            || $this->converted_invoice_id !== null;
    }

    public function isPastExpiry(): bool
    {
        if ($this->expiry_date === null) {
            return false;
        }

        return $this->expiry_date->toDateString() < now(config('app.timezone'))->toDateString();
    }

    public function isAcceptable(): bool
    {
        return $this->isIssued()
            && ! $this->trashed()
            && $this->isPendingOutcome()
            && ! $this->isConverted()
            && ! $this->isPastExpiry();
    }

    public function isRejectable(): bool
    {
        return $this->isIssued()
            && ! $this->trashed()
            && $this->isPendingOutcome()
            && ! $this->isConverted();
    }

    public function isConvertible(): bool
    {
        return $this->isIssued()
            && ! $this->trashed()
            && $this->isAccepted()
            && ! $this->isConverted()
            && ! $this->isPastExpiry();
    }

    public function snapshotsAreAuthoritative(): bool
    {
        return $this->isLocked();
    }

    private function assertFinancialContentNotMutated(): void
    {
        $original = QuoteStatus::tryFrom((string) ($this->getOriginal('status') ?? 'draft')) ?? QuoteStatus::Draft;
        if (! $original->isLocked()) {
            return;
        }

        $dirty = $this->getDirty();
        if (array_key_exists('status', $dirty)) {
            $next = QuoteStatus::tryFrom((string) $dirty['status']);
            if (! ($original === QuoteStatus::Issued && $next === QuoteStatus::Cancelled)) {
                throw new RuntimeException('لا يمكن تغيير حالة عرض السعر الصادر إلا بالإلغاء.');
            }
            unset($dirty['status']);
        }

        $blocked = array_diff(array_keys($dirty), self::MUTABLE_WHEN_LOCKED);
        if ($blocked !== []) {
            throw new RuntimeException('عرض السعر الصادر أو الملغى وثيقة ثابتة ولا يمكن تعديل بياناتها المالية.');
        }
    }
}
