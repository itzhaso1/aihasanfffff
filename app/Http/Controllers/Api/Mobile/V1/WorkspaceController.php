<?php

namespace App\Http\Controllers\Api\Mobile\V1;

use App\Http\Controllers\Api\Mobile\Concerns\ResolvesMobileWorkspace;
use App\Http\Controllers\Api\Mobile\MobileController;
use App\Http\Resources\Mobile\WorkspaceResource;
use App\Services\Mobile\MobileAuthService;
use App\Support\Tenancy\WorkspaceContext;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class WorkspaceController extends MobileController
{
    use ResolvesMobileWorkspace;

    public function __construct(
        private readonly MobileAuthService $mobileAuthService,
        private readonly WorkspaceContext $workspaceContext,
    ) {}

    public function index(Request $request): JsonResponse
    {
        $user = $request->user();
        if (! $user) {
            return $this->fail('غير مصرح.', 401);
        }

        $workspaces = $user->workspaces()
            ->wherePivot('status', 'active')
            ->get();

        return $this->ok(WorkspaceResource::collection($workspaces));
    }

    public function current(Request $request): JsonResponse
    {
        $user = $request->user();
        if (! $user) {
            return $this->fail('غير مصرح.', 401);
        }

        $workspace = $this->requireWorkspace($this->workspaceContext);

        return $this->ok(new WorkspaceResource($workspace));
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
                    'device_name' => $validated['device_name'] ?? null,
                    'device_type' => $validated['device_type'] ?? null,
                    'user_agent' => $request->userAgent(),
                    'ip_address' => $request->ip(),
                ],
            );
        } catch (ModelNotFoundException $exception) {
            return $this->fail($exception->getMessage(), 404);
        }

        return $this->ok(new WorkspaceResource($workspace), message: 'تم تبديل مساحة العمل بنجاح.');
    }
}
