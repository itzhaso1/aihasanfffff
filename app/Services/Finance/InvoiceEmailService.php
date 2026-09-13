<?php

namespace App\Services\Finance;

use App\Enums\Finance\DocumentDeliveryChannel;
use App\Enums\Finance\DocumentDeliveryStatus;
use App\Enums\Finance\FinanceDocumentType;
use App\Models\Customer;
use App\Models\Finance\FinanceDocumentDelivery;
use App\Models\Finance\FinanceInvoice;
use App\Models\User;
use App\Services\Audit\AuditLogService;
use App\Services\Email\CentralEmailService;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use RuntimeException;

/**
 * Prepares a sales invoice email and sends it through CentralEmailService.
 *
 * Intentionally does not call Inbox, WhatsApp, SMS, GL, or ZATCA.
 * May include an already-generated shared checkout URL; it does not mark the invoice paid.
 */
class InvoiceEmailService
{
    public function __construct(
        private readonly CentralEmailService $centralEmailService,
        private readonly PdfInvoiceService $pdfInvoiceService,
        private readonly AuditLogService $auditLogService,
        private readonly InvoiceCheckoutService $invoiceCheckoutService,
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
    public function send(FinanceInvoice $invoice, array $payload, int $actorUserId): FinanceDocumentDelivery
    {
        $this->assertSendable($invoice);

        $customer = $this->requireWorkspaceCustomer($invoice);
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

        $companyName = $this->companyName($invoice);
        $customerName = trim((string) (data_get($invoice->recipient_snapshot, 'name') ?: $customer->name));
        $subject = trim((string) ($payload['subject'] ?? ''));
        if ($subject === '') {
            $subject = 'فاتورة رقم '.$invoice->invoice_number;
        }
        $message = trim((string) ($payload['message'] ?? ''));
        if ($message === '') {
            $message = $this->defaultMessage($invoice, $companyName);
        }

        $delivery = FinanceDocumentDelivery::withoutGlobalScopes()->create([
            'workspace_id' => $invoice->workspace_id,
            'document_type' => FinanceDocumentType::Invoice->value,
            'document_id' => $invoice->id,
            'channel' => DocumentDeliveryChannel::Email->value,
            'recipient' => $email,
            'recipient_phone' => $phone,
            'subject' => $subject,
            'status' => DocumentDeliveryStatus::Sending->value,
            'sent_by' => $actorUserId > 0 ? $actorUserId : null,
            'meta' => [
                'invoice_number' => $invoice->invoice_number,
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
                $binary = $this->pdfInvoiceService->renderBinary($invoice);
                $filename = 'invoice-'.$invoice->invoice_number.'.pdf';
                $attachmentPath = 'workspaces/'.$invoice->workspace_id.'/finance/invoices/emails/'.Str::uuid().'_'.$filename;
                $attachmentDisk = 'public';
                Storage::disk($attachmentDisk)->put($attachmentPath, $binary);
                $attachments[] = [
                    'storage_disk' => $attachmentDisk,
                    'storage_path' => $attachmentPath,
                    'name' => $filename,
                    'mime' => 'application/pdf',
                ];
            }

            $checkoutUrl = $this->invoiceCheckoutService->availability($invoice)->checkoutUrl;
            $lines = [
                'العميل: '.$customerName,
                'رقم الفاتورة: '.$invoice->invoice_number,
                'تاريخ الفاتورة: '.($invoice->issue_date?->format('Y-m-d') ?: '—'),
                'الإجمالي: '.number_format((float) $invoice->total, 2).' '.($invoice->currency ?: 'SAR'),
            ];
            if (is_string($checkoutUrl) && $checkoutUrl !== '') {
                $lines[] = 'رابط الدفع: '.$checkoutUrl;
            }

            $emailLog = $this->centralEmailService->send([
                'to' => [$email],
                'template' => 'invoice_email',
                'subject' => $subject,
                'workspace_id' => (int) $invoice->workspace_id,
                'attachments' => $attachments,
                'data' => [
                    'headline' => 'فاتورة رقم '.$invoice->invoice_number,
                    'intro' => $message,
                    'lines' => $lines,
                    'action_text' => $checkoutUrl ? 'دفع الفاتورة' : null,
                    'action_url' => $checkoutUrl,
                    'brand_name' => $companyName,
                    'brand_color' => (string) (data_get($invoice->pdf_snapshot, 'primary_color') ?: '#06C2A4'),
                    'footer' => 'هذه رسالة من '.$companyName.' — إرسال البريد لا يغيّر حالة الفاتورة المالية.',
                ],
                'meta' => [
                    'source' => 'finance_invoice_send',
                    'invoice_id' => $invoice->id,
                    'invoice_number' => $invoice->invoice_number,
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

            $this->recordInvoiceSentAudit($invoice, $email, $actorUserId, $delivery);

            return $delivery->fresh();
        } catch (\Throwable $exception) {
            $delivery->forceFill([
                'status' => DocumentDeliveryStatus::Failed->value,
                'error' => $this->safeFailureMessage($exception),
            ])->save();

            throw new RuntimeException($this->safeFailureMessage($exception), previous: $exception);
        }
    }

    private function assertSendable(FinanceInvoice $invoice): void
    {
        if ($invoice->trashed()) {
            throw new RuntimeException('لا يمكن إرسال فاتورة محذوفة.');
        }
        if ((string) $invoice->type !== 'sales') {
            throw new RuntimeException('يمكن إرسال فواتير المبيعات الصادرة فقط.');
        }
        if ($invoice->isCancelled()) {
            throw new RuntimeException('لا يمكن إرسال فاتورة ملغاة.');
        }
        if (! $invoice->isIssued()) {
            throw new RuntimeException('يجب إصدار الفاتورة قبل إرسالها.');
        }
    }

    private function requireWorkspaceCustomer(FinanceInvoice $invoice): Customer
    {
        $customer = Customer::withoutGlobalScopes()
            ->where('workspace_id', $invoice->workspace_id)
            ->whereKey($invoice->customer_id)
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

    private function companyName(FinanceInvoice $invoice): string
    {
        $name = trim((string) (data_get($invoice->company_snapshot, 'company_name_ar')
            ?: data_get($invoice->company_snapshot, 'company_name')
            ?: ''));

        return $name !== '' ? $name : (string) config('app.name', 'HASEM');
    }

    private function defaultMessage(FinanceInvoice $invoice, string $companyName): string
    {
        $total = number_format((float) $invoice->total, 2).' '.($invoice->currency ?: 'SAR');
        $date = $invoice->issue_date?->format('Y-m-d') ?: '—';

        return "السلام عليكم،\nنرفق لكم الفاتورة رقم {$invoice->invoice_number}.\nالتاريخ: {$date}\nالإجمالي: {$total}\nمع التحية،\n{$companyName}";
    }

    private function recordInvoiceSentAudit(
        FinanceInvoice $invoice,
        string $recipient,
        int $actorUserId,
        FinanceDocumentDelivery $delivery,
    ): void {
        $actor = $actorUserId > 0
            ? User::query()->find($actorUserId)
            : null;

        $this->auditLogService->log(
            action: 'invoice_sent',
            entityType: FinanceInvoice::class,
            entityId: (int) $invoice->id,
            oldValues: null,
            newValues: [
                'delivery_id' => $delivery->id,
                'recipient' => $recipient,
                'channel' => DocumentDeliveryChannel::Email->value,
                'invoice_number' => $invoice->invoice_number,
            ],
            actor: $actor instanceof User ? $actor : null,
            workspaceId: (int) $invoice->workspace_id,
            meta: [
                'invoice_id' => $invoice->id,
                'invoice_number' => $invoice->invoice_number,
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
            return 'تعذر إرسال الفاتورة عبر البريد. يرجى المحاولة لاحقًا.';
        }

        return Str::limit($message, 500);
    }
}
