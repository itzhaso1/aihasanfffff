<?php

namespace App\Jobs;

use App\Exceptions\CustomerMailboxException;
use App\Models\EmailMessage;
use App\Services\Email\CustomerMailboxErrorClassifier;
use App\Services\Email\WorkspaceEmailSender;
use App\Support\Tenancy\WorkspaceContext;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;
use Throwable;

class SendEmailMessageJob implements ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    public int $timeout = 120;

    public function __construct(
        public readonly int $emailMessageId,
        public readonly ?int $workspaceId = null,
    ) {}

    public function handle(WorkspaceEmailSender $workspaceEmailSender): void
    {
        $emailMessage = $this->findOwnedMessage();

        if (! $emailMessage || $emailMessage->type !== 'outbound') {
            return;
        }

        app(WorkspaceContext::class)->runForId((int) $emailMessage->workspace_id, function () use ($workspaceEmailSender, $emailMessage): void {
            $emailMessage->load(['account', 'attachments']);
            $this->deliver($workspaceEmailSender, $emailMessage);
        });
    }

    private function deliver(WorkspaceEmailSender $workspaceEmailSender, EmailMessage $emailMessage): void
    {
        try {
            $emailMessage->forceFill([
                'delivery_status' => 'sending',
                'delivery_error' => null,
            ])->save();

            $workspaceEmailSender->send($emailMessage);
        } catch (Throwable $exception) {
            Log::warning('email-send-job-failed', [
                'email_message_id' => $this->emailMessageId,
                'workspace_id' => $emailMessage->workspace_id,
                'email_account_id' => $emailMessage->email_account_id,
                'category' => $exception instanceof CustomerMailboxException
                    ? $exception->category
                    : app(CustomerMailboxErrorClassifier::class)->classify($exception),
            ]);

            throw $exception;
        }
    }

    public function failed(Throwable $exception): void
    {
        $emailMessage = $this->findOwnedMessage();
        if (! $emailMessage) {
            return;
        }

        app(WorkspaceContext::class)->runForId((int) $emailMessage->workspace_id, function () use ($emailMessage, $exception): void {
            $emailMessage->forceFill([
                'delivery_status' => 'failed',
                'delivery_error' => app(CustomerMailboxErrorClassifier::class)->safeStoredError($exception, 'send'),
            ])->save();
        });
    }

    private function findOwnedMessage(): ?EmailMessage
    {
        if ($this->workspaceId === null) {
            Log::warning('email-send-job-missing-workspace', [
                'email_message_id' => $this->emailMessageId,
            ]);

            return null;
        }

        return EmailMessage::withoutGlobalScopes()
            ->whereKey($this->emailMessageId)
            ->where('workspace_id', $this->workspaceId)
            ->first();
    }
}
