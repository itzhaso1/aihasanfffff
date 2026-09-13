<?php

namespace App\Http\Controllers\Api\Finance\V1;

use App\Exceptions\Api\ApiErrorCode;
use App\Http\Controllers\Api\Finance\Concerns\HandlesFinanceClient;
use App\Http\Controllers\Api\Finance\FinanceApiController;
use App\Services\Feature\FeatureAccessService;
use App\Services\Finance\Api\FinanceClientPresenter;
use App\Services\Mobile\MobileAuthService;
use App\Support\Tenancy\WorkspaceContext;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class WorkspaceController extends FinanceApiController
{
    use HandlesFinanceClient;

    public function __construct(
        private readonly WorkspaceContext $workspaceContext,
        private readonly MobileAuthService $mobileAuthService,
        private readonly FeatureAccessService $featureAccessService,
        private readonly FinanceClientPresenter $presenter,
    ) {}

    public function index(Request $request): JsonResponse
    {
        $workspaces = $request->user()
            ->workspaces()
            ->wherePivot('status', 'active')
            ->get();

        return $this->ok([
            'workspaces' => $this->presenter->workspaces(
                $workspaces,
                fn ($workspace) => $this->featureAccessService->workspaceHasFeature($workspace, 'finance'),
            ),
        ]);
    }

    public function current(Request $request): JsonResponse
    {
        $workspace = $this->clientWorkspace($this->workspaceContext);
        $user = $request->user();

        return $this->ok([
            'workspace' => [
                'id' => $workspace->id,
                'name' => $workspace->name,
                'slug' => $workspace->slug ?? null,
                'type' => $workspace->type,
                'finance_enabled' => $this->featureAccessService->workspaceHasFeature($workspace, 'finance'),
            ],
            'permissions' => $this->financePermissionMap($user, $workspace),
            'finance_enabled' => $this->featureAccessService->workspaceHasFeature($workspace, 'finance'),
        ]);
    }

    public function switch(Request $request): JsonResponse
    {
        $user = $request->user();
        $token = $user?->currentAccessToken();
        if (! $user || ! $token) {
            return $this->fail('غير مصرح.', ApiErrorCode::Unauthorized, 401);
        }

        $validated = $request->validate([
            'workspace_id' => ['required', 'integer'],
            'device_name' => ['nullable', 'string', 'max:255'],
            'device_type' => ['nullable', 'string', 'max:32'],
        ]);

        try {
            $workspace = $this->mobileAuthService->switchWorkspace(
                user: $user,
                token: $token,
                workspaceId: (int) $validated['workspace_id'],
                device: [
                    'device_name' => $validated['device_name'] ?? 'حاسم للمالية',
                    'device_type' => $validated['device_type'] ?? 'finance',
                    'user_agent' => $request->userAgent(),
                    'ip_address' => $request->ip(),
                ],
            );
        } catch (ModelNotFoundException $exception) {
            return $this->fail($exception->getMessage(), ApiErrorCode::NotFound, 404);
        }

        $this->workspaceContext->set($workspace);

        return $this->ok([
            'workspace' => [
                'id' => $workspace->id,
                'name' => $workspace->name,
                'slug' => $workspace->slug ?? null,
                'type' => $workspace->type,
                'finance_enabled' => $this->featureAccessService->workspaceHasFeature($workspace, 'finance'),
            ],
            'permissions' => $this->financePermissionMap($user, $workspace),
            'finance_enabled' => $this->featureAccessService->workspaceHasFeature($workspace, 'finance'),
        ], message: 'تم تبديل مساحة العمل بنجاح.');
    }
}
