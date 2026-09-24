<?php

namespace App\Services\Cashier\Assistant;

use App\Services\Cashier\Assistant\Contracts\CashierAssistantTool;
use App\Services\Cashier\Assistant\Tools\BusinessAlertsTool;
use App\Services\Cashier\Assistant\Tools\ExpensesPeriodTool;
use App\Services\Cashier\Assistant\Tools\InventoryLowStockTool;
use App\Services\Cashier\Assistant\Tools\InventoryValueTool;
use App\Services\Cashier\Assistant\Tools\ProfitNetTool;
use App\Services\Cashier\Assistant\Tools\SalesComparePeriodsTool;
use App\Services\Cashier\Assistant\Tools\SalesPaymentMethodsTool;
use App\Services\Cashier\Assistant\Tools\SalesPeriodTool;
use App\Services\Cashier\Assistant\Tools\SalesTodayTool;
use App\Services\Cashier\Assistant\Tools\SalesTopCustomersTool;
use App\Services\Cashier\Assistant\Tools\SalesTopProductsTool;
use InvalidArgumentException;

final class CashierAssistantToolRegistry
{
    /** @var array<string, CashierAssistantTool> */
    private array $tools = [];

    public function __construct(
        SalesTodayTool $salesTodayTool,
        SalesPeriodTool $salesPeriodTool,
        SalesTopProductsTool $salesTopProductsTool,
        SalesTopCustomersTool $salesTopCustomersTool,
        SalesPaymentMethodsTool $salesPaymentMethodsTool,
        SalesComparePeriodsTool $salesComparePeriodsTool,
        ExpensesPeriodTool $expensesPeriodTool,
        ProfitNetTool $profitNetTool,
        InventoryValueTool $inventoryValueTool,
        InventoryLowStockTool $inventoryLowStockTool,
        BusinessAlertsTool $businessAlertsTool,
    ) {
        foreach ([
            $salesTodayTool,
            $salesPeriodTool,
            $salesTopProductsTool,
            $salesTopCustomersTool,
            $salesPaymentMethodsTool,
            $salesComparePeriodsTool,
            $expensesPeriodTool,
            $profitNetTool,
            $inventoryValueTool,
            $inventoryLowStockTool,
            $businessAlertsTool,
        ] as $tool) {
            $this->tools[$tool->name()] = $tool;
        }
    }

    /**
     * @return list<CashierAssistantTool>
     */
    public function all(): array
    {
        return array_values($this->tools);
    }

    public function get(string $name): CashierAssistantTool
    {
        if (! isset($this->tools[$name])) {
            throw new InvalidArgumentException("Unknown cashier assistant tool [{$name}].");
        }

        return $this->tools[$name];
    }

    /**
     * @return list<array{name: string, description: string, permission: string, parameters: array<string, mixed>}>
     */
    public function catalog(): array
    {
        return array_map(fn (CashierAssistantTool $tool): array => [
            'name' => $tool->name(),
            'description' => $tool->description(),
            'permission' => $tool->permission(),
            'parameters' => $tool->parameterSchema(),
        ], $this->all());
    }
}
