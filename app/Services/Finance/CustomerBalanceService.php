<?php

namespace App\Services\Finance;

use App\Models\Finance\FinanceInvoice;
use Illuminate\Support\Collection;

class CustomerBalanceService
{
    /**
     * Accounts-receivable outstanding from issued sales invoices.
     *
     * Source of truth is invoice amount_due (already net of posted payments
     * and issued credit/debit notes). customers.balance is a stored cache
     * and must not be used for display or decisions.
     */
    public function outstanding(int $workspaceId, int $customerId): float
    {
        $map = $this->outstandingByCustomerIds($workspaceId, [$customerId]);

        return (float) ($map[$customerId] ?? 0);
    }

    /**
     * @param  array<int, int>  $customerIds
     * @return array<int, float>
     */
    public function outstandingByCustomerIds(int $workspaceId, array $customerIds): array
    {
        $ids = array_values(array_unique(array_filter(array_map('intval', $customerIds))));
        if ($ids === []) {
            return [];
        }

        /** @var Collection<int, object{customer_id:int, outstanding:string|float|int}> $rows */
        $rows = FinanceInvoice::withoutGlobalScopes()
            ->where('workspace_id', $workspaceId)
            ->where('type', 'sales')
            ->whereIn('customer_id', $ids)
            ->whereInvoiceStatus('issued')
            ->groupBy('customer_id')
            ->selectRaw('customer_id, COALESCE(SUM(amount_due), 0) as outstanding')
            ->get();

        $map = [];
        foreach ($ids as $id) {
            $map[$id] = 0.0;
        }
        foreach ($rows as $row) {
            $map[(int) $row->customer_id] = round((float) $row->outstanding, 2);
        }

        return $map;
    }
}
