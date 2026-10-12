<?php

namespace Tests\Feature\Feature\Runtime;

use App\Jobs\Runtime\RuntimeFailingJob;
use App\Jobs\Runtime\RuntimeProbeJob;
use App\Services\Runtime\RuntimeHealthService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Redis;
use Illuminate\Support\Str;
use Tests\TestCase;
use Throwable;

class RedisRuntimeTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        if (! $this->redisReachable()) {
            $this->markTestSkipped('REDIS VERIFICATION = BLOCKED: no isolated Redis on 127.0.0.1:6379.');
        }

        $this->isolateRedis();
    }

    public function test_redis_connection_works(): void
    {
        $pong = Redis::connection('default')->ping();
        $normalized = is_bool($pong) || is_int($pong) ? $pong : strtoupper(ltrim((string) $pong, '+'));

        $this->assertTrue($normalized === true || $normalized === 1 || $normalized === 'PONG');
    }

    public function test_cache_put_get_delete_uses_redis(): void
    {
        config(['cache.default' => 'redis']);
        Cache::forget('p04-cache');
        $this->assertTrue(Cache::put('p04-cache', 'ok', 60));
        $this->assertSame('ok', Cache::get('p04-cache'));
        $this->assertTrue(Cache::forget('p04-cache'));
        $this->assertNull(Cache::get('p04-cache'));
    }

    public function test_session_store_uses_redis(): void
    {
        config(['session.driver' => 'redis', 'session.connection' => 'session']);
        $store = app('session')->driver('redis');
        $store->put('p04-session', 'alive');
        $this->assertSame('alive', $store->get('p04-session'));
    }

    public function test_real_queue_worker_processes_probe_once(): void
    {
        config([
            'queue.default' => 'redis',
            'cache.default' => 'redis',
        ]);

        $token = Str::lower(Str::random(12));
        $job = new RuntimeProbeJob($token);
        dispatch($job)->onConnection('redis');

        $this->assertNull(Cache::get($job->cacheKey()));

        $code = Artisan::call('queue:work', [
            'connection' => 'redis',
            '--once' => true,
            '--tries' => 1,
            '--timeout' => 15,
        ]);

        $this->assertSame(0, $code);
        $this->assertSame('processed', Cache::get($job->cacheKey()));
        Cache::forget($job->cacheKey());
    }

    public function test_failing_job_on_redis_is_tracked_and_retry_succeeds(): void
    {
        config([
            'queue.default' => 'redis',
            'cache.default' => 'redis',
        ]);

        $token = Str::lower(Str::random(12));
        $job = new RuntimeFailingJob($token);
        dispatch($job)->onConnection('redis');

        Artisan::call('queue:work', [
            'connection' => 'redis',
            '--once' => true,
            '--tries' => 1,
            '--timeout' => 15,
        ]);
        $this->assertSame(1, DB::table('failed_jobs')->count());
        $this->assertNull(Cache::get($job->cacheKey()));

        $failedUuid = (string) DB::table('failed_jobs')->value('uuid');
        $this->assertSame(0, Artisan::call('queue:retry', ['id' => [$failedUuid]]));
        $this->assertSame(0, DB::table('failed_jobs')->count());

        $this->assertSame(0, Artisan::call('queue:work', [
            'connection' => 'redis',
            '--once' => true,
            '--tries' => 1,
            '--timeout' => 15,
        ]));
        $this->assertSame('recovered', Cache::get($job->cacheKey()));
        $this->assertSame(0, DB::table('failed_jobs')->count());

        Cache::forget($job->cacheKey());
        @unlink(storage_path('framework/runtime-fail-'.$token));
    }

    public function test_runtime_check_requires_redis_and_succeeds(): void
    {
        config([
            'queue.default' => 'redis',
            'cache.default' => 'redis',
            'session.driver' => 'redis',
        ]);

        $code = Artisan::call('runtime:check', [
            '--production-strict' => true,
            '--require-redis' => true,
        ]);

        $this->assertSame(0, $code, Artisan::output());
    }

    private function isolateRedis(): void
    {
        $db = (int) config('runtime.redis.test_database', 14);
        $prefix = (string) config('runtime.redis.test_prefix', 'hasem-p04-');
        config([
            'database.redis.options.prefix' => $prefix,
            'database.redis.default.database' => $db,
            'database.redis.cache.database' => $db,
            'database.redis.session.database' => $db,
        ]);
        app()->forgetInstance('redis');
    }

    private function redisReachable(): bool
    {
        try {
            $result = app(RuntimeHealthService::class)->redisPing();

            return $result['ok'];
        } catch (Throwable) {
            return false;
        }
    }
}
