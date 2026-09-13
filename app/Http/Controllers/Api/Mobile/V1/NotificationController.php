<?php

namespace App\Http\Controllers\Api\Mobile\V1;

use App\Http\Controllers\Api\Mobile\Concerns\ResolvesMobileWorkspace;
use App\Http\Controllers\Api\Mobile\MobileController;
use App\Http\Resources\Mobile\NotificationResource;
use App\Services\Mobile\PushDeviceService;
use App\Support\Tenancy\WorkspaceContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Schema;

class NotificationController extends MobileController
{
    use ResolvesMobileWorkspace;

    public function __construct(
        private readonly PushDeviceService $pushDeviceService,
        private readonly WorkspaceContext $workspaceContext,
    ) {}

    public function index(Request $request): JsonResponse
    {
        $user = $request->user();
        if (! $user) {
            return $this->fail('غير مصرح.', 401);
        }

        if (! Schema::hasTable('notifications')) {
            return $this->ok([]);
        }

        $workspace = $this->requireWorkspace($this->workspaceContext);
        $perPage = max(1, min(50, (int) $request->input('per_page', 20)));

        $paginator = $user->notifications()
            ->when(
                Schema::hasColumn('notifications', 'workspace_id'),
                fn ($query) => $query->where('workspace_id', $workspace->id),
            )
            ->latest('created_at')
            ->paginate($perPage);

        return $this->ok(
            NotificationResource::collection($paginator->items()),
            [
                'total' => $paginator->total(),
                'per_page' => $paginator->perPage(),
                'current_page' => $paginator->currentPage(),
            ],
        );
    }

    public function markRead(Request $request, string $notification): JsonResponse
    {
        $user = $request->user();
        if (! $user) {
            return $this->fail('غير مصرح.', 401);
        }

        $record = $user->notifications()->whereKey($notification)->first();
        if (! $record) {
            return $this->fail('الإشعار غير موجود.', 404);
        }

        $record->markAsRead();

        return $this->ok(new NotificationResource($record));
    }

    public function markAllRead(Request $request): JsonResponse
    {
        $user = $request->user();
        if (! $user) {
            return $this->fail('غير مصرح.', 401);
        }

        $workspace = $this->requireWorkspace($this->workspaceContext);

        $query = $user->unreadNotifications();
        if (Schema::hasColumn('notifications', 'workspace_id')) {
            $query->where('workspace_id', $workspace->id);
        }

        $count = $query->count();
        $query->update(['read_at' => now()]);

        return $this->ok(['marked_count' => $count], message: 'تم تعليم جميع الإشعارات كمقروءة.');
    }

    public function preferences(Request $request): JsonResponse
    {
        $user = $request->user();
        if (! $user) {
            return $this->fail('غير مصرح.', 401);
        }

        $workspace = $this->requireWorkspace($this->workspaceContext);
        $preferences = $this->pushDeviceService->preferences($user, (int) $workspace->id);

        return $this->ok([
            'messages' => (bool) $preferences->messages,
            'email' => (bool) $preferences->email,
            'marketing' => (bool) $preferences->marketing,
        ]);
    }

    public function updatePreferences(Request $request): JsonResponse
    {
        $user = $request->user();
        if (! $user) {
            return $this->fail('غير مصرح.', 401);
        }

        $validated = $request->validate([
            'messages' => ['nullable', 'boolean'],
            'email' => ['nullable', 'boolean'],
            'marketing' => ['nullable', 'boolean'],
        ]);

        $workspace = $this->requireWorkspace($this->workspaceContext);
        $preferences = $this->pushDeviceService->updatePreferences($user, $validated, (int) $workspace->id);

        return $this->ok([
            'messages' => (bool) $preferences->messages,
            'email' => (bool) $preferences->email,
            'marketing' => (bool) $preferences->marketing,
        ], message: 'تم تحديث تفضيلات الإشعارات.');
    }
}
