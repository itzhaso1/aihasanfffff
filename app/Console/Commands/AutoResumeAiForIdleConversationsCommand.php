<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Enums\ConversationHandoffStatus;
use App\Models\Conversation;
use App\Services\Conversation\IdleHandoffResumeService;
use Illuminate\Console\Command;

/**
 * Artisan command: conversations:auto-resume-ai
 *
 * Resumes AI on all open conversations where the assigned employee has been
 * idle for longer than the configured threshold (default: 5 minutes).
 *
 * Runs every minute via the scheduler.
 */
final class AutoResumeAiForIdleConversationsCommand extends Command
{
    protected $signature = 'conversations:auto-resume-ai
                            {--dry-run : Print eligible conversations without resuming AI}';

    protected $description = 'Resume AI on conversations where the employee has been idle too long';

    public function handle(IdleHandoffResumeService $service): int
    {
        if ($this->option('dry-run')) {
            $this->dryRun($service);

            return self::SUCCESS;
        }

        $resumed = $service->resumeIdleConversations();

        $this->info("Auto-resumed AI on {$resumed} conversation(s).");

        return self::SUCCESS;
    }

    private function dryRun(IdleHandoffResumeService $service): void
    {
        $threshold = now()->subMinutes($service->idleThresholdMinutes());

        $this->info("Dry-run: checking conversations idle since {$threshold->toDateTimeString()} (threshold: {$service->idleThresholdMinutes()} minutes)");

        $count = Conversation::withoutGlobalScopes()
            ->where('status', 'open')
            ->whereNull('deleted_at')
            ->whereIn('handoff_status', [
                ConversationHandoffStatus::Assigned->value,
                ConversationHandoffStatus::Active->value,
                ConversationHandoffStatus::Queued->value,
                ConversationHandoffStatus::Requested->value,
            ])
            ->where(function ($query) use ($threshold): void {
                $query->whereNull('employee_last_replied_at')
                    ->orWhere('employee_last_replied_at', '<=', $threshold);
            })
            ->count();

        $this->line("Found {$count} candidate conversation(s) (presence check not applied in dry-run).");
    }
}
