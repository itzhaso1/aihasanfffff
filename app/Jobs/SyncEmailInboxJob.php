<?php

namespace App\Jobs;

use App\Exceptions\CustomerMailboxException;
use App\Models\EmailAccount;
use App\Services\Email\CustomerMailboxErrorClassifier;
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

    public function handle(ImapSyncService $imapSyncService, ?CustomerMailboxErrorClassifier $errorClassifier = null): void
    {
        $errorClassifier ??= app(CustomerMailboxErrorClassifier::class);
        if ($this->workspaceId === null) {
            Log::warning('email-sync-job-missing-workspace', [
                'email_account_id' => $this->emailAccountId,
            ]);

            return;
        }

        $emailAccount = EmailAccount::withoutGlobalScopes()
            ->whereKey($this->emailAccountId)
            ->where('workspace_id', $this->workspaceId)
            ->first();

        if (! $emailAccount) {
            Log::warning('email-sync-job-account-not-found', [
                'email_account_id' => $this->emailAccountId,
                'workspace_id' => $this->workspaceId,
            ]);

            return;
        }

        try {
            app(WorkspaceContext::class)->runForId((int) $emailAccount->workspace_id, function () use ($imapSyncService, $emailAccount): void {
                $imapSyncService->syncAccount($emailAccount, 40);
            });
        } catch (CustomerMailboxException $exception) {
            $errorClassifier->log($exception, 'imap');

            throw $exception;
        } catch (\Throwable $exception) {
            $wrapped = $errorClassifier->wrap($exception, 'imap', $emailAccount);

            throw $wrapped;
        }
    }
}
