<?php

namespace App\Http\Controllers\Api\Mobile\V1;

use App\Http\Controllers\Api\Mobile\Concerns\ResolvesMobileWorkspace;
use App\Http\Controllers\Api\Mobile\MobileController;
use App\Services\Analytics\WorkspaceAnalyticsService;
use App\Support\Tenancy\WorkspaceContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;

class AnalyticsController extends MobileController
{
    use ResolvesMobileWorkspace;

    public function __construct(
        private readonly WorkspaceAnalyticsService $analyticsService,
        private readonly WorkspaceContext $workspaceContext,
    ) {}

    public function summary(Request $request): JsonResponse
    {
        $user = $request->user();
        if (! $user) {
            return $this->fail('غير مصرح.', 401);
        }

        $workspace = $this->requireWorkspace($this->workspaceContext);
        $range = (string) $request->input('range', 'month');
        [$from, $to] = $this->resolveRange($request, $range);

        $summary = $this->analyticsService->summary($workspace, $from, $to);

        return $this->ok($summary);
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
