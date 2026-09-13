<?php

namespace App\Http\Controllers\Api\Finance\V1;

use App\Http\Controllers\Api\Finance\Concerns\HandlesFinanceClient;
use App\Http\Controllers\Api\Finance\FinanceApiController;
use App\Models\Finance\FinanceAccount;
use App\Services\Finance\FinanceExportService;
use App\Services\Finance\LedgerReportService;
use App\Support\Tenancy\WorkspaceContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Symfony\Component\HttpFoundation\StreamedResponse;

class ReportController extends FinanceApiController
{
    use HandlesFinanceClient;

    public function __construct(
        private readonly WorkspaceContext $workspaceContext,
        private readonly LedgerReportService $ledgerReportService,
        private readonly FinanceExportService $financeExportService,
    ) {}

    public function show(Request $request, string $report): JsonResponse|StreamedResponse
    {
        $workspace = $this->clientWorkspace($this->workspaceContext);
        $this->clientActor($request, $workspace, 'reports.view');
        $workspaceId = (int) $workspace->id;
        $from = $this->resolveDate($request->input('from'), now()->startOfMonth())->toDateString();
        $to = $this->resolveDate($request->input('to'), now()->endOfMonth())->toDateString();

        $data = match ($report) {
            'profit-loss' => ['profit_and_loss' => $this->ledgerReportService->profitAndLoss($workspaceId, $from, $to)],
            'balance-sheet' => ['balance_sheet' => $this->ledgerReportService->balanceSheet($workspaceId, $to)],
            'trial-balance' => ['trial_balance' => $this->ledgerReportService->trialBalance($workspaceId, $to)],
            'general-ledger' => [
                'general_ledger' => $this->ledgerReportService->generalLedger(
                    $workspaceId,
                    $request->integer('account_id') ?: null,
                    $from,
                    $to
                ),
                'accounts' => FinanceAccount::query()->orderBy('code')->get(['id', 'code', 'name']),
            ],
            'ar-aging' => ['aging' => $this->ledgerReportService->aging($workspaceId, 'sales', $to)],
            'ap-aging' => ['aging' => $this->ledgerReportService->aging($workspaceId, 'purchase', $to)],
            'cash-flow' => ['cash_flow' => $this->ledgerReportService->cashFlow($workspaceId, $from, $to)],
            'inventory-valuation' => ['inventory_valuation' => $this->ledgerReportService->inventoryValuation($workspaceId)],
            default => abort(404),
        };

        if ($request->string('format')->toString() === 'csv') {
            return $this->csv($report, $data, $from, $to);
        }

        return $this->ok([
            'report' => $report,
            'from' => $from,
            'to' => $to,
            ...$data,
        ]);
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private function csv(string $report, array $data, string $from, string $to): StreamedResponse
    {
        if (isset($data['aging'])) {
            return $this->financeExportService->stream(
                $report.'-'.$to.'.csv',
                ['invoice_number', 'due_date', 'days_overdue', 'amount_due', 'bucket'],
                function ($out) use ($data): void {
                    foreach ($data['aging']['rows'] ?? [] as $row) {
                        fputcsv($out, [
                            $row['invoice_number'] ?? '',
                            $row['due_date'] ?? '',
                            $row['days_overdue'] ?? 0,
                            $row['amount_due'] ?? '0.00',
                            $row['bucket'] ?? '',
                        ]);
                    }
                }
            );
        }

        $rowsKey = match (true) {
            isset($data['profit_and_loss']) => 'profit_and_loss',
            isset($data['trial_balance']) => 'trial_balance',
            isset($data['balance_sheet']) => 'balance_sheet',
            isset($data['general_ledger']) => 'general_ledger',
            isset($data['cash_flow']) => 'cash_flow',
            default => null,
        };

        if ($rowsKey === 'cash_flow') {
            $cash = $data['cash_flow'];

            return $this->financeExportService->stream(
                'cash-flow-'.$from.'.csv',
                ['metric', 'amount'],
                function ($out) use ($cash): void {
                    foreach (['opening_cash', 'net_change', 'closing_cash'] as $key) {
                        fputcsv($out, [$key, $cash[$key] ?? '0.00']);
                    }
                }
            );
        }

        if (isset($data['inventory_valuation'])) {
            return $this->financeExportService->stream(
                'inventory-valuation.csv',
                ['sku', 'name', 'stock', 'cost', 'value'],
                function ($out) use ($data): void {
                    foreach ($data['inventory_valuation']['rows'] ?? [] as $row) {
                        if (! is_array($row)) {
                            continue;
                        }
                        fputcsv($out, [
                            $row['sku'] ?? '',
                            $row['name'] ?? '',
                            $row['stock'] ?? '',
                            $row['cost'] ?? '0.00',
                            $row['value'] ?? '0.00',
                        ]);
                    }
                }
            );
        }

        if ($rowsKey === null) {
            abort(404);
        }

        return $this->financeExportService->stream(
            $report.'-'.$from.'.csv',
            ['code', 'name', 'type', 'debit', 'credit', 'balance'],
            function ($out) use ($data, $rowsKey): void {
                $block = $data[$rowsKey];
                $rows = is_array($block) ? ($block['rows'] ?? $block) : [];
                foreach ($rows as $row) {
                    if (! is_array($row)) {
                        continue;
                    }
                    fputcsv($out, [
                        $row['code'] ?? '',
                        $row['name'] ?? ($row['account'] ?? ''),
                        $row['type'] ?? ($row['date'] ?? ''),
                        $row['debit'] ?? '',
                        $row['credit'] ?? '',
                        $row['balance'] ?? '',
                    ]);
                }
            }
        );
    }

    private function resolveDate(mixed $value, Carbon $fallback): Carbon
    {
        if (! $value) {
            return $fallback;
        }

        try {
            return Carbon::parse((string) $value);
        } catch (\Throwable) {
            return $fallback;
        }
    }
}
