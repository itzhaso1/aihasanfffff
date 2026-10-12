<?php

declare(strict_types=1);

namespace App\Http\Controllers\Platform;

use App\Enums\WorkerPayoutStatus;
use App\Http\Controllers\Controller;
use App\Http\Requests\Platform\CreateWorkerPayoutRequest;
use App\Http\Requests\Platform\UpdateWorkerPayoutStatusRequest;
use App\Models\PlatformAdmin;
use App\Models\Worker\WorkerPayoutRecord;
use App\Services\Worker\WorkerPayoutException;
use App\Services\Worker\WorkerPayoutService;
use Illuminate\Http\RedirectResponse;
use Illuminate\View\View;

class WorkerPayoutController extends Controller
{
    public function __construct(
        private readonly WorkerPayoutService $payouts,
    ) {}

    public function index(): View
    {
        return view('platform.worker-payouts.index', [
            'payouts' => $this->payouts->forPlatform(),
            'approvedPeriods' => $this->payouts->approvedPeriodsForPlatform(),
        ]);
    }

    public function store(CreateWorkerPayoutRequest $request): RedirectResponse
    {
        $admin = $request->user('platform_admin');
        abort_unless($admin instanceof PlatformAdmin, 403);

        try {
            $this->payouts->create(
                $admin,
                (int) $request->validated('period_id'),
                $request,
            );
        } catch (WorkerPayoutException $exception) {
            return back()->withErrors(['payout' => $exception->getMessage()]);
        }

        return redirect()
            ->route('platform.worker-payouts.index')
            ->with('success', 'تم إنشاء سجل الصرف بحالة معلّقة.');
    }

    public function update(
        UpdateWorkerPayoutStatusRequest $request,
        WorkerPayoutRecord $payout,
    ): RedirectResponse {
        $admin = $request->user('platform_admin');
        abort_unless($admin instanceof PlatformAdmin, 403);

        try {
            $this->payouts->transition(
                $admin,
                $payout->id,
                WorkerPayoutStatus::from((string) $request->validated('status')),
                $request->validated('provider_reference'),
                $request->validated('reason'),
                $request,
            );
        } catch (WorkerPayoutException $exception) {
            return back()->withErrors(['payout' => $exception->getMessage()]);
        }

        return redirect()
            ->route('platform.worker-payouts.index')
            ->with('success', 'تم تحديث حالة سجل الصرف.');
    }
}
