<?php

namespace App\Services\Email;

use App\Models\EmailLog;
use App\Models\EmailMessage;
use Illuminate\Support\Facades\Storage;

class WorkspaceEmailSender
{
    public function __construct(
        private readonly CentralEmailService $centralEmailService,
    ) {}

    public function send(EmailMessage $emailMessage): EmailLog
    {
        $emailMessage->loadMissing(['account', 'attachments']);
        $account = $emailMessage->account;

        if (! $account) {
            throw new \RuntimeException('Email account is missing.');
        }

        $recipients = collect(explode(',', (string) $emailMessage->recipient))
            ->map(fn (string $recipient): string => trim($recipient))
            ->filter()
            ->values()
            ->all();

        if (count($recipients) === 0) {
            throw new \RuntimeException('No valid recipient was provided.');
        }

        $attachments = $emailMessage->attachments->map(function ($attachment): ?array {
            try {
                $path = $attachment->assertSafeStoragePath();
            } catch (\RuntimeException) {
                return null;
            }
            $disk = $attachment->storageDisk();
            if ($path === '' || ! Storage::disk($disk)->exists($path)) {
                return null;
            }

            return [
                'storage_disk' => $disk,
                'storage_path' => $path,
                'name' => basename($path),
                'mime' => $attachment->file_type,
            ];
        })->filter()->values()->all();

        return $this->centralEmailService->send([
            'to' => $recipients,
            'template' => 'workspace_branded',
            'subject' => $emailMessage->subject ?: 'رسالة جديدة',
            'workspace_id' => $emailMessage->workspace_id,
            'email_message_id' => $emailMessage->id,
            'reply_to' => $account->email ? [$account->email] : [],
            'attachments' => $attachments,
            'data' => [
                'headline' => $emailMessage->subject ?: 'رسالة جديدة',
                'body' => (string) $emailMessage->body,
                'brand_color' => $account->brand_color ?: '#06C2A4',
                'account_name' => $account->name,
                'company_name' => $account->name,
                'logo_url' => $account->logo_path ? Storage::disk('public')->url($account->logo_path) : null,
            ],
            'meta' => [
                'source' => 'workspace_email_sender',
            ],
        ]);
    }
}
