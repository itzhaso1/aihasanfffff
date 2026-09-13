<?php

namespace App\Jobs;

use App\Models\EmailMessage;
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

            $emailLog = $workspaceEmailSender->send($emailMessage);

            $emailMessage->forceFill([
                'delivery_status' => 'sent',
                'delivery_error' => null,
                'delivered_at' => now(),
                'message_id' => $emailLog->provider_message_id ?: $emailMessage->message_id,
            ])->save();
        } catch (\Throwable $exception) {
            Log::error('email-send-job-failed', [
                'email_message_id' => $this->emailMessageId,
                'workspace_id' => $emailMessage->workspace_id,
                'error' => $exception->getMessage(),
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
                'delivery_error' => $exception->getMessage(),
            ])->save();
        });
    }

    private function findOwnedMessage(): ?EmailMessage
    {
        $query = EmailMessage::withoutGlobalScopes()->whereKey($this->emailMessageId);
        if ($this->workspaceId !== null) {
            $query->where('workspace_id', $this->workspaceId);
        }

        return $query->first();
    }
}
