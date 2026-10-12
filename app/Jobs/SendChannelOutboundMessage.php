<?php

namespace App\Jobs;

use App\Exceptions\ChannelOutboundRetryableException;
use App\Models\ChannelOutboundDelivery;
use App\Services\Channel\Outbound\ChannelOutboundService;
use App\Services\Observability\OpsMetricsRecorder;
use App\Support\Tenancy\WorkspaceContext;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;
use Throwable;

class SendChannelOutboundMessage implements ShouldBeUnique, ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    public int $timeout = 60;

    public int $uniqueFor = 600;

    /** @var array<int, int> */
    public array $backoff = [10, 45, 120];

    public function __construct(
        public readonly int $deliveryId,
        public readonly int $workspaceId,
    ) {
        $this->onQueue((string) config('inbox.queues.outbound', 'outbound'));
    }

    public function uniqueId(): string
    {
        return 'channel-outbound:'.$this->workspaceId.':'.$this->deliveryId;
    }

    public function handle(ChannelOutboundService $channelOutboundService): void
    {
        $started = microtime(true);

        app(WorkspaceContext::class)->runForId($this->workspaceId, function () use ($channelOutboundService, $started): void {
            $delivery = ChannelOutboundDelivery::withoutGlobalScopes()
                ->whereKey($this->deliveryId)
                ->where('workspace_id', $this->workspaceId)
                ->first();

            if (! $delivery || $delivery->status === ChannelOutboundDelivery::STATUS_SENT) {
                return;
            }

            try {
                $channelOutboundService->deliverQueuedDelivery($delivery);
            } catch (ChannelOutboundRetryableException $exception) {
                Log::warning('channel.outbound.retryable_failure', [
                    'workspace_id' => $this->workspaceId,
                    'delivery_id' => $this->deliveryId,
                    'message_id' => $delivery->message_id,
                    'provider' => $delivery->channel,
                    'attempt' => $delivery->fresh()?->attempts,
                    'duration_ms' => (int) round((microtime(true) - $started) * 1000),
                    'reason' => $exception->getMessage(),
                ]);

                throw $exception;
            }

            $durationMs = (int) round((microtime(true) - $started) * 1000);
            $status = $delivery->fresh()?->status;

            Log::info('channel.outbound.job_complete', [
                'workspace_id' => $this->workspaceId,
                'delivery_id' => $this->deliveryId,
                'message_id' => $delivery->message_id,
                'provider' => $delivery->channel,
                'status' => $status,
                'duration_ms' => $durationMs,
            ]);

            app(OpsMetricsRecorder::class)->timing('outbound.job_complete_ms', $durationMs, [
                'provider' => $delivery->channel,
            ]);
            app(OpsMetricsRecorder::class)->increment('outbound.job_complete', [
                'provider' => $delivery->channel,
                'status' => $status ?? 'unknown',
            ]);
        });
    }

    public function failed(?Throwable $exception): void
    {
        $delivery = ChannelOutboundDelivery::withoutGlobalScopes()
            ->whereKey($this->deliveryId)
            ->where('workspace_id', $this->workspaceId)
            ->first();

        if (! $delivery || $delivery->status === ChannelOutboundDelivery::STATUS_SENT) {
            return;
        }

        app(WorkspaceContext::class)->runForId($this->workspaceId, function () use ($delivery, $exception): void {
            $delivery->forceFill([
                'status' => ChannelOutboundDelivery::STATUS_FAILED,
                'last_error' => $exception?->getMessage() ?: $delivery->last_error,
                'failed_at' => $delivery->failed_at ?? now(),
            ])->save();
        });
    }
}
