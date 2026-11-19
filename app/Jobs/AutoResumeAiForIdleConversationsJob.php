<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Services\Conversation\IdleHandoffResumeService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

/**
 * Scans all open conversations with an active human handoff and resumes AI
 * when the assigned employee has been idle for too long.
 *
 * Dispatched every minute by the scheduler (see routes/console.php).
 */
final class AutoResumeAiForIdleConversationsJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $timeout = 120;

    public int $tries = 1;

    public function handle(IdleHandoffResumeService $service): void
    {
        $resumed = $service->resumeIdleConversations();

        if ($resumed > 0) {
            Log::info("[AutoResumeAiJob] Auto-resumed AI on {$resumed} idle conversation(s).");
        }
    }
}
