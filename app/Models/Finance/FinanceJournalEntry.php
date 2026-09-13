<?php

namespace App\Models\Finance;

use App\Models\Concerns\BelongsToWorkspace;
use App\Models\User;
use App\Models\WorkspaceScopedModel;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable([
    'workspace_id',
    'entry_number',
    'entry_date',
    'type',
    'reference_type',
    'reference_id',
    'reverses_entry_id',
    'description',
    'status',
    'posted_by',
])]
class FinanceJournalEntry extends WorkspaceScopedModel
{
    use BelongsToWorkspace;

    protected function casts(): array
    {
        return [
            'entry_date' => 'date',
        ];
    }

    public function lines(): HasMany
    {
        return $this->hasMany(FinanceJournalEntryLine::class, 'journal_entry_id');
    }

    public function reversedBy(): HasMany
    {
        return $this->hasMany(self::class, 'reverses_entry_id');
    }

    protected static function booted(): void
    {
        parent::booted();

        static::updating(function (self $entry): void {
            $originalStatus = (string) $entry->getOriginal('status');
            if ($originalStatus === 'draft') {
                return;
            }

            $dirty = array_values(array_diff(array_keys($entry->getDirty()), ['updated_at']));
            $isReversalMark = $originalStatus === 'posted'
                && $dirty === ['status']
                && $entry->status === 'reversed';

            if (! $isReversalMark) {
                throw new \RuntimeException('Posted journal entries cannot be mutated. Use a reversal entry.');
            }
        });

        static::deleting(function (self $entry): void {
            if (in_array((string) $entry->status, ['posted', 'reversed'], true)) {
                throw new \RuntimeException('Posted journal entries cannot be deleted. Use a reversal entry.');
            }
        });
    }

    public function poster(): BelongsTo
    {
        return $this->belongsTo(User::class, 'posted_by');
    }
}
