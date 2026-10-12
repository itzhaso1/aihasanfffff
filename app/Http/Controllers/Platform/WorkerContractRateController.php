<?php

declare(strict_types=1);

namespace App\Http\Controllers\Platform;

use App\Http\Controllers\Controller;
use App\Http\Requests\Platform\StoreWorkerContractRateRequest;
use App\Models\PlatformAdmin;
use App\Models\Worker\WorkerContractRate;
use App\Models\Worker\WorkerProfile;
use App\Services\Worker\WorkerContractRateService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

final class WorkerContractRateController extends Controller
{
    public function __construct(
        private readonly WorkerContractRateService $rates,
    ) {}

    public function index(Request $request): View
    {
        $admin = $request->user('platform_admin');
        abort_unless($admin instanceof PlatformAdmin, 403);

        return view('platform.worker-contract-rates.index', [
            'workers' => WorkerProfile::query()
                ->with('user')
                ->orderBy('id')
                ->get(),
            'rates' => WorkerContractRate::query()
                ->with('workerProfile.user')
                ->latest('id')
                ->get(),
        ]);
    }

    public function store(StoreWorkerContractRateRequest $request): RedirectResponse
    {
        $admin = $request->user('platform_admin');
        abort_unless($admin instanceof PlatformAdmin, 403);

        $worker = WorkerProfile::query()->findOrFail(
            (int) $request->validated('worker_profile_id'),
        );

        $this->rates->createVersion(
            admin: $admin,
            workerProfile: $worker,
            durationValue: (int) $request->validated('duration_value'),
            durationUnit: (string) $request->validated('duration_unit'),
            amount: (string) $request->validated('amount'),
            currency: (string) $request->validated('currency'),
            effectiveFrom: $request->date('effective_from'),
        );

        return back()->with('success', 'تم حفظ سعر التعاقد الجديد.');
    }

    public function disable(Request $request, WorkerContractRate $rate): RedirectResponse
    {
        $admin = $request->user('platform_admin');
        abort_unless($admin instanceof PlatformAdmin, 403);

        $this->rates->disable($admin, $rate);

        return back()->with('success', 'تم تعطيل سعر التعاقد.');
    }
}
