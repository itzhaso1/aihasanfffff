<?php

namespace App\Models\Finance;

use App\Models\Concerns\BelongsToWorkspace;
use App\Models\Customer;
use App\Models\User;
use App\Models\WorkspaceScopedModel;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Facades\Schema;
use RuntimeException;

#[Fillable([
    'workspace_id',
    'invoice_id',
    'customer_id',
    'note_number',
    'type',
    'status',
    'reason',
    'issue_date',
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
    'issued_at',
    'cancelled_at',
    'created_by',
    'issued_by',
])]
class FinanceCreditNote extends WorkspaceScopedModel
{
    use BelongsToWorkspace, SoftDeletes;

    public const TYPE_CREDIT = 'credit';

    public const TYPE_DEBIT = 'debit';

    public const STATUS_DRAFT = 'draft';

    public const STATUS_ISSUED = 'issued';

    public const STATUS_CANCELLED = 'cancelled';

    /**
     * @var array<int, string>
     */
    private const MUTABLE_WHEN_LOCKED = [
        'status',
        'cancelled_at',
        'notes',
        'updated_at',
    ];

    protected function casts(): array
    {
        return [
            'issue_date' => 'date',
            'subtotal' => 'decimal:2',
            'discount' => 'decimal:2',
            'taxable_amount' => 'decimal:2',
            'tax_amount' => 'decimal:2',
            'total' => 'decimal:2',
            'tax_rate' => 'decimal:2',
            'tax_breakdown' => 'array',
            'issued_at' => 'datetime',
            'cancelled_at' => 'datetime',
        ];
    }

    public function invoice(): BelongsTo
    {
        return $this->belongsTo(FinanceInvoice::class, 'invoice_id');
    }

    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }

    public function items(): HasMany
    {
        return $this->hasMany(FinanceCreditNoteItem::class, 'credit_note_id');
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function issuer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'issued_by');
    }

    public function issuedSnapshot(): HasOne
    {
        return $this->hasOne(IssuedDocumentSnapshot::class, 'source_id')
            ->whereIn('source_type', [
                IssuedDocumentSnapshot::SOURCE_FINANCE_CREDIT_NOTE,
                IssuedDocumentSnapshot::SOURCE_FINANCE_DEBIT_NOTE,
            ]);
    }

    public function isCredit(): bool
    {
        return $this->type === self::TYPE_CREDIT;
    }

    public function isFinanciallyLocked(): bool
    {
        return in_array($this->status, [self::STATUS_ISSUED, self::STATUS_CANCELLED], true);
    }

    protected static function booted(): void
    {
        parent::booted();

        static::updating(function (FinanceCreditNote $note): void {
            $originalStatus = (string) ($note->getOriginal('status') ?? self::STATUS_DRAFT);
            if (! in_array($originalStatus, [self::STATUS_ISSUED, self::STATUS_CANCELLED], true)) {
                return;
            }

            $dirty = $note->getDirty();
            if (array_key_exists('status', $dirty)) {
                $next = (string) $dirty['status'];
                if (! ($originalStatus === self::STATUS_ISSUED && $next === self::STATUS_CANCELLED)) {
                    throw new RuntimeException('لا يمكن تغيير حالة الإشعار المعتمد إلا بالإلغاء.');
                }
                unset($dirty['status']);
            }

            $blocked = array_diff(array_keys($dirty), self::MUTABLE_WHEN_LOCKED);
            if ($blocked !== []) {
                throw new RuntimeException('الإشعار المعتمد أو الملغى وثيقة مالية ثابتة ولا يمكن تعديل بياناتها.');
            }
        });

        static::deleting(function (FinanceCreditNote $note): void {
            if ($note->isFinanciallyLocked()) {
                throw new RuntimeException('لا يمكن حذف إشعار معتمد أو ملغى.');
            }
        });
    }

    public static function hasTaxEngineColumns(): bool
    {
        return Schema::hasColumn('finance_credit_notes', 'tax_price_mode')
            && Schema::hasColumn('finance_credit_notes', 'tax_breakdown');
    }
}
