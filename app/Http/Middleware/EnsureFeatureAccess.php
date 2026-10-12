<?php

namespace App\Http\Middleware;

use App\Exceptions\FeatureNotAvailableException;
use App\Services\Feature\FeatureAccessService;
use App\Services\Worker\WorkerEngagementAuthorization;
use App\Support\Tenancy\WorkspaceContext;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class EnsureFeatureAccess
{
    public function __construct(
        private readonly WorkspaceContext $workspaceContext,
        private readonly FeatureAccessService $featureAccessService,
        private readonly WorkerEngagementAuthorization $workerAuthorization,
    ) {}

    public function handle(Request $request, Closure $next, string $feature): Response
    {
        $user = $request->user();
        $workspace = $this->workspaceContext->workspace();

        if (! $user || ! $workspace) {
            abort(Response::HTTP_FORBIDDEN, __('سياق مساحة العمل مطلوب.'));
        }

        $hasFeature = $this->featureAccessService->hasFeature($user, $workspace, $feature);
        $hasWorkerViewCapability = $this->featureAccessService->workspaceHasFeature($workspace, $feature)
            && $this->workerAuthorization->workerCanInWorkspace(
                $user,
                $workspace,
                $feature.'.view',
            );

        if (! $hasFeature && ! $hasWorkerViewCapability) {
            throw new FeatureNotAvailableException(
                feature: $feature,
                requiredPlan: $this->featureAccessService->suggestedPlanForFeature($feature),
                message: __('هذه الميزة غير متاحة في باقتك الحالية. قم بالترقية للمتابعة.'),
            );
        }

        return $next($request);
    }
}
