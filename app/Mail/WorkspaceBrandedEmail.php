<?php

namespace App\Mail;

use App\Models\EmailAccount;
use App\Models\EmailBranding;
use App\Models\EmailMessage;
use App\Services\Email\EmailBrandingHtmlBuilder;
use App\Support\Email\EmailBrandInlineImages;
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

    /** @var array<string, mixed>|null */
    private ?array $outboundViewData = null;

    public function __construct(
        public readonly EmailMessage $emailMessage,
        public readonly EmailAccount $emailAccount,
    ) {}

    public function envelope(): Envelope
    {
        $fromName = $this->emailAccount->senderDisplayName($this->emailMessage->sender);

        return new Envelope(
            from: new Address($this->emailAccount->email, $fromName),
            replyTo: [
                new Address($this->emailAccount->email, $fromName),
            ],
            subject: $this->emailMessage->subject ?: '(بدون عنوان)',
            using: [
                function (Email $email): void {
                    if ($this->emailMessage->in_reply_to) {
                        $replyId = $this->normalizeHeaderId((string) $this->emailMessage->in_reply_to);
                        $email->getHeaders()->addIdHeader('In-Reply-To', $replyId);
                        $email->getHeaders()->addTextHeader('References', '<'.$replyId.'>');
                    }

                    if ($this->emailMessage->message_id) {
                        $email->getHeaders()->addIdHeader('Message-ID', $this->normalizeHeaderId((string) $this->emailMessage->message_id));
                    }

                    foreach (EmailBrandInlineImages::embeds(
                        (int) $this->emailMessage->workspace_id,
                        $this->logoStoragePath(),
                        $this->outboundViewData()['socialLinks'] ?? [],
                    ) as $embed) {
                        $email->embedFromPath($embed['path'], $embed['cid'], $embed['mime']);
                    }
                },
            ],
        );
    }

    public function content(): Content
    {
        return new Content(
            view: 'emails.workspace-branded',
            with: EmailBrandInlineImages::withCids(
                $this->outboundViewData(),
                (int) $this->emailMessage->workspace_id,
                $this->logoStoragePath(),
            ),
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

    /**
     * @return array<string, mixed>
     */
    private function outboundViewData(): array
    {
        return $this->outboundViewData ??= app(EmailBrandingHtmlBuilder::class)
            ->forOutbound($this->emailMessage, $this->emailAccount);
    }

    private function logoStoragePath(): ?string
    {
        $workspaceId = (int) $this->emailMessage->workspace_id;
        if ($workspaceId < 1 || (int) $this->emailAccount->workspace_id !== $workspaceId) {
            return null;
        }

        $branding = EmailBranding::withoutGlobalScopes()
            ->where('workspace_id', $workspaceId)
            ->first();

        $path = $branding?->logo_path ?: $this->emailAccount->logo_path;

        return is_string($path) && $path !== '' ? $path : null;
    }

    private function normalizeHeaderId(string $id): string
    {
        return trim($id, " \t\n\r\0\x0B<>");
    }
}
