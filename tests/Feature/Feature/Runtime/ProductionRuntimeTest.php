<?php

namespace Tests\Feature\Feature\Runtime;

use App\Jobs\Runtime\RuntimeFailingJob;
use App\Jobs\Runtime\RuntimeProbeJob;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

class ProductionRuntimeTest extends TestCase
{
    use RefreshDatabase;

    public function test_timeout_is_less_than_retry_after_and_covers_longest_job(): void
    {
        $maxJob = (int) config('runtime.queue.max_job_timeout');
        $worker = (int) config('runtime.queue.worker_timeout');
        $retryAfter = (int) config('queue.connections.redis.retry_after');

        $this->assertSame(180, $maxJob);
        $this->assertGreaterThanOrEqual($maxJob, $worker);
        $this->assertLessThan($retryAfter, $worker);
    }

    public function test_runtime_documents_supervisor_worker_profiles_for_p0(): void
    {
        $workers = config('runtime.workers');

        $this->assertIsArray($workers);
        $this->assertSame('emails', $workers['emails']['queue']);
        $this->assertSame('inbound', $workers['inbound']['queue']);
        $this->assertSame('realtime', $workers['realtime']['queue']);
        $this->assertSame('ai', $workers['ai']['queue']);
    }

    public function test_redis_queue_supports_after_commit_for_production_safety(): void
    {
        $this->assertArrayHasKey('after_commit', config('queue.connections.redis'));
    }

    public function test_production_like_config_uses_redis_not_database_or_failover(): void
    {
        config([
            'queue.default' => 'redis',
            'cache.default' => 'redis',
            'session.driver' => 'redis',
        ]);

        $this->assertSame('redis', config('runtime.production.queue_connection'));
        $this->assertSame('redis', config('queue.default'));
        $this->assertSame('redis', config('cache.default'));
        $this->assertSame('redis', config('session.driver'));
        $this->assertNotSame('database', config('queue.default'));
        $this->assertNotSame('failover', config('queue.default'));
        $this->assertNotSame('failover', config('cache.default'));
        $this->assertSame('database-uuids', config('queue.failed.driver'));
        $this->assertSame('failed_jobs', config('queue.failed.table'));
    }

    public function test_phpunit_defaults_stay_off_redis(): void
    {
        $this->assertSame('sync', config('queue.default'));
        $this->assertSame('array', config('cache.default'));
        $this->assertSame('array', config('session.driver'));
    }

    public function test_scheduler_registers_critical_commands_with_overlap_protection(): void
    {
        $events = collect(app(Schedule::class)->events());
        $required = [
            'finance:invoices:refresh-payment-status',
            'finance:invoices:send-reminders',
            'finance:billing-schedules:generate',
            'subscriptions:expire-trials',
            'subscriptions:process-renewals',
            'subscriptions:process-dunning',
            'backup:database',
        ];

        foreach ($required as $command) {
            $this->assertTrue(
                $events->contains(fn ($event): bool => str_contains((string) ($event->command ?? ''), $command)),
                $command
            );
        }

        $this->assertTrue($events->contains(
            fn ($event): bool => str_contains((string) ($event->description ?? $event->mutexName()), 'ExpireStoriesJob')
        ));

        foreach ($events as $event) {
            $this->assertTrue($event->withoutOverlapping, (string) ($event->command ?? $event->mutexName()));
            $this->assertTrue($event->onOneServer, (string) ($event->command ?? $event->mutexName()));
        }
    }

    public function test_runtime_check_succeeds_in_testing_without_redis(): void
    {
        $code = Artisan::call('runtime:check');

        $this->assertSame(0, $code);
        $this->assertStringNotContainsString((string) config('app.key'), Artisan::output());
    }

    public function test_runtime_check_fails_when_production_still_uses_database_queue(): void
    {
        config([
            'queue.default' => 'database',
            'cache.default' => 'database',
            'session.driver' => 'database',
        ]);

        $code = Artisan::call('runtime:check', ['--production-strict' => true]);

        $this->assertSame(1, $code);
        $this->assertStringContainsString('FAIL', Artisan::output());
    }

    public function test_runtime_check_fails_when_redis_is_unreachable(): void
    {
        config([
            'database.redis.client' => 'predis',
            'database.redis.default.host' => '127.0.0.1',
            'database.redis.default.port' => 1,
            'database.redis.default.password' => 'wrong-redis-secret-p04',
        ]);
        app()->forgetInstance('redis');

        $code = Artisan::call('runtime:check', ['--require-redis' => true]);
        $output = Artisan::output();

        $this->assertSame(1, $code);
        $this->assertStringNotContainsString('wrong-redis-secret-p04', $output);
        $this->assertStringContainsString('FAIL', $output);
    }

    public function test_probe_job_executes_exactly_once_on_sync_queue(): void
    {
        $token = Str::lower(Str::random(12));
        $job = new RuntimeProbeJob($token);

        dispatch_sync($job);

        $this->assertSame('processed', Cache::get($job->cacheKey()));
    }

    public function test_failing_job_is_tracked_and_retry_succeeds(): void
    {
        config(['queue.default' => 'database']);

        $token = Str::lower(Str::random(12));
        $job = new RuntimeFailingJob($token);
        dispatch($job);

        Artisan::call('queue:work', [
            'connection' => 'database',
            '--once' => true,
            '--tries' => 1,
        ]);
        $this->assertSame(1, DB::table('failed_jobs')->count());

        $failedUuid = (string) DB::table('failed_jobs')->value('uuid');
        $this->assertSame(0, Artisan::call('queue:retry', ['id' => [$failedUuid]]));
        $this->assertSame(0, DB::table('failed_jobs')->count());

        $this->assertSame(0, Artisan::call('queue:work', [
            'connection' => 'database',
            '--once' => true,
            '--tries' => 1,
        ]));
        $this->assertSame('recovered', Cache::get($job->cacheKey()));
        $this->assertSame(0, DB::table('failed_jobs')->count());

        @unlink(storage_path('framework/runtime-fail-'.$token));
    }
}
