<?php

namespace App\Http\Controllers\Api\Cashier\V1;

use App\Http\Controllers\Api\Cashier\CashierController;
use App\Http\Controllers\Api\Cashier\Concerns\AuthorizesCashier;
use App\Http\Controllers\Api\Cashier\Concerns\ResolvesCashierWorkspace;
use App\Http\Resources\Mobile\WorkspaceResource;
use App\Services\Feature\FeatureAccessService;
use App\Services\Mobile\MobileAuthService;
use App\Support\Tenancy\WorkspaceContext;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class WorkspaceController extends CashierController
{
    use AuthorizesCashier;
    use ResolvesCashierWorkspace;

    public function __construct(
        private readonly WorkspaceContext $workspaceContext,
        private readonly MobileAuthService $mobileAuthService,
        private readonly FeatureAccessService $featureAccessService,
    ) {}

    public function index(Request $request): JsonResponse
    {
        $workspaces = $request->user()
            ->workspaces()
            ->wherePivot('status', 'active')
            ->get()
            ->map(function ($workspace) {
                return [
                    'id' => $workspace->id,
                    'name' => $workspace->name,
                    'slug' => $workspace->slug,
                    'type' => $workspace->type,
                    'pos_enabled' => $this->featureAccessService->workspaceHasFeature($workspace, 'pos'),
                    'workspace' => new WorkspaceResource($workspace),
                ];
            })
            ->values();

        return $this->ok(['workspaces' => $workspaces]);
    }

    public function current(Request $request): JsonResponse
    {
        $workspace = $this->requireWorkspace($this->workspaceContext);
        $user = $request->user();

        return $this->ok([
            'workspace' => new WorkspaceResource($workspace),
            'pos_enabled' => $this->featureAccessService->workspaceHasFeature($workspace, 'pos'),
            'permissions' => $this->permissionMap($user, $workspace),
            'entitlements' => $this->featureAccessService->entitlementsSnapshot($workspace),
        ]);
    }

    public function switch(Request $request): JsonResponse
    {
        $user = $request->user();
        $token = $user?->currentAccessToken();
        if (! $user || ! $token) {
            return $this->fail('غير مصرح.', 401);
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
                    'device_name' => $validated['device_name'] ?? 'كاشير حاسم',
                    'device_type' => $validated['device_type'] ?? 'cashier',
                    'user_agent' => $request->userAgent(),
                    'ip_address' => $request->ip(),
                ],
            );
        } catch (ModelNotFoundException $exception) {
            return $this->fail($exception->getMessage(), 404);
        }

        return $this->ok([
            'workspace' => new WorkspaceResource($workspace),
            'pos_enabled' => $this->featureAccessService->workspaceHasFeature($workspace, 'pos'),
            'permissions' => $this->permissionMap($user, $workspace),
            'entitlements' => $this->featureAccessService->entitlementsSnapshot($workspace),
        ], message: 'تم تبديل مساحة العمل بنجاح.');
    }
}
