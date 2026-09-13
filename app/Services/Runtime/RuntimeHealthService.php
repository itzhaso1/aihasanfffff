<?php

namespace App\Services\Runtime;

use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Support\Facades\Redis;
use Illuminate\Support\Facades\Schema;
use Throwable;

class RuntimeHealthService
{
    /**
     * @return array{
     *     ok: bool,
     *     checks: array<string, array{ok: bool, detail: string}>
     * }
     */
    public function inspect(bool $productionStrict = false, bool $requireRedis = false): array
    {
        $checks = [];

        $checks['queue_timeout_budget'] = $this->timeoutBudget();
        $checks['queue_not_failover'] = $this->notDriver('queue.default', 'failover', 'Queue connection');
        $checks['cache_not_failover'] = $this->notDriver('cache.default', 'failover', 'Cache store');
        $checks['failed_jobs_driver'] = $this->failedJobsDriver();
        $checks['failed_jobs_table'] = $this->failedJobsTable();
        $checks['scheduler'] = $this->scheduler();
        $checks['queue_config'] = $this->report('queue.default', (string) config('queue.default'));
        $checks['cache_config'] = $this->report('cache.default', (string) config('cache.default'));
        $checks['session_config'] = $this->report('session.driver', (string) config('session.driver'));

        if ($productionStrict) {
            $checks['production_queue'] = $this->mustEqual(
                (string) config('queue.default'),
                (string) config('runtime.production.queue_connection'),
                'Production queue connection'
            );
            $checks['production_cache'] = $this->mustEqual(
                (string) config('cache.default'),
                (string) config('runtime.production.cache_store'),
                'Production cache store'
            );
            $checks['production_session'] = $this->mustEqual(
                (string) config('session.driver'),
                (string) config('runtime.production.session_driver'),
                'Production session driver'
            );
        }

        if ($requireRedis) {
            $checks['redis'] = $this->redisPing();
        }

        $ok = collect($checks)->every(fn (array $check): bool => $check['ok']);

        return ['ok' => $ok, 'checks' => $checks];
    }

    /**
     * @return array{ok: bool, detail: string}
     */
    public function redisPing(): array
    {
        $password = (string) config('database.redis.default.password');
        $host = (string) config('database.redis.default.host', '127.0.0.1');
        $port = (string) config('database.redis.default.port', '6379');

        $client = (string) config('database.redis.client', 'phpredis');
        if ($client === 'phpredis' && ! extension_loaded('redis')) {
            return [
                'ok' => false,
                'detail' => 'phpredis extension is not loaded. Install ext-redis or set REDIS_CLIENT=predis.',
            ];
        }

        try {
            $pong = Redis::connection('default')->ping();
            $normalized = is_bool($pong) || is_int($pong) ? $pong : strtoupper(ltrim((string) $pong, '+'));
            $ok = $normalized === true || $normalized === 1 || $normalized === 'PONG';

            return [
                'ok' => $ok,
                'detail' => $ok
                    ? 'Redis reachable at '.$host.':'.$port.' (password omitted).'
                    : 'Redis ping returned an unexpected response.',
            ];
        } catch (Throwable $exception) {
            $message = $exception->getMessage();
            if ($password !== '' && $password !== 'null') {
                $message = str_replace($password, '[redacted]', $message);
            }

            return [
                'ok' => false,
                'detail' => 'Redis unavailable: '.$message,
            ];
        }
    }

    /**
     * @return array{ok: bool, detail: string}
     */
    private function timeoutBudget(): array
    {
        $maxJob = (int) config('runtime.queue.max_job_timeout');
        $worker = (int) config('runtime.queue.worker_timeout');
        $retryAfter = (int) config('queue.connections.redis.retry_after', config('runtime.queue.retry_after'));

        $ok = $maxJob > 0 && $worker >= $maxJob && $worker < $retryAfter;

        return [
            'ok' => $ok,
            'detail' => sprintf(
                'max_job_timeout=%d worker_timeout=%d retry_after=%d (require max_job <= worker < retry_after)',
                $maxJob,
                $worker,
                $retryAfter
            ),
        ];
    }

    /**
     * @return array{ok: bool, detail: string}
     */
    private function notDriver(string $key, string $forbidden, string $label): array
    {
        $value = (string) config($key);

        return [
            'ok' => $value !== $forbidden,
            'detail' => $label.'='.$value,
        ];
    }

    /**
     * @return array{ok: bool, detail: string}
     */
    private function failedJobsDriver(): array
    {
        $driver = (string) config('queue.failed.driver');
        $expected = (string) config('runtime.production.failed_driver', 'database-uuids');

        return [
            'ok' => $driver === $expected,
            'detail' => 'queue.failed.driver='.$driver,
        ];
    }

    /**
     * @return array{ok: bool, detail: string}
     */
    private function failedJobsTable(): array
    {
        $table = (string) config('queue.failed.table', 'failed_jobs');

        try {
            $exists = Schema::hasTable($table);

            return [
                'ok' => $exists,
                'detail' => $exists ? $table.' exists' : $table.' is missing',
            ];
        } catch (Throwable $exception) {
            return [
                'ok' => false,
                'detail' => 'Could not inspect failed_jobs: '.$exception->getMessage(),
            ];
        }
    }

    /**
     * @return array{ok: bool, detail: string}
     */
    private function scheduler(): array
    {
        $required = [
            'finance:invoices:refresh-payment-status',
            'finance:invoices:send-reminders',
            'finance:billing-schedules:generate',
            'subscriptions:expire-trials',
            'subscriptions:process-renewals',
            'subscriptions:process-dunning',
            'backup:database',
        ];

        $events = app(Schedule::class)->events();
        $haystack = collect($events)
            ->map(fn ($event): string => (string) ($event->command ?? $event->description ?? $event->mutexName()))
            ->implode(' | ');

        $missing = array_values(array_filter(
            $required,
            fn (string $command): bool => ! str_contains($haystack, $command)
        ));

        $overlap = collect($events)->every(fn ($event): bool => (bool) $event->withoutOverlapping);
        $oneServer = collect($events)->every(fn ($event): bool => (bool) $event->onOneServer);

        $ok = $missing === [] && $overlap && $oneServer;

        return [
            'ok' => $ok,
            'detail' => $ok
                ? count($events).' scheduled events; withoutOverlapping+onOneServer'
                : 'scheduler gap: missing='.implode(',', $missing).' overlap='.($overlap ? 'yes' : 'no').' onOneServer='.($oneServer ? 'yes' : 'no'),
        ];
    }

    /**
     * @return array{ok: bool, detail: string}
     */
    private function report(string $key, string $value): array
    {
        return ['ok' => $value !== '', 'detail' => $key.'='.$value];
    }

    /**
     * @return array{ok: bool, detail: string}
     */
    private function mustEqual(string $actual, string $expected, string $label): array
    {
        return [
            'ok' => $actual === $expected,
            'detail' => $label.'='.$actual.' (expected '.$expected.')',
        ];
    }
}
