<?php

namespace App\Http\Controllers\Workspace;

use App\Http\Controllers\Controller;
use App\Http\Controllers\Workspace\Concerns\InteractsWithWorkspace;
use App\Services\Analytics\WorkspaceAnalyticsService;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\View\View;

class AnalyticsController extends Controller
{
    use InteractsWithWorkspace;

    public function __construct(
        private readonly WorkspaceAnalyticsService $analyticsService,
    ) {}

    public function index(Request $request): View
    {
        $workspace = $this->currentWorkspace();
        $range = (string) $request->input('range', 'month');
        [$from, $to] = $this->resolveRange($request, $range);

        $summary = $this->analyticsService->summary($workspace, $from, $to);

        return view('workspace.analytics.index', [
            'summary' => $summary,
            'range' => $range,
            'from' => $from->toDateString(),
            'to' => $to->toDateString(),
        ]);
    }

    /**
     * @return array{0: Carbon, 1: Carbon}
     */
    private function resolveRange(Request $request, string $range): array
    {
        $now = now();

        return match ($range) {
            'today' => [$now->copy()->startOfDay(), $now->copy()->endOfDay()],
            'week' => [$now->copy()->startOfWeek(), $now->copy()->endOfWeek()],
            'year' => [$now->copy()->startOfYear(), $now->copy()->endOfYear()],
            'custom' => [
                Carbon::parse((string) $request->input('from', $now->toDateString()))->startOfDay(),
                Carbon::parse((string) $request->input('to', $now->toDateString()))->endOfDay(),
            ],
            default => [$now->copy()->startOfMonth(), $now->copy()->endOfMonth()],
        };
    }
}
