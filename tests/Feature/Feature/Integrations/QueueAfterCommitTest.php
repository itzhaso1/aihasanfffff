<?php

namespace Tests\Feature\Feature\Integrations;

use App\Jobs\ProcessAIResponse;
use App\Jobs\ProcessIncomingWhatsAppMessage;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use RuntimeException;
use Tests\Support\Jobs\QueueAfterCommitProbeJob;
use Tests\TestCase;

class QueueAfterCommitTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Config::set('queue.default', 'database');
        Config::set('queue.connections.database.after_commit', true);
    }

    public function test_redis_connection_defaults_after_commit_to_false_in_testing_stack(): void
    {
        $this->assertFalse((bool) config('queue.connections.redis.after_commit'));
    }

    public function test_redis_connection_honors_explicit_production_env_override(): void
    {
        Config::set('queue.connections.redis.after_commit', filter_var(
            env('REDIS_QUEUE_AFTER_COMMIT', 'true'),
            FILTER_VALIDATE_BOOL
        ));

        $this->assertTrue((bool) config('queue.connections.redis.after_commit'));
    }

    public function test_job_dispatched_inside_transaction_is_not_pushed_until_commit(): void
    {
        DB::transaction(function (): void {
            QueueAfterCommitProbeJob::dispatch('pending-until-commit');

            $this->assertSame(0, DB::table('jobs')->count());
        });

        $this->assertSame(1, DB::table('jobs')->count());
    }

    public function test_job_dispatched_inside_transaction_is_not_pushed_on_rollback(): void
    {
        try {
            DB::transaction(function (): void {
                QueueAfterCommitProbeJob::dispatch('rolled-back');

                throw new RuntimeException('force rollback');
            });
        } catch (RuntimeException) {
            //
        }

        $this->assertSame(0, DB::table('jobs')->count());
    }

    public function test_job_dispatched_outside_transaction_is_pushed_immediately(): void
    {
        QueueAfterCommitProbeJob::dispatch('immediate');

        $this->assertSame(1, DB::table('jobs')->count());
    }

    public function test_after_commit_disabled_pushes_job_before_transaction_commits(): void
    {
        Config::set('queue.connections.database.after_commit', false);

        DB::transaction(function (): void {
            QueueAfterCommitProbeJob::dispatch('legacy-behavior');

            $this->assertSame(1, DB::table('jobs')->count());
        });

        $this->assertSame(1, DB::table('jobs')->count());
    }

    public function test_should_be_unique_dispatch_still_collapses_with_bus_fake(): void
    {
        Bus::fake();

        ProcessAIResponse::dispatch(1, 2, 3);
        ProcessAIResponse::dispatch(1, 2, 3);

        Bus::assertDispatched(ProcessAIResponse::class, 1);
    }

    public function test_existing_whatsapp_webhook_job_can_still_be_queued_after_commit_enabled(): void
    {
        Queue::fake();

        ProcessIncomingWhatsAppMessage::dispatch(
            workspaceId: 1,
            messageData: ['id' => 'wamid.after-commit'],
            webhookEventId: 10,
            phoneNumberId: 'pn-after-commit',
        );

        Queue::assertPushed(ProcessIncomingWhatsAppMessage::class, 1);
    }
}
