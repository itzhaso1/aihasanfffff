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

class InvoiceReminderEmailService
{
    public function __construct(
        private readonly CentralEmailService $centralEmailService,
        private readonly PdfInvoiceService $pdfInvoiceService,
        private readonly AuditLogService $auditLogService,
    ) {}

    /**
     * @param  array{
     *     email?:string,
     *     phone?:string|null,
     *     subject?:string|null,
     *     message?:string|null,
     *     attach_pdf?:bool|int|string|null,
     *     source?:string|null
     * }  $payload
     */
    public function send(FinanceInvoice $invoice, array $payload, int $actorUserId): FinanceDocumentDelivery
    {
        $this->assertRemindable($invoice);

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
        $source = trim((string) ($payload['source'] ?? 'manual'));
        if ($source === '') {
            $source = 'manual';
        }

        $companyName = $this->companyName($invoice);
        $customerName = trim((string) (data_get($invoice->recipient_snapshot, 'name') ?: $customer->name));
        $subject = trim((string) ($payload['subject'] ?? ''));
        if ($subject === '') {
            $subject = 'تذكير بفاتورة رقم '.$invoice->invoice_number;
        }
        $message = trim((string) ($payload['message'] ?? ''));
        if ($message === '') {
            $message = $this->defaultMessage($invoice, $companyName);
        }

        $delivery = FinanceDocumentDelivery::withoutGlobalScopes()->create([
            'workspace_id' => $invoice->workspace_id,
            'document_type' => FinanceDocumentType::InvoiceReminder->value,
            'document_id' => $invoice->id,
            'channel' => DocumentDeliveryChannel::Email->value,
            'recipient' => $email,
            'recipient_phone' => $phone,
            'subject' => $subject,
            'status' => DocumentDeliveryStatus::Sending->value,
            'sent_by' => $actorUserId > 0 ? $actorUserId : null,
            'meta' => [
                'kind' => 'reminder',
                'source' => $source,
                'invoice_number' => $invoice->invoice_number,
                'customer_id' => $customer->id,
                'customer_name' => $customerName,
                'attach_pdf' => $attachPdf,
                'amount_due' => (float) $invoice->amount_due,
            ],
        ]);

        try {
            $attachments = [];
            $attachmentDisk = null;
            $attachmentPath = null;
            if ($attachPdf) {
                $binary = $this->pdfInvoiceService->renderBinary($invoice);
                $filename = 'invoice-'.$invoice->invoice_number.'.pdf';
                $attachmentPath = 'workspaces/'.$invoice->workspace_id.'/finance/invoices/reminders/'.Str::uuid().'_'.$filename;
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
                'template' => 'invoice_reminder_email',
                'subject' => $subject,
                'workspace_id' => (int) $invoice->workspace_id,
                'attachments' => $attachments,
                'data' => [
                    'headline' => 'تذكير بفاتورة رقم '.$invoice->invoice_number,
                    'intro' => $message,
                    'lines' => [
                        'العميل: '.$customerName,
                        'رقم الفاتورة: '.$invoice->invoice_number,
                        'تاريخ الاستحقاق: '.($invoice->due_date?->format('Y-m-d') ?: '—'),
                        'المتبقي: '.number_format((float) $invoice->amount_due, 2).' '.($invoice->currency ?: 'SAR'),
                    ],
                    'brand_name' => $companyName,
                    'brand_color' => (string) (data_get($invoice->pdf_snapshot, 'primary_color') ?: '#06C2A4'),
                    'footer' => 'هذه رسالة تذكير من '.$companyName.' — التذكير لا يغيّر حالة الفاتورة المالية.',
                ],
                'meta' => [
                    'source' => 'finance_invoice_reminder',
                    'invoice_id' => $invoice->id,
                    'invoice_number' => $invoice->invoice_number,
                    'delivery_id' => $delivery->id,
                    'reminder_source' => $source,
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

            $invoice->forceFill([
                'last_reminder_sent_at' => now(),
            ])->save();

            $this->recordReminderSentAudit($invoice, $email, $actorUserId, $delivery, $source);

            return $delivery->fresh();
        } catch (\Throwable $exception) {
            $delivery->forceFill([
                'status' => DocumentDeliveryStatus::Failed->value,
                'error' => $this->safeFailureMessage($exception),
            ])->save();

            throw new RuntimeException($this->safeFailureMessage($exception), previous: $exception);
        }
    }

    public function assertRemindable(FinanceInvoice $invoice): void
    {
        if ($invoice->trashed()) {
            throw new RuntimeException('لا يمكن تذكير فاتورة محذوفة.');
        }
        if ((string) $invoice->type !== 'sales') {
            throw new RuntimeException('تذكير البريد متاح لفواتير المبيعات الصادرة فقط.');
        }
        if ($invoice->isCancelled()) {
            throw new RuntimeException('لا يمكن إرسال تذكير لفاتورة ملغاة.');
        }
        if (! $invoice->isIssued()) {
            throw new RuntimeException('لا يمكن إرسال تذكير لمسودة.');
        }
        if ((float) $invoice->amount_due <= InvoiceStateService::PAYMENT_TOLERANCE) {
            throw new RuntimeException('لا يمكن إرسال تذكير لفاتورة مدفوعة بالكامل.');
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
        $due = $invoice->due_date?->format('Y-m-d') ?: '—';
        $amount = number_format((float) $invoice->amount_due, 2).' '.($invoice->currency ?: 'SAR');

        return "السلام عليكم،\nتذكير بلطف بأن الفاتورة رقم {$invoice->invoice_number} ما زالت مستحقة.\nتاريخ الاستحقاق: {$due}\nالمتبقي: {$amount}\nمع التحية،\n{$companyName}";
    }

    private function recordReminderSentAudit(
        FinanceInvoice $invoice,
        string $recipient,
        int $actorUserId,
        FinanceDocumentDelivery $delivery,
        string $source,
    ): void {
        $actor = $actorUserId > 0
            ? User::query()->find($actorUserId)
            : null;

        $this->auditLogService->log(
            action: 'invoice_reminder_sent',
            entityType: FinanceInvoice::class,
            entityId: (int) $invoice->id,
            oldValues: null,
            newValues: [
                'delivery_id' => $delivery->id,
                'recipient' => $recipient,
                'channel' => DocumentDeliveryChannel::Email->value,
                'invoice_number' => $invoice->invoice_number,
                'source' => $source,
            ],
            actor: $actor instanceof User ? $actor : null,
            workspaceId: (int) $invoice->workspace_id,
            meta: [
                'invoice_id' => $invoice->id,
                'invoice_number' => $invoice->invoice_number,
                'recipient' => $recipient,
                'channel' => DocumentDeliveryChannel::Email->value,
                'delivery_id' => $delivery->id,
                'source' => $source,
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
            return 'تعذر إرسال تذكير الفاتورة عبر البريد. يرجى المحاولة لاحقًا.';
        }

        return Str::limit($message, 500);
    }
}
