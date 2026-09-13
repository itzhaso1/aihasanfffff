<?php

namespace App\Services\Finance;

use App\Models\Finance\FinanceAccount;
use App\Models\Finance\FinanceJournalEntry;
use App\Models\Finance\FinanceJournalEntryLine;
use App\Support\Money\Money;
use Illuminate\Support\Facades\DB;
use RuntimeException;

class AccountingService
{
    public function __construct(
        private readonly FinancialPeriodGuardService $financialPeriodGuardService,
    ) {}

    /**
     * @param  array<int, array{account_id:int,debit:float|int|string,credit:float|int|string,description?:string|null,entity_type?:string|null,entity_id?:int|null}>  $lines
     */
    public function createEntry(
        int $workspaceId,
        string $entryDate,
        string $type,
        array $lines,
        ?string $description = null,
        ?string $referenceType = null,
        ?int $referenceId = null,
        ?int $postedBy = null,
        string $status = 'posted',
        ?int $reversesEntryId = null,
    ): FinanceJournalEntry {
        if (count($lines) < 2) {
            throw new RuntimeException('Journal entry requires at least two lines.');
        }

        [$totalDebit, $totalCredit] = $this->totals($lines);
        if (Money::cmp($totalDebit, $totalCredit) !== 0) {
            throw new RuntimeException('Journal entry is not balanced. Total debit must equal total credit.');
        }

        $this->financialPeriodGuardService->assertDateIsOpen(
            workspaceId: $workspaceId,
            date: $entryDate,
            context: 'ترحيل قيد محاسبي'
        );

        $finalStatus = in_array($status, ['draft', 'posted'], true) ? $status : 'posted';

        return DB::transaction(function () use (
            $workspaceId,
            $entryDate,
            $type,
            $lines,
            $description,
            $referenceType,
            $referenceId,
            $postedBy,
            $finalStatus,
            $reversesEntryId
        ): FinanceJournalEntry {
            $entryNumber = $this->nextEntryNumber($workspaceId, $entryDate);

            $entry = FinanceJournalEntry::withoutGlobalScopes()->create([
                'workspace_id' => $workspaceId,
                'entry_number' => $entryNumber,
                'entry_date' => $entryDate,
                'type' => $type,
                'reference_type' => $referenceType,
                'reference_id' => $referenceId,
                'reverses_entry_id' => $reversesEntryId,
                'description' => $description,
                'status' => 'draft',
                'posted_by' => $postedBy,
            ]);

            foreach ($lines as $line) {
                $account = FinanceAccount::withoutGlobalScopes()
                    ->where('workspace_id', $workspaceId)
                    ->whereKey((int) $line['account_id'])
                    ->first();

                if (! $account) {
                    throw new RuntimeException('Accounting line account is invalid for this workspace.');
                }

                FinanceJournalEntryLine::withoutGlobalScopes()->create([
                    'workspace_id' => $workspaceId,
                    'journal_entry_id' => $entry->id,
                    'account_id' => $account->id,
                    'description' => $line['description'] ?? null,
                    'debit' => Money::of($line['debit']),
                    'credit' => Money::of($line['credit']),
                    'entity_type' => $line['entity_type'] ?? null,
                    'entity_id' => $line['entity_id'] ?? null,
                ]);
            }

            if ($finalStatus === 'posted') {
                $entry->update(['status' => 'posted']);
            }

            return $entry->fresh('lines');
        });
    }

    public function reverseEntry(
        FinanceJournalEntry $entry,
        string $type,
        int $actorUserId,
        ?string $description = null,
        ?string $referenceType = null,
        ?int $referenceId = null,
    ): FinanceJournalEntry {
        $alreadyReversed = FinanceJournalEntry::withoutGlobalScopes()
            ->where('workspace_id', $entry->workspace_id)
            ->where('reverses_entry_id', $entry->id)
            ->exists();
        if ($alreadyReversed || $entry->status === 'reversed') {
            throw new RuntimeException('Journal entry is already reversed.');
        }

        if ($entry->status !== 'posted') {
            throw new RuntimeException('Only posted journal entries can be reversed.');
        }

        $entry->loadMissing('lines');
        if ($entry->lines->count() < 2) {
            throw new RuntimeException('Cannot reverse an incomplete journal entry.');
        }

        $lines = $entry->lines->map(fn (FinanceJournalEntryLine $line): array => [
            'account_id' => (int) $line->account_id,
            'debit' => Money::of($line->credit),
            'credit' => Money::of($line->debit),
            'description' => 'Reversal: '.((string) ($line->description ?: $entry->description)),
            'entity_type' => $line->entity_type,
            'entity_id' => $line->entity_id,
        ])->all();

        return DB::transaction(function () use ($entry, $type, $actorUserId, $description, $referenceType, $referenceId, $lines): FinanceJournalEntry {
            $reversal = $this->createEntry(
                workspaceId: (int) $entry->workspace_id,
                entryDate: now()->toDateString(),
                type: $type,
                lines: $lines,
                description: $description ?: ('Reversal of '.$entry->entry_number),
                referenceType: $referenceType ?: $entry->reference_type,
                referenceId: $referenceId ?: $entry->reference_id,
                postedBy: $actorUserId,
                reversesEntryId: $entry->id,
            );

            $entry->update(['status' => 'reversed']);

            return $reversal;
        });
    }

    /**
     * @param  array<int, array{debit:float|int|string,credit:float|int|string}>  $lines
     * @return array{0:string,1:string}
     */
    private function totals(array $lines): array
    {
        $totalDebit = '0.00';
        $totalCredit = '0.00';

        foreach ($lines as $line) {
            $debit = Money::of($line['debit']);
            $credit = Money::of($line['credit']);

            if (Money::cmp($debit, '0') < 0 || Money::cmp($credit, '0') < 0) {
                throw new RuntimeException('Debit/Credit cannot be negative.');
            }

            if (Money::isPositive($debit) && Money::isPositive($credit)) {
                throw new RuntimeException('Single line cannot have both debit and credit amounts.');
            }

            if (Money::isZero($debit) && Money::isZero($credit)) {
                throw new RuntimeException('Journal line must have a debit or a credit amount.');
            }

            $totalDebit = Money::add($totalDebit, $debit);
            $totalCredit = Money::add($totalCredit, $credit);
        }

        return [$totalDebit, $totalCredit];
    }

    private function nextEntryNumber(int $workspaceId, string $entryDate): string
    {
        $year = date('Y', strtotime($entryDate));
        $prefix = 'JE-'.$year.'-';

        $last = FinanceJournalEntry::withoutGlobalScopes()
            ->where('workspace_id', $workspaceId)
            ->where('entry_number', 'like', $prefix.'%')
            ->lockForUpdate()
            ->orderByDesc('id')
            ->first();

        if (! $last) {
            return $prefix.'000001';
        }

        $lastNumber = (int) substr($last->entry_number, -6);

        return $prefix.str_pad((string) ($lastNumber + 1), 6, '0', STR_PAD_LEFT);
    }
}
