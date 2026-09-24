<?php

namespace App\Services\Email;

use App\Exceptions\CustomerMailboxException;
use App\Models\EmailAccount;
use App\Models\EmailAttachment;
use App\Models\EmailMessage;
use App\Support\Network\MailboxHostValidator;
use App\Support\Tenancy\WorkspaceContext;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Throwable;

class ImapSyncService
{
    public function __construct(
        private readonly CustomerMailboxErrorClassifier $errorClassifier,
    ) {}

    public function extensionAvailable(): bool
    {
        return function_exists('imap_open');
    }

    /**
     * @return array{mailbox: string, username: string, email_account_id: int, workspace_id: int}
     */
    public function connectionSettings(EmailAccount $account): array
    {
        return [
            'mailbox' => $account->imapMailbox(),
            'username' => $account->imapUsername(),
            'email_account_id' => (int) $account->id,
            'workspace_id' => (int) $account->workspace_id,
        ];
    }

    public function syncAccount(EmailAccount $account, int $limit = 30): int
    {
        try {
            MailboxHostValidator::assertAllowed((string) $account->imap_host, 'imap_host');
        } catch (ValidationException $exception) {
            throw new CustomerMailboxException(
                'تعذر مزامنة الوارد. عنوان خادم IMAP غير مسموح.',
                CustomerMailboxErrorClassifier::CONFIGURATION,
                (int) $account->workspace_id,
                (int) $account->id,
                previous: $exception,
            );
        }

        if (! $this->extensionAvailable()) {
            Log::warning('email.imap.extension_missing', [
                'email_account_id' => $account->id,
                'workspace_id' => $account->workspace_id,
            ]);

            throw new CustomerMailboxException(
                'تعذر مزامنة الوارد. امتداد IMAP غير مفعّل على الخادم.',
                CustomerMailboxErrorClassifier::CONFIGURATION,
                (int) $account->workspace_id,
                (int) $account->id,
            );
        }

        $settings = $this->connectionSettings($account);
        $stream = @imap_open($settings['mailbox'], $settings['username'], $account->mailboxPassword());

        if (! $stream) {
            imap_errors();

            throw $this->errorClassifier->wrap(
                new \RuntimeException('Unable to open IMAP mailbox.'),
                'imap',
                $account,
            );
        }

        $synced = 0;

        try {
            $ids = imap_search($stream, 'ALL') ?: [];
            rsort($ids);

            foreach (array_slice($ids, 0, $limit) as $messageNumber) {
                $overviewList = imap_fetch_overview($stream, (string) $messageNumber, 0);
                $overview = $overviewList[0] ?? null;

                if (! $overview) {
                    continue;
                }

                $body = (string) (imap_fetchbody($stream, $messageNumber, '1.1') ?: imap_fetchbody($stream, $messageNumber, '1') ?: imap_body($stream, $messageNumber));
                $imported = $this->importMessage($account, [
                    'message_id' => (string) ($overview->message_id ?? ''),
                    'fallback_key' => 'imap-'.$account->id.'-'.$messageNumber,
                    'from' => $this->decodeMimeHeader((string) ($overview->from ?? '')),
                    'to' => $this->decodeMimeHeader((string) ($overview->to ?? $account->email)),
                    'subject' => $this->decodeMimeHeader((string) ($overview->subject ?? '(بدون عنوان)')),
                    'body' => trim($body),
                    'in_reply_to' => isset($overview->in_reply_to) ? $this->normalizeMessageId((string) $overview->in_reply_to) : null,
                    'date' => isset($overview->date) ? (string) $overview->date : null,
                ]);

                if (! $imported) {
                    continue;
                }

                $structure = imap_fetchstructure($stream, $messageNumber);
                if ($structure) {
                    $this->extractAndStoreAttachments($stream, $structure, (int) $messageNumber, $imported);
                }

                $synced++;
            }
        } catch (CustomerMailboxException $exception) {
            throw $exception;
        } catch (Throwable $exception) {
            throw $this->errorClassifier->wrap($exception, 'imap', $account);
        } finally {
            imap_close($stream);
        }

        return $synced;
    }

    /**
     * Persist one inbound message for the selected account. Used by IMAP sync and tests.
     *
     * @param  array{
     *     message_id?: string,
     *     fallback_key?: string,
     *     from?: string,
     *     to?: string,
     *     subject?: string,
     *     body?: string,
     *     in_reply_to?: string|null,
     *     date?: string|null
     * }  $payload
     */
    public function importMessage(EmailAccount $account, array $payload): ?EmailMessage
    {
        $externalMessageId = $this->normalizeMessageId((string) ($payload['message_id'] ?? ''));
        if ($externalMessageId === '') {
            $externalMessageId = $this->normalizeMessageId((string) ($payload['fallback_key'] ?? 'imap-'.$account->id.'-'.uniqid()));
        }

        $alreadyExists = EmailMessage::withoutGlobalScopes()
            ->where('workspace_id', $account->workspace_id)
            ->where('email_account_id', $account->id)
            ->where('message_id', $externalMessageId)
            ->exists();

        if ($alreadyExists) {
            return null;
        }

        $inReplyTo = isset($payload['in_reply_to']) && $payload['in_reply_to']
            ? $this->normalizeMessageId((string) $payload['in_reply_to'])
            : null;
        $threadKey = $inReplyTo ?: $externalMessageId;

        return app(WorkspaceContext::class)->runForId((int) $account->workspace_id, function () use ($account, $payload, $externalMessageId, $inReplyTo, $threadKey): EmailMessage {
            return EmailMessage::withoutGlobalScopes()->create([
                'workspace_id' => $account->workspace_id,
                'email_account_id' => $account->id,
                'sender' => (string) ($payload['from'] ?? ''),
                'recipient' => (string) ($payload['to'] ?? $account->email),
                'subject' => (string) ($payload['subject'] ?? '(بدون عنوان)'),
                'body' => (string) ($payload['body'] ?? ''),
                'type' => 'inbound',
                'delivery_status' => 'received',
                'message_id' => $externalMessageId,
                'in_reply_to' => $inReplyTo,
                'thread_key' => $threadKey,
                'created_at' => isset($payload['date']) && $payload['date'] ? Carbon::parse((string) $payload['date']) : now(),
                'delivered_at' => now(),
            ]);
        });
    }

    private function extractAndStoreAttachments($stream, object $structure, int $messageNumber, EmailMessage $message, string $partNumber = ''): void
    {
        $parts = $structure->parts ?? [];
        if (empty($parts)) {
            return;
        }

        foreach ($parts as $index => $part) {
            $currentPartNumber = $partNumber === '' ? (string) ($index + 1) : $partNumber.'.'.($index + 1);
            $filename = $this->attachmentFilename($part);

            if ($filename) {
                $rawBody = imap_fetchbody($stream, $messageNumber, $currentPartNumber);
                $decodedBody = $this->decodeBodyByEncoding($rawBody, (int) ($part->encoding ?? 0));
                $storagePath = 'workspaces/'.$message->workspace_id.'/emails/attachments/'.Str::uuid().'_'.basename($filename);
                Storage::disk('local')->put($storagePath, $decodedBody);

                EmailAttachment::query()->create([
                    'workspace_id' => $message->workspace_id,
                    'message_id' => $message->id,
                    'file_path' => $storagePath,
                    'disk' => 'local',
                    'file_type' => isset($part->subtype) ? strtolower((string) $part->subtype) : null,
                    'file_size' => strlen($decodedBody),
                ]);
            }

            if (! empty($part->parts)) {
                $this->extractAndStoreAttachments($stream, $part, $messageNumber, $message, $currentPartNumber);
            }
        }
    }

    private function attachmentFilename(object $part): ?string
    {
        $filename = null;

        if (! empty($part->dparameters)) {
            foreach ($part->dparameters as $parameter) {
                if (strtolower((string) $parameter->attribute) === 'filename') {
                    $filename = (string) $parameter->value;
                    break;
                }
            }
        }

        if (! $filename && ! empty($part->parameters)) {
            foreach ($part->parameters as $parameter) {
                if (strtolower((string) $parameter->attribute) === 'name') {
                    $filename = (string) $parameter->value;
                    break;
                }
            }
        }

        return $filename ? $this->decodeMimeHeader($filename) : null;
    }

    private function decodeBodyByEncoding(string $body, int $encoding): string
    {
        return match ($encoding) {
            3 => base64_decode($body, true) ?: $body,
            4 => quoted_printable_decode($body),
            default => $body,
        };
    }

    private function decodeMimeHeader(string $value): string
    {
        if (! function_exists('imap_mime_header_decode')) {
            return $value;
        }

        $elements = imap_mime_header_decode($value);
        if (! is_array($elements)) {
            return $value;
        }

        return collect($elements)
            ->map(fn ($element) => (string) ($element->text ?? ''))
            ->implode('');
    }

    private function normalizeMessageId(string $messageId): string
    {
        return trim($messageId, " \t\n\r\0\x0B<>");
    }
}
