<?php

namespace App\Services\Finance;

use App\Models\Finance\FinanceTreasuryAccount;
use App\Support\Money\Money;

class TreasuryBalanceService
{
    public function adjust(FinanceTreasuryAccount $account, float $delta): FinanceTreasuryAccount
    {
        $locked = FinanceTreasuryAccount::withoutGlobalScopes()
            ->whereKey($account->id)
            ->lockForUpdate()
            ->firstOrFail();

        $locked->update([
            'current_balance' => Money::add($locked->current_balance, $delta),
        ]);

        return $locked->refresh();
    }
}
