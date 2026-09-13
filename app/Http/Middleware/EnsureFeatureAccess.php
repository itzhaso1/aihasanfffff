<?php

namespace App\Http\Middleware;

use App\Exceptions\FeatureNotAvailableException;
use App\Services\Feature\FeatureAccessService;
use App\Support\Tenancy\WorkspaceContext;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class EnsureFeatureAccess
{
    public function __construct(
        private readonly WorkspaceContext $workspaceContext,
        private readonly FeatureAccessService $featureAccessService,
    ) {}

    public function handle(Request $request, Closure $next, string $feature): Response
    {
        $user = $request->user();
        $workspace = $this->workspaceContext->workspace();

        if (! $user || ! $workspace) {
            abort(Response::HTTP_FORBIDDEN, __('سياق مساحة العمل مطلوب.'));
        }

        if (! $this->featureAccessService->hasFeature($user, $workspace, $feature)) {
            throw new FeatureNotAvailableException(
                feature: $feature,
                requiredPlan: $this->featureAccessService->suggestedPlanForFeature($feature),
                message: __('هذه الميزة غير متاحة في باقتك الحالية. قم بالترقية للمتابعة.'),
            );
        }

        return $next($request);
    }
}
