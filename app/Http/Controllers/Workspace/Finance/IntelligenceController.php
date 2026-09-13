<?php

namespace App\Http\Controllers\Workspace\Finance;

use App\Services\Finance\BusinessAlertService;
use App\Services\Finance\FinanceCopilotService;
use App\Services\Finance\FinanceSearchService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

class IntelligenceController extends FinanceBaseController
{
    public function alerts(Request $request, BusinessAlertService $alerts): View
    {
        $this->authorizeFinance($request, 'finance.view');

        return view('workspace.finance.alerts.index', [
            'alerts' => $alerts->alerts((int) $this->currentWorkspace()->id),
        ]);
    }

    public function copilot(Request $request): View
    {
        $this->authorizeFinance($request, 'finance.view');

        return view('workspace.finance.copilot.index', [
            'result' => null,
            'question' => '',
        ]);
    }

    public function ask(Request $request, FinanceCopilotService $copilot): View
    {
        $this->authorizeFinance($request, 'finance.view');
        $validated = $request->validate([
            'question' => ['required', 'string', 'max:500'],
        ]);

        return view('workspace.finance.copilot.index', [
            'question' => $validated['question'],
            'result' => $copilot->ask((int) $this->currentWorkspace()->id, $validated['question']),
        ]);
    }

    public function search(Request $request, FinanceSearchService $search): View|JsonResponse
    {
        $this->authorizeFinance($request, 'finance.view');
        $term = trim((string) $request->input('q', ''));
        $results = $term === '' ? [] : $search->search((int) $this->currentWorkspace()->id, $term);

        if ($request->wantsJson()) {
            return response()->json(['data' => $results]);
        }

        return view('workspace.finance.search.index', [
            'term' => $term,
            'results' => $results,
        ]);
    }
}
