<?php

namespace App\Mail;

use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Address;
use Illuminate\Mail\Mailables\Attachment;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Storage;

class CentralTemplatedEmail extends Mailable
{
    use Queueable, SerializesModels;

    /**
     * @param  array<int, array<string, mixed>>  $attachments
     * @param  array<int, string>  $replyTo
     * @param  array<string, mixed>  $data
     */
    public function __construct(
        private readonly string $subjectLine,
        private readonly string $viewName,
        private readonly array $data,
        private readonly array $attachments = [],
        private readonly ?string $fromAddress = null,
        private readonly ?string $fromName = null,
        private readonly array $replyTo = [],
    ) {}

    public function envelope(): Envelope
    {
        $fromAddress = $this->fromAddress ?: config('email_templates.default_from_address');
        $fromName = $this->fromName ?: config('email_templates.default_from_name');
        $replyTo = collect($this->replyTo)
            ->map(fn (string $email): Address => new Address($email))
            ->values()
            ->all();

        return new Envelope(
            from: new Address((string) $fromAddress, (string) $fromName),
            replyTo: $replyTo,
            subject: $this->subjectLine
        );
    }

    public function content(): Content
    {
        return new Content(
            view: $this->viewName,
            with: [
                'data' => $this->data,
                'subject' => $this->subjectLine,
            ],
        );
    }

    /**
     * @return array<int, Attachment>
     */
    public function attachments(): array
    {
        return collect($this->attachments)->map(function (array $attachment): ?Attachment {
            $name = (string) ($attachment['name'] ?? 'attachment.bin');
            $mime = $attachment['mime'] ?? null;

            if (isset($attachment['storage_disk'], $attachment['storage_path'])) {
                $mailAttachment = Attachment::fromStorageDisk(
                    (string) $attachment['storage_disk'],
                    (string) $attachment['storage_path']
                )->as($name);

                return is_string($mime) && $mime !== '' ? $mailAttachment->withMime($mime) : $mailAttachment;
            }

            if (isset($attachment['path'])) {
                $mailAttachment = Attachment::fromPath((string) $attachment['path'])->as($name);

                return is_string($mime) && $mime !== '' ? $mailAttachment->withMime($mime) : $mailAttachment;
            }

            if (isset($attachment['public_storage_path'])) {
                $absolutePath = Storage::disk('public')->path((string) $attachment['public_storage_path']);
                if (! is_file($absolutePath)) {
                    return null;
                }

                $mailAttachment = Attachment::fromPath($absolutePath)->as($name);

                return is_string($mime) && $mime !== '' ? $mailAttachment->withMime($mime) : $mailAttachment;
            }

            if (isset($attachment['content'])) {
                $binary = (string) $attachment['content'];
                $mailAttachment = Attachment::fromData(static fn () => $binary, $name);

                return is_string($mime) && $mime !== '' ? $mailAttachment->withMime($mime) : $mailAttachment;
            }

            return null;
        })->filter()->values()->all();
    }
}
