<?php

namespace App\Http\Controllers\Api\Mobile\V1;

use App\Http\Controllers\Api\Mobile\MobileController;
use App\Http\Resources\Mobile\DeviceSessionResource;
use App\Services\Mobile\MobileAuthService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class DeviceSessionController extends MobileController
{
    public function __construct(
        private readonly MobileAuthService $mobileAuthService,
    ) {}

    public function index(Request $request): JsonResponse
    {
        $user = $request->user();
        if (! $user) {
            return $this->fail('غير مصرح.', 401);
        }

        $sessions = $user->tokens()->orderByDesc('last_used_at')->orderByDesc('id')->get();

        return $this->ok(DeviceSessionResource::collection($sessions));
    }

    public function destroy(Request $request, int $tokenId): JsonResponse
    {
        $user = $request->user();
        if (! $user) {
            return $this->fail('غير مصرح.', 401);
        }

        $currentId = (int) ($user->currentAccessToken()?->id ?? 0);
        if ($currentId === $tokenId) {
            return $this->fail('لا يمكن إلغاء الجلسة الحالية من هنا. استخدم تسجيل الخروج.', 422);
        }

        try {
            $this->mobileAuthService->revokeSession($user, $tokenId);
        } catch (\Illuminate\Database\Eloquent\ModelNotFoundException) {
            return $this->fail('الجلسة غير موجودة.', 404);
        }

        return $this->ok(message: 'تم إنهاء الجلسة.');
    }

    public function destroyAll(Request $request): JsonResponse
    {
        $user = $request->user();
        if (! $user) {
            return $this->fail('غير مصرح.', 401);
        }

        $exceptId = (int) ($user->currentAccessToken()?->id ?? 0);
        $count = $this->mobileAuthService->revokeAllSessions($user, $exceptId ?: null);

        return $this->ok(['revoked_count' => $count], message: 'تم إلغاء الجلسات الأخرى بنجاح.');
    }
}
