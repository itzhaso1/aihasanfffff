<?php

namespace App\Services\Email;

use App\Mail\CentralTemplatedEmail;
use App\Models\EmailLog;
use GuzzleHttp\Client;
use GuzzleHttp\Exception\GuzzleException;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;
use Illuminate\Support\Facades\View;

class CentralEmailService
{
    public function __construct(
        private readonly Client $httpClient,
    ) {}

    /**
     * @param  array{
     *     to: string|array<int, string>,
     *     template?: string,
     *     subject?: string|null,
     *     data?: array<string, mixed>,
     *     attachments?: array<int, array<string, mixed>>,
     *     cc?: string|array<int, string>,
     *     bcc?: string|array<int, string>,
     *     reply_to?: string|array<int, string>|null,
     *     from?: string|null,
     *     from_name?: string|null,
     *     workspace_id?: int|null,
     *     email_message_id?: int|null,
     *     meta?: array<string, mixed>
     * } $payload
     */
    public function send(array $payload): EmailLog
    {
        $mailerName = (string) config('email_templates.mailer', 'resend');
        if ($mailerName === 'resend' && blank((string) config('services.resend.key'))) {
            throw new \RuntimeException('Resend API key is not configured.');
        }

        $templateKey = (string) ($payload['template'] ?? config('email_templates.default_template', 'general_notification'));
        $template = config('email_templates.templates.'.$templateKey);
        if (! is_array($template) || ! isset($template['view'])) {
            throw new \InvalidArgumentException('Unknown email template: '.$templateKey);
        }

        $to = $this->normalizeRecipients($payload['to'] ?? []);
        if ($to === []) {
            throw new \InvalidArgumentException('At least one valid recipient is required.');
        }

        $cc = $this->normalizeRecipients($payload['cc'] ?? []);
        $bcc = $this->normalizeRecipients($payload['bcc'] ?? []);
        $replyTo = $this->normalizeRecipients($payload['reply_to'] ?? []);

        $subject = trim((string) ($payload['subject'] ?? $template['subject'] ?? 'إشعار من النظام'));
        $subject = $subject !== '' ? $subject : 'إشعار من النظام';

        $workspaceId = isset($payload['workspace_id']) ? (int) $payload['workspace_id'] : null;
        $logAttributes = [
            'workspace_id' => $workspaceId,
            'email_message_id' => isset($payload['email_message_id']) ? (int) $payload['email_message_id'] : null,
            'provider' => 'resend',
            'template' => $templateKey,
            'recipient' => implode(', ', $to),
            'subject' => $subject,
            'status' => 'pending',
            'meta' => array_merge((array) ($payload['meta'] ?? []), [
                'cc' => $cc,
                'bcc' => $bcc,
            ]),
        ];
        $persist = function (callable $callback) use ($workspaceId) {
            if ($workspaceId) {
                return app(\App\Support\Tenancy\WorkspaceContext::class)->runForId($workspaceId, $callback);
            }

            return $callback();
        };

        $log = $persist(fn () => EmailLog::query()->create($logAttributes));

        try {
            $providerMessageId = $mailerName === 'resend'
                ? $this->sendViaResend(
                    to: $to,
                    cc: $cc,
                    bcc: $bcc,
                    replyTo: $replyTo,
                    templateView: (string) $template['view'],
                    subject: $subject,
                    data: (array) ($payload['data'] ?? []),
                    from: $payload['from'] ?? null,
                    fromName: $payload['from_name'] ?? null,
                    attachments: (array) ($payload['attachments'] ?? []),
                )
                : $this->sendViaLaravelMailer(
                    mailerName: $mailerName,
                    to: $to,
                    cc: $cc,
                    bcc: $bcc,
                    replyTo: $replyTo,
                    templateView: (string) $template['view'],
                    subject: $subject,
                    data: (array) ($payload['data'] ?? []),
                    from: $payload['from'] ?? null,
                    fromName: $payload['from_name'] ?? null,
                    attachments: (array) ($payload['attachments'] ?? []),
                );

            $persist(function () use ($log, $providerMessageId): void {
                $log->forceFill([
                    'status' => 'sent',
                    'provider_message_id' => $providerMessageId !== '' ? $providerMessageId : null,
                    'error' => null,
                    'sent_at' => now(),
                ])->save();
            });
        } catch (\Throwable $exception) {
            $sanitizedError = Str::limit($exception->getMessage(), 2000);

            $persist(function () use ($log, $sanitizedError): void {
                $log->forceFill([
                    'status' => 'failed',
                    'error' => $sanitizedError,
                ])->save();
            });

            Log::error('central-email-send-failed', [
                'email_log_id' => $log->id,
                'template' => $templateKey,
                'workspace_id' => $payload['workspace_id'] ?? null,
                'email_message_id' => $payload['email_message_id'] ?? null,
                'recipient_count' => count($to),
                'error' => $sanitizedError,
            ]);

            throw new \RuntimeException('تعذر إرسال البريد حاليًا، يرجى المحاولة لاحقًا.');
        }

        return $persist(fn () => $log->fresh());
    }

    /**
     * @param  array<int, string>  $to
     * @param  array<int, string>  $cc
     * @param  array<int, string>  $bcc
     * @param  array<int, string>  $replyTo
     * @param  array<string, mixed>  $data
     * @param  array<int, array<string, mixed>>  $attachments
     */
    private function sendViaResend(
        array $to,
        array $cc,
        array $bcc,
        array $replyTo,
        string $templateView,
        string $subject,
        array $data,
        ?string $from,
        ?string $fromName,
        array $attachments,
    ): ?string {
        $apiKey = (string) config('services.resend.key');
        if ($apiKey === '') {
            throw new \RuntimeException('Resend API key is not configured.');
        }

        $fromAddress = $from ?: (string) config('email_templates.default_from_address');
        $fromName = $fromName ?: (string) config('email_templates.default_from_name');
        $html = View::make($templateView, [
            'data' => $data,
            'subject' => $subject,
        ])->render();

        $payload = [
            'from' => trim($fromName).' <'.$fromAddress.'>',
            'to' => $to,
            'subject' => $subject,
            'html' => $html,
        ];

        if ($cc !== []) {
            $payload['cc'] = $cc;
        }

        if ($bcc !== []) {
            $payload['bcc'] = $bcc;
        }

        if ($replyTo !== []) {
            $payload['reply_to'] = $replyTo;
        }

        $encodedAttachments = $this->buildResendAttachments($attachments);
        if ($encodedAttachments !== []) {
            $payload['attachments'] = $encodedAttachments;
        }

        try {
            $response = $this->httpClient->request('POST', 'https://api.resend.com/emails', [
                'headers' => [
                    'Authorization' => 'Bearer '.$apiKey,
                    'Content-Type' => 'application/json',
                ],
                'json' => $payload,
                'timeout' => 20,
            ]);
        } catch (GuzzleException $exception) {
            throw new \RuntimeException('Resend request failed: '.$exception->getMessage(), previous: $exception);
        }

        /** @var array<string, mixed> $decoded */
        $decoded = json_decode((string) $response->getBody(), true) ?? [];
        $providerMessageId = $decoded['id'] ?? null;

        return is_string($providerMessageId) && $providerMessageId !== '' ? $providerMessageId : null;
    }

    /**
     * @param  array<int, string>  $to
     * @param  array<int, string>  $cc
     * @param  array<int, string>  $bcc
     * @param  array<int, string>  $replyTo
     * @param  array<string, mixed>  $data
     * @param  array<int, array<string, mixed>>  $attachments
     */
    private function sendViaLaravelMailer(
        string $mailerName,
        array $to,
        array $cc,
        array $bcc,
        array $replyTo,
        string $templateView,
        string $subject,
        array $data,
        ?string $from,
        ?string $fromName,
        array $attachments,
    ): ?string {
        $mailable = new CentralTemplatedEmail(
            subjectLine: $subject,
            viewName: $templateView,
            data: $data,
            attachments: $attachments,
            fromAddress: $from,
            fromName: $fromName,
            replyTo: $replyTo,
        );

        $pendingMail = Mail::mailer($mailerName)->to($to);
        if ($cc !== []) {
            $pendingMail->cc($cc);
        }
        if ($bcc !== []) {
            $pendingMail->bcc($bcc);
        }

        $sentMessage = $pendingMail->send($mailable);
        if (is_object($sentMessage) && method_exists($sentMessage, 'getMessageId')) {
            $id = (string) ($sentMessage->getMessageId() ?? '');

            return $id !== '' ? $id : null;
        }

        return null;
    }

    /**
     * @param  array<int, array<string, mixed>>  $attachments
     * @return array<int, array<string, string>>
     */
    private function buildResendAttachments(array $attachments): array
    {
        return collect($attachments)
            ->map(function (array $attachment): ?array {
                $name = trim((string) ($attachment['name'] ?? 'attachment.bin'));
                $mime = trim((string) ($attachment['mime'] ?? 'application/octet-stream'));
                $content = null;

                if (isset($attachment['content'])) {
                    $content = (string) $attachment['content'];
                } elseif (isset($attachment['path']) && is_string($attachment['path']) && is_file($attachment['path'])) {
                    $content = file_get_contents($attachment['path']) ?: null;
                } elseif (
                    isset($attachment['storage_disk'], $attachment['storage_path'])
                    && Storage::disk((string) $attachment['storage_disk'])->exists((string) $attachment['storage_path'])
                ) {
                    $content = Storage::disk((string) $attachment['storage_disk'])->get((string) $attachment['storage_path']);
                } elseif (
                    isset($attachment['public_storage_path'])
                    && Storage::disk('public')->exists((string) $attachment['public_storage_path'])
                ) {
                    $content = Storage::disk('public')->get((string) $attachment['public_storage_path']);
                }

                if (! is_string($content) || $content === '') {
                    return null;
                }

                return [
                    'filename' => $name,
                    'content' => base64_encode($content),
                    'type' => $mime !== '' ? $mime : 'application/octet-stream',
                ];
            })
            ->filter()
            ->values()
            ->all();
    }

    /**
     * @param  string|array<int, string>  $raw
     * @return array<int, string>
     */
    private function normalizeRecipients(string|array $raw): array
    {
        if (is_array($raw)) {
            $parts = $raw;
        } else {
            $parts = preg_split('/[\r\n,;]+/', $raw) ?: [];
        }

        return collect($parts)
            ->map(function ($candidate): string {
                $email = strtolower(trim((string) $candidate));
                if (str_contains($email, '<') && str_contains($email, '>')) {
                    $email = trim((string) Str::between($email, '<', '>'));
                }

                return $email;
            })
            ->filter(fn (string $email): bool => $email !== '' && filter_var($email, FILTER_VALIDATE_EMAIL) !== false)
            ->unique()
            ->values()
            ->all();
    }
}
