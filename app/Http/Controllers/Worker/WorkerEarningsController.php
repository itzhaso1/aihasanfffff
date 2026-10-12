<?php

declare(strict_types=1);

namespace App\Http\Controllers\Worker;

use App\Http\Controllers\Controller;
use App\Http\Requests\Worker\DisputeWorkerEarningPeriodRequest;
use App\Http\Requests\Worker\WorkerOperationalReportRequest;
use App\Models\User;
use App\Services\Worker\WorkerEarningsException;
use App\Services\Worker\WorkerEarningsService;
use App\Services\Worker\WorkerOperationalReportingService;
use App\Services\Worker\WorkerPayoutService;
use Illuminate\Http\RedirectResponse;
use Illuminate\View\View;

class WorkerEarningsController extends Controller
{
    public function __construct(
        private readonly WorkerEarningsService $earnings,
        private readonly WorkerPayoutService $payouts,
        private readonly WorkerOperationalReportingService $reporting,
    ) {}

    public function show(WorkerOperationalReportRequest $request, int $engagement): View
    {
        $worker = $request->user();
        abort_unless($worker instanceof User, 403);

        $data = $this->reporting->workerEarningReport(
            $worker,
            $engagement,
            $request->validated(),
        );

        return view('worker.engagements.earnings', [
            'engagement' => $data['engagement'],
            'periods' => $data['periods'],
            'payouts' => $data['payouts'],
            'earningsSummary' => $data['earningsSummary'],
            'payoutSummary' => $data['payoutSummary'],
        ]);
    }

    public function dispute(
        DisputeWorkerEarningPeriodRequest $request,
        int $engagement,
        int $period,
    ): RedirectResponse {
        $worker = $request->user();
        abort_unless($worker instanceof User, 403);

        try {
            $this->earnings->dispute(
                $worker,
                $engagement,
                $period,
                $request->validated('reason'),
                $request,
            );
        } catch (WorkerEarningsException $exception) {
            return back()->withErrors(['earnings' => $exception->getMessage()]);
        }

        return redirect()
            ->route('worker.engagements.earnings.show', $engagement)
            ->with('success', 'تم تسجيل اعتراضك على فترة الأرباح.');
    }
}
