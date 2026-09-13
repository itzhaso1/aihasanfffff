<?php

namespace App\Jobs;

use App\Models\EmailAccount;
use App\Services\Email\ImapSyncService;
use App\Support\Tenancy\WorkspaceContext;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;

class SyncEmailInboxJob implements ShouldQueue
{
    use Queueable;

    public int $tries = 2;
    public int $timeout = 180;

    public function __construct(
        public readonly int $emailAccountId,
        public readonly ?int $workspaceId = null,
    ) {}

    public function handle(ImapSyncService $imapSyncService): void
    {
        $query = EmailAccount::withoutGlobalScopes()->whereKey($this->emailAccountId);
        if ($this->workspaceId !== null) {
            $query->where('workspace_id', $this->workspaceId);
        }

        $emailAccount = $query->first();
        if (! $emailAccount) {
            return;
        }

        try {
            app(WorkspaceContext::class)->runForId((int) $emailAccount->workspace_id, function () use ($imapSyncService, $emailAccount): void {
                $imapSyncService->syncAccount($emailAccount, 40);
            });
        } catch (\Throwable $exception) {
            Log::error('email-sync-job-failed', [
                'email_account_id' => $this->emailAccountId,
                'workspace_id' => $emailAccount->workspace_id,
                'error' => $exception->getMessage(),
            ]);

            throw $exception;
        }
    }
}
