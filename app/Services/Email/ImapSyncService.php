<?php

namespace App\Services\Email;

use App\Models\EmailAccount;
use App\Models\EmailAttachment;
use App\Models\EmailMessage;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

class ImapSyncService
{
    public function syncAccount(EmailAccount $account, int $limit = 30): int
    {
        if (! function_exists('imap_open')) {
            \Illuminate\Support\Facades\Log::warning('email.imap.extension_missing', [
                'email_account_id' => $account->id,
                'workspace_id' => $account->workspace_id,
            ]);

            return 0;
        }

        $mailbox = sprintf('{%s:%d/imap/ssl}INBOX', $account->imap_host, $account->imap_port);
        $stream = @imap_open($mailbox, $account->email, (string) $account->password);

        if (! $stream) {
            throw new \RuntimeException((string) (imap_last_error() ?: 'Unable to open IMAP mailbox.'));
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

                $externalMessageId = $this->normalizeMessageId((string) ($overview->message_id ?? 'imap-'.$account->id.'-'.$messageNumber));
                $alreadyExists = EmailMessage::withoutGlobalScopes()
                    ->where('workspace_id', $account->workspace_id)
                    ->where('email_account_id', $account->id)
                    ->where('message_id', $externalMessageId)
                    ->exists();

                if ($alreadyExists) {
                    continue;
                }

                $body = (string) (imap_fetchbody($stream, $messageNumber, '1.1') ?: imap_fetchbody($stream, $messageNumber, '1') ?: imap_body($stream, $messageNumber));
                $inReplyTo = isset($overview->in_reply_to) ? $this->normalizeMessageId((string) $overview->in_reply_to) : null;
                $threadKey = $inReplyTo ?: $externalMessageId;

                $message = EmailMessage::withoutGlobalScopes()->create([
                    'workspace_id' => $account->workspace_id,
                    'email_account_id' => $account->id,
                    'sender' => $this->decodeMimeHeader((string) ($overview->from ?? '')),
                    'recipient' => $this->decodeMimeHeader((string) ($overview->to ?? $account->email)),
                    'subject' => $this->decodeMimeHeader((string) ($overview->subject ?? '(بدون عنوان)')),
                    'body' => trim($body),
                    'type' => 'inbound',
                    'delivery_status' => 'received',
                    'message_id' => $externalMessageId,
                    'in_reply_to' => $inReplyTo,
                    'thread_key' => $threadKey,
                    'created_at' => isset($overview->date) ? Carbon::parse((string) $overview->date) : now(),
                    'delivered_at' => now(),
                ]);

                $structure = imap_fetchstructure($stream, $messageNumber);
                if ($structure) {
                    $this->extractAndStoreAttachments($stream, $structure, (int) $messageNumber, $message);
                }

                $synced++;
            }
        } finally {
            imap_close($stream);
        }

        return $synced;
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
            3 => base64_decode($body, true) ?: $body, // BASE64
            4 => quoted_printable_decode($body), // QUOTED-PRINTABLE
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
