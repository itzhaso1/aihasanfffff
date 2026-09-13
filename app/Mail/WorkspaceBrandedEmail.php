<?php

namespace App\Mail;

use App\Models\EmailAccount;
use App\Models\EmailMessage;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Address;
use Illuminate\Mail\Mailables\Attachment;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Symfony\Component\Mime\Email;

class WorkspaceBrandedEmail extends Mailable
{
    use Queueable, SerializesModels;

    public function __construct(
        public readonly EmailMessage $emailMessage,
        public readonly EmailAccount $emailAccount,
    ) {}

    public function envelope(): Envelope
    {
        return new Envelope(
            from: new Address($this->emailAccount->email, $this->emailAccount->name),
            subject: $this->emailMessage->subject ?: '(بدون عنوان)',
            using: [
                function (Email $email): void {
                    if ($this->emailMessage->in_reply_to) {
                        $email->getHeaders()->addIdHeader('In-Reply-To', $this->emailMessage->in_reply_to);
                        $email->getHeaders()->addTextHeader('References', $this->emailMessage->in_reply_to);
                    }

                    if ($this->emailMessage->message_id) {
                        $email->getHeaders()->addIdHeader('Message-ID', $this->emailMessage->message_id);
                    }
                },
            ],
        );
    }

    public function content(): Content
    {
        return new Content(
            view: 'emails.workspace-branded',
            with: [
                'subject' => $this->emailMessage->subject,
                'body' => $this->emailMessage->body,
                'brandColor' => $this->emailAccount->brand_color ?: '#06C2A4',
                'logoUrl' => $this->emailAccount->logo_path ? Storage::disk('public')->url($this->emailAccount->logo_path) : null,
                'accountName' => $this->emailAccount->name,
                'companyName' => $this->emailAccount->name,
            ]
        );
    }

    /**
     * @return array<int, Attachment>
     */
    public function attachments(): array
    {
        return $this->emailMessage->attachments
            ->map(function ($attachment): ?Attachment {
                try {
                    $absolutePath = Storage::disk($attachment->storageDisk())->path($attachment->assertSafeStoragePath());
                } catch (\RuntimeException) {
                    return null;
                }
                if (! is_file($absolutePath)) {
                    return null;
                }

                $storedName = basename((string) $attachment->file_path);
                $displayName = Str::contains($storedName, '_') ? Str::after($storedName, '_') : $storedName;

                $mailAttachment = Attachment::fromPath($absolutePath)
                    ->as($displayName);

                if ($attachment->file_type) {
                    $mailAttachment = $mailAttachment->withMime($attachment->file_type);
                }

                return $mailAttachment;
            })
            ->filter()
            ->values()
            ->all();
    }
}
