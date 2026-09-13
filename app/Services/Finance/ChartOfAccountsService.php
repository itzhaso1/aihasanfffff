<?php

namespace App\Services\Finance;

use App\Models\Finance\FinanceAccount;
use App\Models\Workspace;

class ChartOfAccountsService
{
    /**
     * @var array<int, array{code:string,name:string,type:string,classification:string}>
     */
    private array $defaultAccounts = [
        ['code' => '1000', 'name' => 'Cash', 'type' => 'asset', 'classification' => 'current_asset'],
        ['code' => '1100', 'name' => 'Bank', 'type' => 'asset', 'classification' => 'current_asset'],
        ['code' => '1200', 'name' => 'Accounts Receivable', 'type' => 'asset', 'classification' => 'current_asset'],
        ['code' => '1210', 'name' => 'Employee Advances Receivable', 'type' => 'asset', 'classification' => 'current_asset'],
        ['code' => '1300', 'name' => 'Inventory', 'type' => 'asset', 'classification' => 'current_asset'],
        ['code' => '1400', 'name' => 'Input VAT Receivable', 'type' => 'asset', 'classification' => 'tax_asset'],
        ['code' => '2000', 'name' => 'Accounts Payable', 'type' => 'liability', 'classification' => 'current_liability'],
        ['code' => '2100', 'name' => 'Output VAT Payable', 'type' => 'liability', 'classification' => 'tax_liability'],
        ['code' => '2200', 'name' => 'Payroll Payable', 'type' => 'liability', 'classification' => 'current_liability'],
        ['code' => '3000', 'name' => 'Capital', 'type' => 'equity', 'classification' => 'equity'],
        ['code' => '3100', 'name' => 'Retained Earnings', 'type' => 'equity', 'classification' => 'equity'],
        ['code' => '4000', 'name' => 'Sales Revenue', 'type' => 'revenue', 'classification' => 'operating_revenue'],
        ['code' => '4100', 'name' => 'Service Revenue', 'type' => 'revenue', 'classification' => 'operating_revenue'],
        ['code' => '4200', 'name' => 'Foreign Exchange Gain', 'type' => 'revenue', 'classification' => 'other_income'],
        ['code' => '5000', 'name' => 'Salaries Expense', 'type' => 'expense', 'classification' => 'operating_expense'],
        ['code' => '5100', 'name' => 'Rent Expense', 'type' => 'expense', 'classification' => 'operating_expense'],
        ['code' => '5200', 'name' => 'Utilities Expense', 'type' => 'expense', 'classification' => 'operating_expense'],
        ['code' => '5300', 'name' => 'Cost of Goods Sold', 'type' => 'expense', 'classification' => 'cogs'],
        ['code' => '5400', 'name' => 'Foreign Exchange Loss', 'type' => 'expense', 'classification' => 'other_expense'],
        ['code' => '5900', 'name' => 'General Expense', 'type' => 'expense', 'classification' => 'operating_expense'],
    ];

    public function ensureDefaultAccounts(Workspace $workspace): void
    {
        foreach ($this->defaultAccounts as $defaultAccount) {
            FinanceAccount::withoutGlobalScopes()->updateOrCreate(
                [
                    'workspace_id' => $workspace->id,
                    'code' => $defaultAccount['code'],
                ],
                [
                    'workspace_id' => $workspace->id,
                    'name' => $defaultAccount['name'],
                    'type' => $defaultAccount['type'],
                    'classification' => $defaultAccount['classification'],
                    'is_system' => true,
                    'is_active' => true,
                    'allow_manual_entries' => true,
                ]
            );
        }
    }

    public function byCode(string $code, ?int $workspaceId = null): ?FinanceAccount
    {
        if ($workspaceId !== null) {
            return FinanceAccount::withoutGlobalScopes()
                ->where('workspace_id', $workspaceId)
                ->where('code', $code)
                ->first();
        }

        return FinanceAccount::query()->where('code', $code)->first();
    }
}
