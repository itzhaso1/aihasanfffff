<?php

namespace App\Console\Commands;

use App\Services\Worker\WorkerEngagementService;
use Illuminate\Console\Command;

final class ExpireWorkerEngagementsCommand extends Command
{
    protected $signature = 'worker:engagements:expire';

    protected $description = 'Complete active and paused worker engagements after their end time';

    public function handle(WorkerEngagementService $engagements): int
    {
        $completed = $engagements->completeExpiredEngagements();

        $this->info("Completed {$completed} worker engagements.");

        return self::SUCCESS;
    }
}
