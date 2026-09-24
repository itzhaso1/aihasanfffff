<?php

namespace App\Services\Email;

use App\Exceptions\CustomerMailboxException;
use App\Models\EmailAccount;
use App\Models\EmailLog;
use App\Models\EmailMessage;
use App\Support\Tenancy\WorkspaceContext;
use Illuminate\Support\Facades\DB;
use Throwable;

class WorkspaceEmailSender
{
    public function __construct(
        private readonly CustomerSmtpMailer $customerSmtpMailer,
        private readonly CustomerMailboxErrorClassifier $errorClassifier,
    ) {}

    public function send(EmailMessage $emailMessage): EmailLog
    {
        $emailMessage->loadMissing(['account', 'attachments']);
        $account = $this->ownedAccount($emailMessage);

        $recipients = collect(explode(',', (string) $emailMessage->recipient))
            ->map(fn (string $recipient): string => trim($recipient))
            ->filter()
            ->values()
            ->all();

        if ($recipients === []) {
            throw new CustomerMailboxException(
                $this->errorClassifier->userMessage('send'),
                CustomerMailboxErrorClassifier::CONFIGURATION,
                (int) $emailMessage->workspace_id,
                $account->id,
                $emailMessage->id,
            );
        }

        $log = $this->persist($emailMessage->workspace_id, fn () => EmailLog::query()->create([
            'workspace_id' => $emailMessage->workspace_id,
            'email_message_id' => $emailMessage->id,
            'provider' => 'customer_smtp',
            'template' => 'workspace_branded',
            'recipient' => implode(', ', $recipients),
            'subject' => $emailMessage->subject ?: 'رسالة جديدة',
            'status' => 'pending',
            'meta' => [
                'source' => 'workspace_email_sender',
                'email_account_id' => $account->id,
                'smtp_host' => $account->smtp_host,
                'smtp_port' => $account->smtp_port,
                'smtp_username' => $account->smtpUsername(),
                'smtp_encryption' => $account->resolvedSmtpEncryption(),
            ],
        ]));

        try {
            $providerMessageId = $this->customerSmtpMailer->send($account, $emailMessage, $recipients);

            return $this->persist($emailMessage->workspace_id, function () use ($log, $emailMessage, $providerMessageId): EmailLog {
                return DB::transaction(function () use ($log, $emailMessage, $providerMessageId): EmailLog {
                    $deliveredAt = now();
                    $messageId = $providerMessageId ?: $emailMessage->message_id;

                    $ownedMessage = EmailMessage::withoutGlobalScopes()
                        ->whereKey($emailMessage->id)
                        ->where('workspace_id', $emailMessage->workspace_id)
                        ->where('email_account_id', $emailMessage->email_account_id)
                        ->lockForUpdate()
                        ->first();

                    if (! $ownedMessage) {
                        throw new CustomerMailboxException(
                            $this->errorClassifier->userMessage('send'),
                            CustomerMailboxErrorClassifier::CONFIGURATION,
                            (int) $emailMessage->workspace_id,
                            (int) $emailMessage->email_account_id,
                            $emailMessage->id,
                        );
                    }

                    $ownedMessage->forceFill([
                        'delivery_status' => 'sent',
                        'delivery_error' => null,
                        'delivered_at' => $deliveredAt,
                        'message_id' => $messageId,
                    ])->save();

                    $log->forceFill([
                        'status' => 'sent',
                        'provider_message_id' => $providerMessageId,
                        'error' => null,
                        'sent_at' => $deliveredAt,
                    ])->save();

                    $emailMessage->forceFill([
                        'delivery_status' => 'sent',
                        'delivery_error' => null,
                        'delivered_at' => $deliveredAt,
                        'message_id' => $messageId,
                    ]);

                    return $log->fresh() ?? $log;
                });
            });
        } catch (Throwable $exception) {
            $wrapped = $this->errorClassifier->wrap($exception, 'send', $account, $emailMessage);

            $this->persist($emailMessage->workspace_id, function () use ($log, $wrapped): void {
                $log->forceFill([
                    'status' => 'failed',
                    'error' => $this->errorClassifier->safeStoredError($wrapped, 'send'),
                ])->save();
            });

            throw $wrapped;
        }
    }

    private function ownedAccount(EmailMessage $emailMessage): EmailAccount
    {
        $account = $emailMessage->account;
        if (! $account instanceof EmailAccount) {
            $account = EmailAccount::withoutGlobalScopes()
                ->whereKey($emailMessage->email_account_id)
                ->where('workspace_id', $emailMessage->workspace_id)
                ->first();
        }

        if (
            ! $account
            || (int) $account->workspace_id !== (int) $emailMessage->workspace_id
            || (int) $account->id !== (int) $emailMessage->email_account_id
        ) {
            throw new CustomerMailboxException(
                $this->errorClassifier->userMessage('send'),
                CustomerMailboxErrorClassifier::CONFIGURATION,
                (int) $emailMessage->workspace_id,
                $emailMessage->email_account_id ? (int) $emailMessage->email_account_id : null,
                $emailMessage->id,
            );
        }

        return $account;
    }

    /**
     * @template T
     *
     * @param  callable(): T  $callback
     * @return T
     */
    private function persist(?int $workspaceId, callable $callback): mixed
    {
        if ($workspaceId) {
            return app(WorkspaceContext::class)->runForId((int) $workspaceId, $callback);
        }

        return $callback();
    }
}
