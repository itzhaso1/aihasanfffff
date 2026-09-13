<?php

namespace App\Http\Controllers\Api\Mobile\V1;

use App\Http\Controllers\Api\Mobile\Concerns\ResolvesMobileWorkspace;
use App\Http\Controllers\Api\Mobile\MobileController;
use App\Http\Resources\Mobile\DevicePushTokenResource;
use App\Services\Mobile\PushDeviceService;
use App\Support\Tenancy\WorkspaceContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class DeviceController extends MobileController
{
    use ResolvesMobileWorkspace;

    public function __construct(
        private readonly PushDeviceService $pushDeviceService,
        private readonly WorkspaceContext $workspaceContext,
    ) {}

    public function store(Request $request): JsonResponse
    {
        $user = $request->user();
        if (! $user) {
            return $this->fail('غير مصرح.', 401);
        }

        $validated = $request->validate([
            'token' => ['required', 'string', 'max:512'],
            'provider' => ['required', Rule::in(['fcm', 'apns'])],
            'platform' => ['required', 'string', 'max:32'],
            'device_name' => ['nullable', 'string', 'max:255'],
        ]);

        try {
            $workspace = $this->requireWorkspace($this->workspaceContext);
            $device = $this->pushDeviceService->register(
                user: $user,
                token: $validated['token'],
                provider: $validated['provider'],
                platform: $validated['platform'],
                deviceName: $validated['device_name'] ?? null,
                workspaceId: $workspace?->id,
                personalAccessTokenId: $user->currentAccessToken()?->id,
            );
        } catch (\InvalidArgumentException $exception) {
            return $this->fail($exception->getMessage(), 422);
        }

        return $this->ok(new DevicePushTokenResource($device), message: 'تم تسجيل الجهاز بنجاح.', status: 201);
    }

    public function destroy(Request $request, int $device): JsonResponse
    {
        $user = $request->user();
        if (! $user) {
            return $this->fail('غير مصرح.', 401);
        }

        try {
            $workspace = $this->requireWorkspace($this->workspaceContext);
            $this->pushDeviceService->revoke($user, $device, (int) $workspace->id);
        } catch (\Illuminate\Database\Eloquent\ModelNotFoundException) {
            return $this->fail('رمز الإشعار غير موجود.', 404);
        }

        return $this->ok(message: 'تم إلغاء تسجيل الجهاز بنجاح.');
    }
}
