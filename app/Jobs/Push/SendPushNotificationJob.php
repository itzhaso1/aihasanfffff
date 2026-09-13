<?php

namespace App\Jobs\Push;

use App\Models\User;
use App\Services\Mobile\PushDeviceService;
use App\Support\Tenancy\WorkspaceContext;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

class SendPushNotificationJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    /**
     * @param  array<string, mixed>  $data
     */
    public function __construct(
        public int $userId,
        public string $title,
        public string $body,
        public array $data = [],
        public string $category = 'messages',
        public ?int $workspaceId = null,
    ) {}

    public function handle(PushDeviceService $pushDeviceService): void
    {
        if ($this->workspaceId === null || $this->workspaceId <= 0) {
            return;
        }

        app(WorkspaceContext::class)->runForId($this->workspaceId, function () use ($pushDeviceService): void {
            $this->deliver($pushDeviceService);
        });
    }

    private function deliver(PushDeviceService $pushDeviceService): void
    {
        $user = User::query()->find($this->userId);
        if (! $user) {
            return;
        }

        if ($this->workspaceId !== null) {
            $isMember = $user->workspaces()
                ->where('workspaces.id', $this->workspaceId)
                ->wherePivot('status', 'active')
                ->exists();
            if (! $isMember) {
                return;
            }
        }

        if (! $pushDeviceService->shouldNotify($user, $this->workspaceId, $this->category)) {
            Log::info('Push notification skipped due to user preferences', [
                'user_id' => $this->userId,
                'category' => $this->category,
                'workspace_id' => $this->workspaceId,
            ]);

            return;
        }

        $tokens = $pushDeviceService->activeTokensForUser($user, $this->workspaceId);
        if ($tokens->isEmpty()) {
            return;
        }

        $fcmConfigured = (bool) config('services.fcm.enabled') || filled(config('services.fcm.server_key'));
        if (! $fcmConfigured) {
            Log::info('Push notification skipped: FCM provider not configured', [
                'user_id' => $this->userId,
                'category' => $this->category,
                'workspace_id' => $this->workspaceId,
                'device_count' => $tokens->count(),
            ]);

            return;
        }

        Log::info('Push notification dispatch requested', [
            'user_id' => $this->userId,
            'category' => $this->category,
            'workspace_id' => $this->workspaceId,
            'device_count' => $tokens->count(),
            'title' => $this->title,
        ]);
    }
}
