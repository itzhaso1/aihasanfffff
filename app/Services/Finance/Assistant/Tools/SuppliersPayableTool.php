<?php

namespace App\Services\Finance\Assistant\Tools;

use App\Models\User;
use App\Models\Workspace;
use App\Services\Finance\Assistant\Concerns\BuildsAssistantFacts;
use App\Services\Finance\Assistant\Contracts\FinanceAssistantTool;
use App\Services\Finance\Assistant\FinanceAssistantToolResult;
use App\Services\Finance\FinanceAccountingKpiService;
use App\Services\Finance\LedgerReportService;
use Illuminate\Support\Facades\Validator;

final class SuppliersPayableTool implements FinanceAssistantTool
{
    use BuildsAssistantFacts;

    public function __construct(
        private readonly FinanceAccountingKpiService $accountingKpiService,
        private readonly LedgerReportService $ledgerReportService,
    ) {}

    public function name(): string
    {
        return 'suppliers.payable';
    }

    public function description(): string
    {
        return 'Accounts payable balance and purchase invoice aging.';
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
        $today = now()->toDateString();
        $kpi = $this->accountingKpiService->period((int) $workspace->id, FinanceAccountingKpiService::LIFETIME_FROM, $today);
        $aging = $this->ledgerReportService->aging((int) $workspace->id, 'purchase', $today);

        return FinanceAssistantToolResult::available($this->name(), $this->factEnvelope(
            $workspace,
            'ledger_payables',
            FinanceAccountingKpiService::LIFETIME_FROM,
            $today,
            [
                'available' => true,
                'value' => $kpi['payables'],
                'aging' => $aging['buckets'] ?? [],
                'total_due' => $aging['total'] ?? $kpi['payables'],
            ],
        ));
    }
}
