<?php

namespace App\Services\Observability;

use App\Support\Observability\CorrelationContext;
use App\Support\Observability\OpsLogger;
use Illuminate\Support\Facades\Redis;
use Throwable;

final class OpsMetricsRecorder
{
    /**
     * @param  array<string, scalar|null>  $tags
     */
    public function increment(string $name, array $tags = [], int $by = 1): void
    {
        $by = max(1, $by);
        $this->storeCounter($name, $by, $tags);

        OpsLogger::info('ops.metric', array_merge([
            'metric' => $name,
            'value' => $by,
        ], $tags, CorrelationContext::logContext()));
    }

    /**
     * @param  array<string, scalar|null>  $tags
     */
    public function timing(string $name, int $durationMs, array $tags = []): void
    {
        $this->storeTiming($name, $durationMs, $tags);

        OpsLogger::info('ops.timing', array_merge([
            'metric' => $name,
            'duration_ms' => $durationMs,
        ], $tags, CorrelationContext::logContext()));
    }

    /**
     * @return array<string, int>
     */
    public function countersForLastHour(string $prefix): array
    {
        try {
            $connection = Redis::connection('default');
            $pattern = 'ops:cnt:'.date('YmdH').':'.$prefix.'*';
            $keys = $connection->keys($pattern);
            if (! is_array($keys)) {
                return [];
            }

            $result = [];
            foreach ($keys as $key) {
                $value = (int) $connection->get($key);
                $short = str_replace('ops:cnt:'.date('YmdH').':', '', (string) $key);
                $result[$short] = $value;
            }

            return $result;
        } catch (Throwable) {
            return [];
        }
    }

    /**
     * @param  array<string, scalar|null>  $tags
     */
    private function storeCounter(string $name, int $by, array $tags): void
    {
        try {
            $tagSuffix = $this->tagSuffix($tags);
            $key = 'ops:cnt:'.date('YmdH').':'.$name.$tagSuffix;
            $connection = Redis::connection('default');
            $connection->incrby($key, $by);
            $connection->expire($key, 7200);
        } catch (Throwable) {
            // metrics must never break request/job path
        }
    }

    /**
     * @param  array<string, scalar|null>  $tags
     */
    private function storeTiming(string $name, int $durationMs, array $tags): void
    {
        try {
            $tagSuffix = $this->tagSuffix($tags);
            $key = 'ops:timing:'.date('YmdHi').':'.$name.$tagSuffix;
            $connection = Redis::connection('default');
            $connection->rpush($key, (string) $durationMs);
            $connection->expire($key, 7200);
        } catch (Throwable) {
            //
        }
    }

    /**
     * @param  array<string, scalar|null>  $tags
     */
    private function tagSuffix(array $tags): string
    {
        if ($tags === []) {
            return '';
        }

        ksort($tags);
        $parts = [];
        foreach ($tags as $key => $value) {
            if ($value === null) {
                continue;
            }
            $parts[] = $key.'='.(string) $value;
        }

        return $parts === [] ? '' : ':'.implode(',', $parts);
    }
}
