<?php

declare(strict_types=1);

namespace App\Http\Controllers\Workspace;

use App\Enums\WorkerEngagementDurationUnit;
use App\Http\Controllers\Controller;
use App\Http\Controllers\Workspace\Concerns\InteractsWithWorkspace;
use App\Http\Requests\Worker\CreateWorkerEngagementRequest;
use App\Http\Requests\Worker\WorkerDiscoveryRequest;
use App\Models\Worker\WorkerEngagement;
use App\Models\Worker\WorkerProfile;
use App\Models\Worker\WorkerSkill;
use App\Services\Worker\WorkerContractRateService;
use App\Services\Worker\WorkerDiscoveryException;
use App\Services\Worker\WorkerDiscoveryService;
use App\Services\Worker\WorkerEngagementException;
use App\Services\Worker\WorkerEngagementService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

class WorkerDiscoveryController extends Controller
{
    use InteractsWithWorkspace;

    public function __construct(
        private readonly WorkerDiscoveryService $discovery,
        private readonly WorkerEngagementService $engagements,
        private readonly WorkerContractRateService $contractRates,
    ) {}

    public function index(WorkerDiscoveryRequest $request): View
    {
        $workspace = $this->currentWorkspace();

        return view('workspace.worker-discovery.index', [
            'workers' => $this->discovery->search(
                $request->user(),
                $workspace,
                $request->validated(),
            ),
            'openEngagements' => $this->discovery->openContracts(
                $request->user(),
                $workspace,
            ),
            'filters' => $request->validated(),
            'skills' => WorkerSkill::query()->orderBy('sort_order')->orderBy('id')->get(['skill_key', 'display_name']),
        ]);
    }

    public function show(Request $request, WorkerProfile $workerProfile): View
    {
        $workspace = $this->currentWorkspace();
        $profile = $this->discovery->profileForCompany(
            $request->user(),
            $workspace,
            $workerProfile->id,
        );
        $canManageEngagements = $request->user()->can('create', WorkerEngagement::class);

        return view('workspace.worker-discovery.show', [
            'worker' => $profile,
            'canManageEngagements' => $canManageEngagements,
            'products' => $canManageEngagements
                ? $this->engagements->availableProducts($workspace)
                : collect(),
        ]);
    }

    public function storeOffer(
        CreateWorkerEngagementRequest $request,
        WorkerProfile $workerProfile,
    ): RedirectResponse {
        $workspace = $this->currentWorkspace();

        try {
            $this->discovery->assertOfferAllowed(
                $request->user(),
                $workspace,
                $workerProfile->id,
            );

            $engagement = $this->engagements->createOffer(
                $workspace,
                $request->user(),
                $request->validated(),
                $request,
            );
        } catch (WorkerDiscoveryException|WorkerEngagementException $exception) {
            return back()->withInput()->withErrors(['discovery' => $exception->getMessage()]);
        }

        return redirect()
            ->route('workspace.worker-engagements.show', $engagement)
            ->with('success', 'تم إرسال عرض الارتباط إلى العامل.');
    }

    public function contractPrice(
        Request $request,
        WorkerProfile $workerProfile,
    ): JsonResponse {
        $workspace = $this->currentWorkspace();
        $this->discovery->assertOfferAllowed(
            $request->user(),
            $workspace,
            $workerProfile->id,
        );

        $validated = $request->validate([
            'duration_value' => ['required', 'integer', 'min:1'],
            'duration_unit' => ['required', 'in:'.implode(',', WorkerEngagementDurationUnit::values())],
        ]);

        try {
            $rate = $this->contractRates->resolveForOffer(
                $workerProfile,
                (int) $validated['duration_value'],
                (string) $validated['duration_unit'],
            );
        } catch (WorkerEngagementException $exception) {
            return response()->json(['message' => $exception->getMessage()], 422);
        }

        return response()->json([
            'duration_value' => $rate->duration_value,
            'duration_unit' => $rate->duration_unit,
            'amount_minor' => $rate->amount_minor,
            'currency' => $rate->currency,
        ]);
    }
}
