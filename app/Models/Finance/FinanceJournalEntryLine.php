<?php

namespace App\Models\Finance;

use App\Models\Concerns\BelongsToWorkspace;
use App\Models\WorkspaceScopedModel;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable([
    'workspace_id',
    'journal_entry_id',
    'account_id',
    'description',
    'debit',
    'credit',
    'entity_type',
    'entity_id',
])]
class FinanceJournalEntryLine extends WorkspaceScopedModel
{
    use BelongsToWorkspace;

    protected function casts(): array
    {
        return [
            'debit' => 'decimal:2',
            'credit' => 'decimal:2',
        ];
    }

    public function journalEntry(): BelongsTo
    {
        return $this->belongsTo(FinanceJournalEntry::class, 'journal_entry_id');
    }

    public function account(): BelongsTo
    {
        return $this->belongsTo(FinanceAccount::class, 'account_id');
    }

    protected static function booted(): void
    {
        parent::booted();

        static::creating(function (self $line): void {
            if (! $line->journal_entry_id) {
                return;
            }

            $entry = FinanceJournalEntry::withoutGlobalScopes()->find($line->journal_entry_id);
            if ($entry && $entry->status !== 'draft') {
                throw new \RuntimeException('Cannot add lines to a posted journal entry.');
            }
        });

        static::updating(function (self $line): void {
            throw new \RuntimeException('Journal entry lines cannot be mutated. Use a reversal entry.');
        });

        static::deleting(function (self $line): void {
            $entry = $line->journalEntry()
                ?: FinanceJournalEntry::withoutGlobalScopes()->find($line->journal_entry_id);
            if ($entry && in_array((string) $entry->status, ['posted', 'reversed'], true)) {
                throw new \RuntimeException('Posted journal entry lines cannot be deleted.');
            }
        });
    }
}
