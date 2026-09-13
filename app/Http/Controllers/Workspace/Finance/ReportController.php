<?php

namespace App\Http\Controllers\Workspace\Finance;

use App\Models\Finance\FinanceAccount;
use App\Services\Finance\FinanceAnalyticsService;
use App\Services\Finance\FinanceExportService;
use App\Services\Finance\LedgerReportService;
use App\Services\Finance\PeriodComparisonService;
use App\Services\Finance\ReportService;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\View\View;
use Symfony\Component\HttpFoundation\StreamedResponse;

class ReportController extends FinanceBaseController
{
    public function __construct(
        private readonly ReportService $reportService,
        private readonly LedgerReportService $ledgerReportService,
        private readonly PeriodComparisonService $periodComparisonService,
        private readonly FinanceAnalyticsService $financeAnalyticsService,
        private readonly FinanceExportService $financeExportService,
    ) {}

    public function index(Request $request): View
    {
        $this->authorizeFinance($request, 'reports.view');

        $from = $this->resolveDate($request->input('from'), now()->startOfMonth())->toDateString();
        $to = $this->resolveDate($request->input('to'), now()->endOfMonth())->toDateString();
        $summary = $this->reportService->summary($from, $to);
        $workspaceId = (int) $this->currentWorkspace()->id;

        return view('workspace.finance.reports.index', [
            'from' => $from,
            'to' => $to,
            'profitAndLoss' => $this->ledgerReportService->profitAndLoss($workspaceId, $from, $to),
            'trialBalance' => $this->ledgerReportService->trialBalance($workspaceId, $to),
            'cashFlow' => $this->ledgerReportService->cashFlow($workspaceId, $from, $to),
            'periods' => $this->periodComparisonService->compare($workspaceId),
            'analytics' => $this->financeAnalyticsService->dashboard($workspaceId, [
                'from' => $from,
                'to' => $to,
            ]),
            ...$summary,
        ]);
    }

    public function show(Request $request, string $report): View|StreamedResponse
    {
        $this->authorizeFinance($request, 'reports.view');
        $workspaceId = (int) $this->currentWorkspace()->id;
        $from = $this->resolveDate($request->input('from'), now()->startOfMonth())->toDateString();
        $to = $this->resolveDate($request->input('to'), now()->endOfMonth())->toDateString();

        $data = match ($report) {
            'profit-loss' => ['profitAndLoss' => $this->ledgerReportService->profitAndLoss($workspaceId, $from, $to)],
            'balance-sheet' => ['balanceSheet' => $this->ledgerReportService->balanceSheet($workspaceId, $to)],
            'trial-balance' => ['trialBalance' => $this->ledgerReportService->trialBalance($workspaceId, $to)],
            'general-ledger' => [
                'generalLedger' => $this->ledgerReportService->generalLedger(
                    $workspaceId,
                    $request->integer('account_id') ?: null,
                    $from,
                    $to
                ),
                'accounts' => FinanceAccount::query()->orderBy('code')->get(['id', 'code', 'name']),
            ],
            'ar-aging' => ['aging' => $this->ledgerReportService->aging($workspaceId, 'sales', $to)],
            'ap-aging' => ['aging' => $this->ledgerReportService->aging($workspaceId, 'purchase', $to)],
            'cash-flow' => ['cashFlow' => $this->ledgerReportService->cashFlow($workspaceId, $from, $to)],
            'inventory-valuation' => ['inventoryValuation' => $this->ledgerReportService->inventoryValuation($workspaceId)],
            default => abort(404),
        };

        if ($request->string('format')->toString() === 'csv') {
            return $this->csv($report, $data, $from, $to);
        }

        return view('workspace.finance.reports.show', [
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

        if (isset($data['profitAndLoss'])) {
            return $this->financeExportService->stream(
                'profit-loss-'.$from.'.csv',
                ['code', 'name', 'type', 'balance'],
                function ($out) use ($data): void {
                    foreach ($data['profitAndLoss']['rows'] ?? [] as $row) {
                        fputcsv($out, [$row['code'] ?? '', $row['name'] ?? '', $row['type'] ?? '', $row['balance'] ?? '0.00']);
                    }
                }
            );
        }

        if (isset($data['trialBalance'])) {
            return $this->financeExportService->stream(
                'trial-balance-'.$to.'.csv',
                ['code', 'name', 'debit', 'credit'],
                function ($out) use ($data): void {
                    foreach ($data['trialBalance']['rows'] ?? [] as $row) {
                        fputcsv($out, [$row['code'] ?? '', $row['name'] ?? '', $row['debit'] ?? '0.00', $row['credit'] ?? '0.00']);
                    }
                }
            );
        }

        if (isset($data['balanceSheet'])) {
            return $this->financeExportService->stream(
                'balance-sheet-'.$to.'.csv',
                ['code', 'name', 'type', 'balance'],
                function ($out) use ($data): void {
                    foreach ($data['balanceSheet']['rows'] ?? [] as $row) {
                        fputcsv($out, [$row['code'] ?? '', $row['name'] ?? '', $row['type'] ?? '', $row['balance'] ?? '0.00']);
                    }
                }
            );
        }

        if (isset($data['generalLedger'])) {
            return $this->financeExportService->stream(
                'general-ledger-'.$from.'.csv',
                ['date', 'entry', 'account', 'debit', 'credit'],
                function ($out) use ($data): void {
                    foreach ($data['generalLedger']['rows'] ?? $data['generalLedger'] ?? [] as $row) {
                        if (! is_array($row)) {
                            continue;
                        }
                        fputcsv($out, [
                            $row['date'] ?? '',
                            $row['entry_number'] ?? ($row['description'] ?? ''),
                            $row['account'] ?? ($row['code'] ?? ''),
                            $row['debit'] ?? '0.00',
                            $row['credit'] ?? '0.00',
                        ]);
                    }
                }
            );
        }

        if (isset($data['cashFlow'])) {
            $cash = $data['cashFlow'];

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

        if (isset($data['inventoryValuation'])) {
            return $this->financeExportService->stream(
                'inventory-valuation.csv',
                ['sku', 'name', 'qty', 'cost', 'value'],
                function ($out) use ($data): void {
                    $rows = $data['inventoryValuation']['rows'] ?? $data['inventoryValuation'] ?? [];
                    foreach ($rows as $row) {
                        if (! is_array($row)) {
                            continue;
                        }
                        fputcsv($out, [
                            $row['sku'] ?? '',
                            $row['name'] ?? '',
                            $row['quantity'] ?? '',
                            $row['unit_cost'] ?? '',
                            $row['value'] ?? '',
                        ]);
                    }
                }
            );
        }

        abort(404);
    }

    private function resolveDate(?string $value, Carbon $fallback): Carbon
    {
        if (! $value) {
            return $fallback;
        }

        try {
            return Carbon::parse($value);
        } catch (\Throwable) {
            return $fallback;
        }
    }
}
