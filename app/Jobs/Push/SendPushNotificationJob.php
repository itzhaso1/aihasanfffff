<?php

namespace App\Jobs\Push;

use App\Models\User;
use App\Services\Mobile\FcmDeliveryStatus;
use App\Services\Mobile\FcmPushClient;
use App\Services\Mobile\PushDeviceService;
use App\Support\Tenancy\WorkspaceContext;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use RuntimeException;

class SendPushNotificationJob implements ShouldBeUnique, ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;

    public int $timeout = 60;

    public int $uniqueFor = 120;

    /** @var array<int, int> */
    public array $backoff = [30, 120, 300];

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

    public function uniqueId(): string
    {
        $messageId = (string) ($this->data['message_id'] ?? '');

        return 'push:'.(int) $this->workspaceId.':'.$this->userId.':'.$this->category.':'.$messageId;
    }

    public function handle(PushDeviceService $pushDeviceService): void
    {
        if ($this->workspaceId === null || $this->workspaceId <= 0) {
            return;
        }

        $fcmPushClient = app(FcmPushClient::class);

        app(WorkspaceContext::class)->runForId($this->workspaceId, function () use ($pushDeviceService, $fcmPushClient): void {
            $this->deliver($pushDeviceService, $fcmPushClient);
        });
    }

    private function deliver(PushDeviceService $pushDeviceService, FcmPushClient $fcmPushClient): void
    {
        $user = User::query()->find($this->userId);
        if (! $user) {
            return;
        }

        $isMember = $user->workspaces()
            ->where('workspaces.id', $this->workspaceId)
            ->wherePivot('status', 'active')
            ->exists();
        if (! $isMember) {
            return;
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

        if (! $fcmPushClient->isConfigured()) {
            Log::info('Push notification skipped: FCM provider not configured', [
                'user_id' => $this->userId,
                'category' => $this->category,
                'workspace_id' => $this->workspaceId,
                'device_count' => $tokens->count(),
            ]);

            return;
        }

        $retryable = false;
        $sent = 0;

        foreach ($tokens as $record) {
            if ($record->provider !== 'fcm') {
                continue;
            }

            try {
                $status = $fcmPushClient->send(
                    (string) $record->token,
                    $this->title,
                    $this->body,
                    $this->data,
                );
            } catch (RuntimeException $exception) {
                if ($exception->getCode() === 429 || $exception->getCode() === 503) {
                    $retryable = true;

                    continue;
                }

                throw $exception;
            }

            match ($status) {
                FcmDeliveryStatus::Sent => $sent++,
                FcmDeliveryStatus::InvalidToken => $pushDeviceService->markInvalid($record),
                FcmDeliveryStatus::PermanentFailure, FcmDeliveryStatus::RetryableFailure => null,
            };
        }

        if ($retryable && $sent === 0) {
            throw new RuntimeException('FCM delivery is retryable.', 429);
        }
    }
}
