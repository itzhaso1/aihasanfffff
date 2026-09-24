<?php

namespace App\Services\Observability;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Throwable;

final class QueueMetricsService
{
    /**
     * @return array<string, array{queue: string, depth: int|null, status: string}>
     */
    public function snapshot(): array
    {
        $queues = config('observability.queues', []);
        $result = [];

        foreach ($queues as $label => $queueName) {
            $depth = $this->depth((string) $queueName);
            $result[$label] = [
                'queue' => (string) $queueName,
                'depth' => $depth,
                'status' => $depth === null ? 'unknown' : ($depth > 0 ? 'backlog' : 'idle'),
            ];
        }

        return $result;
    }

    public function failedJobsLastHour(): int
    {
        try {
            $table = (string) config('queue.failed.table', 'failed_jobs');

            return (int) DB::table($table)
                ->where('failed_at', '>=', now()->subHour())
                ->count();
        } catch (Throwable) {
            return 0;
        }
    }

    private function depth(string $queue): ?int
    {
        try {
            return (int) Queue::size($queue);
        } catch (Throwable) {
            return null;
        }
    }
}
