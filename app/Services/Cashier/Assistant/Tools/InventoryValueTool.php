<?php

namespace App\Services\Cashier\Assistant\Tools;

use App\Models\User;
use App\Models\Workspace;
use App\Services\Cashier\Assistant\CashierAssistantToolResult;
use App\Services\Cashier\Assistant\Concerns\BuildsAssistantFacts;
use App\Services\Cashier\Assistant\Contracts\CashierAssistantTool;
use App\Services\Finance\LedgerReportService;
use Illuminate\Support\Facades\Validator;

final class InventoryValueTool implements CashierAssistantTool
{
    use BuildsAssistantFacts;

    public function __construct(
        private readonly LedgerReportService $ledgerReportService,
    ) {}

    public function name(): string
    {
        return 'inventory.value';
    }

    public function description(): string
    {
        return 'Inventory valuation from tracked products cost × stock.';
    }

    public function permission(): string
    {
        return 'finance.view';
    }

    public function parameterSchema(): array
    {
        return [];
    }

    public function validateArguments(array $arguments): array
    {
        Validator::validate($arguments, []);

        return [];
    }

    public function execute(Workspace $workspace, User $user, array $arguments): CashierAssistantToolResult
    {
        $valuation = $this->ledgerReportService->inventoryValuation((int) $workspace->id);
        $today = now()->toDateString();

        return CashierAssistantToolResult::available($this->name(), $this->factEnvelope(
            $workspace,
            'inventory_valuation',
            $today,
            $today,
            [
                'available' => true,
                'value' => $valuation['total'],
                'products_count' => count($valuation['rows'] ?? []),
            ],
        ));
    }
}
