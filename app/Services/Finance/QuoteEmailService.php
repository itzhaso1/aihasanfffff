<?php

namespace App\Services\Finance;

use App\Enums\Finance\DocumentDeliveryChannel;
use App\Enums\Finance\DocumentDeliveryStatus;
use App\Enums\Finance\FinanceDocumentType;
use App\Models\Customer;
use App\Models\Finance\FinanceDocumentDelivery;
use App\Models\Finance\FinanceQuote;
use App\Models\User;
use App\Services\Audit\AuditLogService;
use App\Services\Email\CentralEmailService;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use RuntimeException;

/**
 * Prepares a quote email and sends it through CentralEmailService.
 *
 * Intentionally does not call Inbox, WhatsApp, SMS, invoice, payment, GL, or ZATCA.
 */
class QuoteEmailService
{
    public function __construct(
        private readonly CentralEmailService $centralEmailService,
        private readonly PdfQuoteService $pdfQuoteService,
        private readonly AuditLogService $auditLogService,
    ) {}

    /**
     * @param  array{
     *     email?:string,
     *     phone?:string|null,
     *     subject?:string|null,
     *     message?:string|null,
     *     attach_pdf?:bool|int|string|null
     * }  $payload
     */
    public function send(FinanceQuote $quote, array $payload, int $actorUserId): FinanceDocumentDelivery
    {
        $this->assertSendable($quote);

        $customer = $this->requireWorkspaceCustomer($quote);
        $email = $this->normalizedEmail($payload['email'] ?? $customer->email);
        if ($email === '') {
            throw new RuntimeException('لا يوجد بريد إلكتروني للعميل.');
        }
        if (filter_var($email, FILTER_VALIDATE_EMAIL) === false) {
            throw new RuntimeException('البريد الإلكتروني غير صالح.');
        }

        $phone = $this->nullablePhone($payload['phone'] ?? $customer->phone);
        $attachPdf = array_key_exists('attach_pdf', $payload)
            ? $this->boolean($payload['attach_pdf'])
            : true;

        $companyName = $this->companyName($quote);
        $customerName = trim((string) (data_get($quote->recipient_snapshot, 'name') ?: $customer->name));
        $subject = trim((string) ($payload['subject'] ?? ''));
        if ($subject === '') {
            $subject = 'عرض سعر رقم '.$quote->quote_number;
        }
        $message = trim((string) ($payload['message'] ?? ''));
        if ($message === '') {
            $message = $this->defaultMessage($quote, $companyName);
        }

        $delivery = FinanceDocumentDelivery::withoutGlobalScopes()->create([
            'workspace_id' => $quote->workspace_id,
            'document_type' => FinanceDocumentType::Quote->value,
            'document_id' => $quote->id,
            'channel' => DocumentDeliveryChannel::Email->value,
            'recipient' => $email,
            'recipient_phone' => $phone,
            'subject' => $subject,
            'status' => DocumentDeliveryStatus::Sending->value,
            'sent_by' => $actorUserId > 0 ? $actorUserId : null,
            'meta' => [
                'quote_number' => $quote->quote_number,
                'customer_id' => $customer->id,
                'customer_name' => $customerName,
                'attach_pdf' => $attachPdf,
            ],
        ]);

        try {
            $attachments = [];
            $attachmentDisk = null;
            $attachmentPath = null;
            if ($attachPdf) {
                $binary = $this->pdfQuoteService->renderBinary($quote);
                $filename = 'quote-'.$quote->quote_number.'.pdf';
                $attachmentPath = 'workspaces/'.$quote->workspace_id.'/finance/quotes/emails/'.Str::uuid().'_'.$filename;
                $attachmentDisk = 'public';
                Storage::disk($attachmentDisk)->put($attachmentPath, $binary);
                $attachments[] = [
                    'storage_disk' => $attachmentDisk,
                    'storage_path' => $attachmentPath,
                    'name' => $filename,
                    'mime' => 'application/pdf',
                ];
            }

            $emailLog = $this->centralEmailService->send([
                'to' => [$email],
                'template' => 'quote_email',
                'subject' => $subject,
                'workspace_id' => (int) $quote->workspace_id,
                'attachments' => $attachments,
                'data' => [
                    'headline' => 'عرض سعر رقم '.$quote->quote_number,
                    'intro' => $message,
                    'lines' => [
                        'العميل: '.$customerName,
                        'رقم عرض السعر: '.$quote->quote_number,
                        'تاريخ الإصدار: '.($quote->issue_date?->format('Y-m-d') ?: '—'),
                        'صلاحية العرض حتى: '.($quote->expiry_date?->format('Y-m-d') ?: '—'),
                        'الإجمالي: '.number_format((float) $quote->total, 2).' '.($quote->currency ?: 'SAR'),
                    ],
                    'brand_name' => $companyName,
                    'brand_color' => (string) (data_get($quote->pdf_snapshot, 'primary_color') ?: '#06C2A4'),
                    'footer' => 'هذه رسالة من '.$companyName.' — عرض سعر وليس فاتورة ضريبية.',
                ],
                'meta' => [
                    'source' => 'finance_quote_send',
                    'quote_id' => $quote->id,
                    'quote_number' => $quote->quote_number,
                    'delivery_id' => $delivery->id,
                ],
            ]);

            $delivery->forceFill([
                'status' => DocumentDeliveryStatus::Sent->value,
                'sent_at' => $emailLog->sent_at ?? now(config('app.timezone')),
                'provider_message_id' => $emailLog->provider_message_id,
                'email_log_id' => $emailLog->id,
                'attachment_disk' => $attachmentDisk,
                'attachment_path' => $attachmentPath,
                'error' => null,
            ])->save();

            $this->recordQuoteSentAudit($quote, $email, $actorUserId, $delivery);

            return $delivery->fresh();
        } catch (\Throwable $exception) {
            $delivery->forceFill([
                'status' => DocumentDeliveryStatus::Failed->value,
                'error' => $this->safeFailureMessage($exception),
            ])->save();

            throw new RuntimeException($this->safeFailureMessage($exception), previous: $exception);
        }
    }

    private function assertSendable(FinanceQuote $quote): void
    {
        if ($quote->trashed()) {
            throw new RuntimeException('لا يمكن إرسال عرض سعر محذوف.');
        }
        if ($quote->isCancelled()) {
            throw new RuntimeException('لا يمكن إرسال عرض سعر ملغى.');
        }
        if (! $quote->isIssued()) {
            throw new RuntimeException('يجب إصدار عرض السعر قبل إرساله.');
        }
    }

    private function requireWorkspaceCustomer(FinanceQuote $quote): Customer
    {
        $customer = Customer::withoutGlobalScopes()
            ->where('workspace_id', $quote->workspace_id)
            ->whereKey($quote->customer_id)
            ->first();

        if (! $customer) {
            throw new RuntimeException('العميل المحدد غير صالح ضمن مساحة العمل الحالية.');
        }

        return $customer;
    }

    private function normalizedEmail(mixed $value): string
    {
        $email = strtolower(trim((string) $value));
        if (str_contains($email, '<') && str_contains($email, '>')) {
            $email = strtolower(trim((string) Str::between($email, '<', '>')));
        }

        return $email;
    }

    private function nullablePhone(mixed $value): ?string
    {
        $phone = trim((string) ($value ?? ''));
        if ($phone === '') {
            return null;
        }

        return mb_substr($phone, 0, 32);
    }

    private function boolean(mixed $value): bool
    {
        return filter_var($value, FILTER_VALIDATE_BOOLEAN);
    }

    private function companyName(FinanceQuote $quote): string
    {
        $name = trim((string) (data_get($quote->company_snapshot, 'company_name_ar')
            ?: data_get($quote->company_snapshot, 'company_name')
            ?: ''));

        return $name !== '' ? $name : (string) config('app.name', 'HASEM');
    }

    private function defaultMessage(FinanceQuote $quote, string $companyName): string
    {
        $expiry = $quote->expiry_date?->format('Y-m-d') ?: '—';
        $total = number_format((float) $quote->total, 2).' '.($quote->currency ?: 'SAR');

        return "السلام عليكم،\nنرفق لكم عرض السعر رقم {$quote->quote_number}.\nالإجمالي: {$total}\nصلاحية العرض حتى: {$expiry}\nمع التحية،\n{$companyName}";
    }

    private function recordQuoteSentAudit(
        FinanceQuote $quote,
        string $recipient,
        int $actorUserId,
        FinanceDocumentDelivery $delivery,
    ): void {
        $actor = $actorUserId > 0
            ? User::query()->find($actorUserId)
            : null;

        $this->auditLogService->log(
            action: 'quote_sent',
            entityType: FinanceQuote::class,
            entityId: (int) $quote->id,
            oldValues: null,
            newValues: [
                'delivery_id' => $delivery->id,
                'recipient' => $recipient,
                'channel' => DocumentDeliveryChannel::Email->value,
                'quote_number' => $quote->quote_number,
            ],
            actor: $actor instanceof User ? $actor : null,
            workspaceId: (int) $quote->workspace_id,
            meta: [
                'quote_id' => $quote->id,
                'quote_number' => $quote->quote_number,
                'recipient' => $recipient,
                'channel' => DocumentDeliveryChannel::Email->value,
                'delivery_id' => $delivery->id,
            ],
        );
    }

    private function safeFailureMessage(\Throwable $exception): string
    {
        $message = trim($exception->getMessage());
        $lower = strtolower($message);
        if (
            $message === ''
            || str_contains($lower, 'api key')
            || str_contains($lower, 'resend')
            || str_contains($lower, 'stack')
            || str_contains($lower, 'password')
            || str_contains($lower, 'secret')
            || str_contains($lower, 'token')
        ) {
            return 'تعذر إرسال عرض السعر عبر البريد. يرجى المحاولة لاحقًا.';
        }

        return Str::limit($message, 500);
    }
}
