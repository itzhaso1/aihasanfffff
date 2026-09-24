<?php

namespace App\Services\Finance\Assistant;

use App\Services\Finance\Assistant\Contracts\FinanceAssistantTool;
use App\Services\Finance\Assistant\Security\FinanceAgentToolSecurityCatalog;
use App\Services\Finance\Assistant\Tools\BillingSchedulesUpcomingTool;
use App\Services\Finance\Assistant\Tools\ComparePeriodsTool;
use App\Services\Finance\Assistant\Tools\ContractsBalanceTool;
use App\Services\Finance\Assistant\Tools\ContractsDetailsTool;
use App\Services\Finance\Assistant\Tools\ContractsInvoicesTool;
use App\Services\Finance\Assistant\Tools\ContractsLookupTool;
use App\Services\Finance\Assistant\Tools\ContractsSchedulesTool;
use App\Services\Finance\Assistant\Tools\ContractsUnbilledTool;
use App\Services\Finance\Assistant\Tools\CustomersBalanceTool;
use App\Services\Finance\Assistant\Tools\CustomersInvoicesTool;
use App\Services\Finance\Assistant\Tools\CustomersLookupTool;
use App\Services\Finance\Assistant\Tools\CustomersOverdueTool;
use App\Services\Finance\Assistant\Tools\CustomersStatementTool;
use App\Services\Finance\Assistant\Tools\CustomersTopTool;
use App\Services\Finance\Assistant\Tools\ExpensesByCategoryTool;
use App\Services\Finance\Assistant\Tools\ExpensesPeriodTool;
use App\Services\Finance\Assistant\Tools\FinanceAlertsTool;
use App\Services\Finance\Assistant\Tools\FinanceSummaryTool;
use App\Services\Finance\Assistant\Tools\InventoryValueTool;
use App\Services\Finance\Assistant\Tools\InvoicesByCustomerTool;
use App\Services\Finance\Assistant\Tools\InvoicesCountTool;
use App\Services\Finance\Assistant\Tools\InvoicesDetailsTool;
use App\Services\Finance\Assistant\Tools\InvoicesLookupTool;
use App\Services\Finance\Assistant\Tools\InvoicesOverdueTool;
use App\Services\Finance\Assistant\Tools\InvoicesSummaryTool;
use App\Services\Finance\Assistant\Tools\PayrollEmployeesCountTool;
use App\Services\Finance\Assistant\Tools\PayrollPeriodTool;
use App\Services\Finance\Assistant\Tools\PayrollTotalTool;
use App\Services\Finance\Assistant\Tools\ProductsTopTool;
use App\Services\Finance\Assistant\Tools\ProfitCogsTool;
use App\Services\Finance\Assistant\Tools\ProfitGrossTool;
use App\Services\Finance\Assistant\Tools\ProfitNetTool;
use App\Services\Finance\Assistant\Tools\SalesPeriodTool;
use App\Services\Finance\Assistant\Tools\SalesTodayTool;
use App\Services\Finance\Assistant\Tools\SuppliersPayableTool;
use InvalidArgumentException;

final class FinanceAssistantToolRegistry
{
    /** @var array<string, FinanceAssistantTool> */
    private array $tools = [];

    public function __construct(
        SalesTodayTool $salesTodayTool,
        SalesPeriodTool $salesPeriodTool,
        ExpensesPeriodTool $expensesPeriodTool,
        ExpensesByCategoryTool $expensesByCategoryTool,
        ProfitNetTool $profitNetTool,
        ProfitGrossTool $profitGrossTool,
        ProfitCogsTool $profitCogsTool,
        PayrollPeriodTool $payrollPeriodTool,
        PayrollTotalTool $payrollTotalTool,
        PayrollEmployeesCountTool $payrollEmployeesCountTool,
        SuppliersPayableTool $suppliersPayableTool,
        InventoryValueTool $inventoryValueTool,
        CustomersTopTool $customersTopTool,
        ProductsTopTool $productsTopTool,
        ComparePeriodsTool $comparePeriodsTool,
        FinanceSummaryTool $financeSummaryTool,
        FinanceAlertsTool $financeAlertsTool,
        InvoicesLookupTool $invoicesLookupTool,
        InvoicesDetailsTool $invoicesDetailsTool,
        InvoicesOverdueTool $invoicesOverdueTool,
        InvoicesByCustomerTool $invoicesByCustomerTool,
        InvoicesSummaryTool $invoicesSummaryTool,
        InvoicesCountTool $invoicesCountTool,
        CustomersLookupTool $customersLookupTool,
        CustomersBalanceTool $customersBalanceTool,
        CustomersInvoicesTool $customersInvoicesTool,
        CustomersOverdueTool $customersOverdueTool,
        CustomersStatementTool $customersStatementTool,
        ContractsLookupTool $contractsLookupTool,
        ContractsDetailsTool $contractsDetailsTool,
        ContractsBalanceTool $contractsBalanceTool,
        ContractsInvoicesTool $contractsInvoicesTool,
        ContractsUnbilledTool $contractsUnbilledTool,
        ContractsSchedulesTool $contractsSchedulesTool,
        BillingSchedulesUpcomingTool $billingSchedulesUpcomingTool,
    ) {
        foreach ([
            $salesTodayTool,
            $salesPeriodTool,
            $expensesPeriodTool,
            $expensesByCategoryTool,
            $profitNetTool,
            $profitGrossTool,
            $profitCogsTool,
            $payrollPeriodTool,
            $payrollTotalTool,
            $payrollEmployeesCountTool,
            $suppliersPayableTool,
            $inventoryValueTool,
            $customersTopTool,
            $productsTopTool,
            $comparePeriodsTool,
            $financeSummaryTool,
            $financeAlertsTool,
            $invoicesLookupTool,
            $invoicesDetailsTool,
            $invoicesOverdueTool,
            $invoicesByCustomerTool,
            $invoicesSummaryTool,
            $invoicesCountTool,
            $customersLookupTool,
            $customersBalanceTool,
            $customersInvoicesTool,
            $customersOverdueTool,
            $customersStatementTool,
            $contractsLookupTool,
            $contractsDetailsTool,
            $contractsBalanceTool,
            $contractsInvoicesTool,
            $contractsUnbilledTool,
            $contractsSchedulesTool,
            $billingSchedulesUpcomingTool,
        ] as $tool) {
            $this->tools[$tool->name()] = $tool;
        }

        app(FinanceAgentToolSecurityCatalog::class)->assertRegistryCovered(array_keys($this->tools));
    }

    /**
     * @return list<FinanceAssistantTool>
     */
    public function all(): array
    {
        return array_values($this->tools);
    }

    public function get(string $name): FinanceAssistantTool
    {
        if (! isset($this->tools[$name])) {
            throw new InvalidArgumentException("Unknown finance assistant tool [{$name}].");
        }

        return $this->tools[$name];
    }

    /**
     * @return list<array{name: string, description: string, permission: string, parameters: array<string, mixed>}>
     */
    public function catalog(): array
    {
        return array_map(fn (FinanceAssistantTool $tool): array => [
            'name' => $tool->name(),
            'description' => $tool->description(),
            'permission' => $tool->permission(),
            'parameters' => $tool->parameterSchema(),
        ], $this->all());
    }
}
