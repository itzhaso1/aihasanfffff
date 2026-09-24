<?php

namespace App\Services\Finance\Assistant\Tools;

use App\Models\User;
use App\Models\Workspace;
use App\Services\Finance\Assistant\Concerns\BuildsAssistantFacts;
use App\Services\Finance\Assistant\Contracts\FinanceAssistantTool;
use App\Services\Finance\Assistant\FinanceAssistantToolResult;
use App\Services\Finance\LedgerReportService;
use Illuminate\Support\Facades\Validator;

final class InventoryValueTool implements FinanceAssistantTool
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

    public function execute(Workspace $workspace, User $user, array $arguments): FinanceAssistantToolResult
    {
        $valuation = $this->ledgerReportService->inventoryValuation((int) $workspace->id);
        $today = now()->toDateString();

        return FinanceAssistantToolResult::available($this->name(), $this->factEnvelope(
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
