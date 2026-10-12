<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\Mobile\V1\Company;

use App\Enums\WorkerEngagementDurationUnit;
use App\Enums\WorkerEngagementStatus;
use App\Http\Controllers\Api\Mobile\Concerns\ResolvesMobileWorkspace;
use App\Http\Controllers\Api\Mobile\MobileController;
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
use App\Support\Tenancy\WorkspaceContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;

class WorkerDiscoveryController extends MobileController
{
    use ResolvesMobileWorkspace;

    public function __construct(
        private readonly WorkerDiscoveryService $discovery,
        private readonly WorkerEngagementService $engagements,
        private readonly WorkerContractRateService $contractRates,
        private readonly WorkspaceContext $workspaceContext,
    ) {}

    public function index(WorkerDiscoveryRequest $request): JsonResponse
    {
        $workspace = $this->requireWorkspace($this->workspaceContext);
        $workers = $this->discovery->search(
            $request->user(),
            $workspace,
            $request->validated(),
        );

        return $this->ok(
            $workers->items(),
            [
                ...$this->pageMeta($workers),
                'skills' => WorkerSkill::query()
                    ->orderBy('sort_order')
                    ->orderBy('id')
                    ->get(['skill_key', 'display_name'])
                    ->map(fn (WorkerSkill $skill): array => [
                        'skill_key' => (string) $skill->skill_key,
                        'display_name' => (string) $skill->display_name,
                    ])
                    ->values()
                    ->all(),
            ],
        );
    }

    public function show(Request $request, WorkerProfile $workerProfile): JsonResponse
    {
        $workspace = $this->requireWorkspace($this->workspaceContext);

        return $this->ok(
            $this->discovery->profileForCompany(
                $request->user(),
                $workspace,
                $workerProfile->id,
            ),
        );
    }

    public function openEngagements(Request $request): JsonResponse
    {
        $workspace = $this->requireWorkspace($this->workspaceContext);
        $engagements = $this->discovery->openContracts($request->user(), $workspace);

        return $this->ok(
            $engagements
                ->map(function (WorkerEngagement $engagement): array {
                    $worker = $engagement->workerProfile;

                    return [
                        'id' => (int) $engagement->id,
                        'worker_profile_id' => (int) $engagement->worker_profile_id,
                        'status' => $engagement->status instanceof WorkerEngagementStatus
                            ? $engagement->status->value
                            : (string) $engagement->status,
                        'job_title' => $engagement->job_title,
                        'starts_at' => $engagement->starts_at?->toDateString(),
                        'ends_at' => $engagement->ends_at?->toDateString(),
                        'duration_value' => $engagement->duration_value,
                        'duration_unit' => $engagement->duration_unit?->value,
                        'worker' => [
                            'id' => (int) $worker->id,
                            'display_name' => (string) $worker->display_name,
                            'profile_photo' => filled($worker->profile_photo)
                                ? Storage::disk('local')->url($worker->profile_photo)
                                : null,
                        ],
                        'has_guide' => $engagement->guide !== null,
                        'granted_capabilities_count' => (int) $engagement->granted_capabilities_count,
                        'assigned_products_count' => (int) $engagement->assigned_products_count,
                    ];
                })
                ->values()
                ->all(),
            ['total' => $engagements->count()],
        );
    }

    public function contractPrice(
        Request $request,
        WorkerProfile $workerProfile,
    ): JsonResponse {
        $request->validate([
            'duration_value' => ['required', 'integer', 'min:1'],
            'duration_unit' => ['required', 'in:'.implode(',', WorkerEngagementDurationUnit::values())],
        ]);

        $workspace = $this->requireWorkspace($this->workspaceContext);
        $this->discovery->assertOfferAllowed(
            $request->user(),
            $workspace,
            $workerProfile->id,
        );

        try {
            $rate = $this->contractRates->resolveForOffer(
                $workerProfile,
                (int) $request->integer('duration_value'),
                (string) $request->string('duration_unit'),
            );
        } catch (WorkerEngagementException $exception) {
            return $this->fail($exception->getMessage(), 422);
        }

        return $this->ok([
            'duration_value' => $rate->duration_value,
            'duration_unit' => $rate->duration_unit,
            'amount_minor' => $rate->amount_minor,
            'currency' => $rate->currency,
        ]);
    }

    public function storeOffer(
        CreateWorkerEngagementRequest $request,
        WorkerProfile $workerProfile,
    ): JsonResponse {
        $workspace = $this->requireWorkspace($this->workspaceContext);

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
            return $this->fail($exception->getMessage(), 422);
        }

        return $this->ok(
            [
                'id' => $engagement->id,
                'worker_profile_id' => $engagement->worker_profile_id,
                'status' => $engagement->status instanceof WorkerEngagementStatus
                    ? $engagement->status->value
                    : (string) $engagement->status,
                'job_title' => $engagement->job_title,
                'job_description' => $engagement->job_description,
                'starts_at' => optional($engagement->starts_at)?->toDateString(),
                'ends_at' => optional($engagement->ends_at)?->toDateString(),
                'duration_value' => $engagement->duration_value,
                'duration_unit' => $engagement->duration_unit?->value,
                'offered_contract_amount_minor' => $engagement->offered_contract_amount_minor,
                'offered_contract_currency' => $engagement->offered_contract_currency,
            ],
            message: 'تم إرسال العرض.',
        );
    }
}
