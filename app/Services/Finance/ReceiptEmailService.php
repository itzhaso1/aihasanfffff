<?php

namespace App\Services\Finance;

use App\Enums\Finance\DocumentDeliveryChannel;
use App\Enums\Finance\DocumentDeliveryStatus;
use App\Enums\Finance\FinanceDocumentType;
use App\Models\Customer;
use App\Models\Finance\FinanceDocumentDelivery;
use App\Models\Finance\FinanceReceipt;
use App\Models\User;
use App\Services\Audit\AuditLogService;
use App\Services\Email\CentralEmailService;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use RuntimeException;

class ReceiptEmailService
{
    public function __construct(
        private readonly CentralEmailService $centralEmailService,
        private readonly PdfReceiptService $pdfReceiptService,
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
    public function send(FinanceReceipt $receipt, array $payload, int $actorUserId): FinanceDocumentDelivery
    {
        $this->assertSendable($receipt);

        $receipt->loadMissing(['payment', 'invoice', 'customer']);
        $customer = $this->requireWorkspaceCustomer($receipt);
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

        $companyName = $this->companyName($receipt);
        $customerName = trim((string) (data_get($receipt->invoice?->recipient_snapshot, 'name') ?: $customer->name));
        $subject = trim((string) ($payload['subject'] ?? ''));
        if ($subject === '') {
            $subject = 'إيصال رقم '.$receipt->receipt_number;
        }
        $message = trim((string) ($payload['message'] ?? ''));
        if ($message === '') {
            $message = $this->defaultMessage($receipt, $companyName);
        }

        $payment = $receipt->payment;
        $amount = round((float) ($payment?->amount ?? $receipt->amount), 2);

        $delivery = FinanceDocumentDelivery::withoutGlobalScopes()->create([
            'workspace_id' => $receipt->workspace_id,
            'document_type' => FinanceDocumentType::Receipt->value,
            'document_id' => $receipt->id,
            'channel' => DocumentDeliveryChannel::Email->value,
            'recipient' => $email,
            'recipient_phone' => $phone,
            'subject' => $subject,
            'status' => DocumentDeliveryStatus::Sending->value,
            'sent_by' => $actorUserId > 0 ? $actorUserId : null,
            'meta' => [
                'receipt_number' => $receipt->receipt_number,
                'payment_id' => $receipt->payment_id,
                'invoice_id' => $receipt->invoice_id,
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
                $binary = $this->pdfReceiptService->renderBinary($receipt);
                $filename = 'receipt-'.$receipt->receipt_number.'.pdf';
                $attachmentPath = 'workspaces/'.$receipt->workspace_id.'/finance/receipts/emails/'.Str::uuid().'_'.$filename;
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
                'template' => 'receipt_email',
                'subject' => $subject,
                'workspace_id' => (int) $receipt->workspace_id,
                'attachments' => $attachments,
                'data' => [
                    'headline' => 'إيصال رقم '.$receipt->receipt_number,
                    'intro' => $message,
                    'lines' => [
                        'العميل: '.$customerName,
                        'رقم الإيصال: '.$receipt->receipt_number,
                        'رقم الفاتورة: '.($receipt->invoice?->invoice_number ?: '—'),
                        'تاريخ الدفع: '.($payment?->payment_date?->format('Y-m-d') ?: ($receipt->payment_date?->format('Y-m-d') ?: '—')),
                        'المبلغ: '.number_format($amount, 2).' '.($receipt->currency ?: 'SAR'),
                    ],
                    'brand_name' => $companyName,
                    'brand_color' => (string) (data_get($receipt->invoice?->pdf_snapshot, 'primary_color') ?: '#06C2A4'),
                    'footer' => 'هذه رسالة من '.$companyName.' — الإيصال مرتبط بالدفعة المالية المسجّلة وليس بعملية دفع جديدة.',
                ],
                'meta' => [
                    'source' => 'finance_receipt_send',
                    'receipt_id' => $receipt->id,
                    'receipt_number' => $receipt->receipt_number,
                    'payment_id' => $receipt->payment_id,
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

            $this->recordReceiptSentAudit($receipt, $email, $actorUserId, $delivery);

            return $delivery->fresh();
        } catch (\Throwable $exception) {
            $delivery->forceFill([
                'status' => DocumentDeliveryStatus::Failed->value,
                'error' => $this->safeFailureMessage($exception),
            ])->save();

            throw new RuntimeException($this->safeFailureMessage($exception), previous: $exception);
        }
    }

    private function assertSendable(FinanceReceipt $receipt): void
    {
        if ($receipt->isVoided()) {
            throw new RuntimeException('لا يمكن إرسال إيصال ملغى.');
        }
        if (! $receipt->isPosted()) {
            throw new RuntimeException('يمكن إرسال الإيصالات المعتمدة فقط.');
        }
    }

    private function requireWorkspaceCustomer(FinanceReceipt $receipt): Customer
    {
        $customer = Customer::withoutGlobalScopes()
            ->where('workspace_id', $receipt->workspace_id)
            ->whereKey($receipt->customer_id)
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

    private function companyName(FinanceReceipt $receipt): string
    {
        $name = trim((string) (data_get($receipt->invoice?->company_snapshot, 'company_name_ar')
            ?: data_get($receipt->invoice?->company_snapshot, 'company_name')
            ?: ''));

        return $name !== '' ? $name : (string) config('app.name', 'HASEM');
    }

    private function defaultMessage(FinanceReceipt $receipt, string $companyName): string
    {
        $amount = number_format((float) ($receipt->payment?->amount ?? $receipt->amount), 2).' '.($receipt->currency ?: 'SAR');

        return "السلام عليكم،\nنرفق لكم إيصال الدفع رقم {$receipt->receipt_number}.\nالمبلغ: {$amount}\nمع التحية،\n{$companyName}";
    }

    private function recordReceiptSentAudit(
        FinanceReceipt $receipt,
        string $recipient,
        int $actorUserId,
        FinanceDocumentDelivery $delivery,
    ): void {
        $actor = $actorUserId > 0
            ? User::query()->find($actorUserId)
            : null;

        $this->auditLogService->log(
            action: 'receipt_sent',
            entityType: FinanceReceipt::class,
            entityId: (int) $receipt->id,
            oldValues: null,
            newValues: [
                'delivery_id' => $delivery->id,
                'recipient' => $recipient,
                'channel' => DocumentDeliveryChannel::Email->value,
                'receipt_number' => $receipt->receipt_number,
            ],
            actor: $actor instanceof User ? $actor : null,
            workspaceId: (int) $receipt->workspace_id,
            meta: [
                'receipt_id' => $receipt->id,
                'receipt_number' => $receipt->receipt_number,
                'payment_id' => $receipt->payment_id,
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
            return 'تعذر إرسال الإيصال عبر البريد. يرجى المحاولة لاحقًا.';
        }

        return Str::limit($message, 500);
    }
}
