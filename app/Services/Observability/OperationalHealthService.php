<?php

namespace App\Services\Observability;

use App\Services\Runtime\RuntimeHealthService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Throwable;

final class OperationalHealthService
{
    public function __construct(
        private readonly RuntimeHealthService $runtimeHealth,
        private readonly QueueMetricsService $queueMetrics,
    ) {}

    /**
     * @return array{
     *     status: string,
     *     checks: array<string, array{ok: bool, detail: string, severity?: string}>,
     *     queues: array<string, array{queue: string, depth: int|null, status: string}>,
     *     generated_at: string
     * }
     */
    public function snapshot(bool $productionStrict = false, bool $requireRedis = false): array
    {
        $runtime = $this->runtimeHealth->inspect($productionStrict, $requireRedis);
        $checks = $runtime['checks'];

        $checks['database'] = $this->databasePing();
        $checks['storage'] = $this->storageWritable();
        $checks['disk'] = $this->diskCapacity();
        $checks['failed_jobs_1h'] = $this->failedJobsCheck();

        $queues = $this->queueMetrics->snapshot();
        foreach ($queues as $label => $meta) {
            $depth = $meta['depth'];
            $threshold = (int) config('observability.alerts.queue_depth.'.$label, PHP_INT_MAX);
            $checks['queue_'.$label] = [
                'ok' => $depth === null || $depth <= $threshold,
                'detail' => $meta['queue'].' depth='.($depth ?? 'unknown').' threshold='.$threshold,
                'severity' => $depth !== null && $depth > $threshold ? 'degraded' : 'healthy',
            ];
        }

        $status = $this->aggregateStatus($checks);

        return [
            'status' => $status,
            'checks' => $checks,
            'queues' => $queues,
            'generated_at' => now()->toIso8601String(),
        ];
    }

    /**
     * @param  array<string, array{ok: bool, detail: string, severity?: string}>  $checks
     */
    private function aggregateStatus(array $checks): string
    {
        $allOk = collect($checks)->every(fn (array $c): bool => $c['ok']);
        if ($allOk) {
            return 'healthy';
        }

        $critical = ['database', 'redis', 'production_queue'];
        foreach ($critical as $key) {
            if (isset($checks[$key]) && ! $checks[$key]['ok']) {
                return 'unhealthy';
            }
        }

        return 'degraded';
    }

    /**
     * @return array{ok: bool, detail: string}
     */
    private function databasePing(): array
    {
        try {
            DB::select('SELECT 1');

            return ['ok' => true, 'detail' => 'database reachable'];
        } catch (Throwable $exception) {
            return ['ok' => false, 'detail' => 'database unavailable'];
        }
    }

    /**
     * @return array{ok: bool, detail: string}
     */
    private function storageWritable(): array
    {
        $diskName = (string) config('media.disk', config('filesystems.default', 'local'));

        try {
            $disk = Storage::disk($diskName);
            $probe = '.ops-health-'.uniqid('', true);
            $disk->put($probe, '1');
            $disk->delete($probe);

            return ['ok' => true, 'detail' => 'storage disk '.$diskName.' writable'];
        } catch (Throwable $exception) {
            return ['ok' => false, 'detail' => 'storage disk '.$diskName.' not writable'];
        }
    }

    /**
     * @return array{ok: bool, detail: string}
     */
    private function diskCapacity(): array
    {
        $path = storage_path();
        $free = @disk_free_space($path);
        $total = @disk_total_space($path);

        if ($free === false || $total === false || $total <= 0) {
            return ['ok' => true, 'detail' => 'disk metrics unavailable'];
        }

        $freePercent = ($free / $total) * 100;
        $min = (float) config('observability.alerts.disk_free_percent_min', 10.0);

        return [
            'ok' => $freePercent >= $min,
            'detail' => sprintf('storage free=%.1f%% (min %.1f%%)', $freePercent, $min),
        ];
    }

    /**
     * @return array{ok: bool, detail: string}
     */
    private function failedJobsCheck(): array
    {
        $count = $this->queueMetrics->failedJobsLastHour();
        $threshold = (int) config('observability.alerts.failed_jobs_recent', 25);

        return [
            'ok' => $count <= $threshold,
            'detail' => 'failed_jobs_1h='.$count.' threshold='.$threshold,
        ];
    }
}
