<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class OpsPruneWebhookEventsCommand extends Command
{
    protected $signature = 'ops:retention:webhook-events {--days=}';

    protected $description = 'Prune old processed webhook_events (never deletes pending rows)';

    public function handle(): int
    {
        $days = (int) ($this->option('days') ?: config('observability.retention.webhook_events_processed_days', 30));
        if ($days < 7) {
            $this->error('Retention must be at least 7 days.');

            return self::FAILURE;
        }

        $cutoff = now()->subDays($days);

        $deleted = DB::table('webhook_events')
            ->where('status', 'processed')
            ->where('processed_at', '<', $cutoff)
            ->limit(5000)
            ->delete();

        $this->info("Pruned {$deleted} processed webhook_events older than {$days} days.");

        return self::SUCCESS;
    }
}
