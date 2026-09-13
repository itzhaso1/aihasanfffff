<?php

namespace App\Services\Contracts;

use App\Models\Contract\Contract;
use App\Models\EmailAccount;
use App\Models\EmailAttachment;
use App\Models\EmailMessage;
use App\Services\Email\CentralEmailService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use RuntimeException;

class ContractEmailService
{
    public function __construct(
        private readonly ContractPdfService $contractPdfService,
        private readonly CentralEmailService $centralEmailService,
    ) {}

    /**
     * @param  array{email_account_id:int,recipient:string,cc?:string|null,subject?:string|null,message?:string|null}  $payload
     */
    public function sendActivationEmail(Contract $contract, array $payload): EmailMessage
    {
        $toRecipients = $this->parseEmails($payload['recipient']);
        if ($toRecipients === []) {
            throw new RuntimeException('يرجى إدخال بريد مستلم صالح.');
        }

        $ccRecipients = $this->parseEmails((string) ($payload['cc'] ?? ''));
        $account = EmailAccount::query()->findOrFail((int) $payload['email_account_id']);
        $allRecipients = array_values(array_unique(array_merge($toRecipients, $ccRecipients)));

        $message = DB::transaction(function () use ($contract, $payload, $allRecipients, $account): EmailMessage {
            $message = EmailMessage::query()->create([
                'workspace_id' => $contract->workspace_id,
                'email_account_id' => $account->id,
                'sender' => (string) config('email_templates.default_from_address'),
                'recipient' => implode(', ', $allRecipients),
                'subject' => (string) ($payload['subject'] ?? ('تفعيل العقد '.$contract->contract_number)),
                'body' => (string) ($payload['message'] ?? 'تم تفعيل العقد وإرفاق نسخة PDF.'),
                'type' => 'outbound',
                'delivery_status' => 'sending',
                'message_id' => '<'.Str::uuid().'@contracts.hasem>',
                'thread_key' => Str::uuid()->toString(),
            ]);

            $pdfBinary = $this->contractPdfService->renderBinary($contract);
            $filename = 'contract-'.$contract->contract_number.'.pdf';
            $storedPath = 'workspaces/'.$contract->workspace_id.'/contracts/emails/'.Str::uuid().'_'.$filename;
            Storage::disk('local')->put($storedPath, $pdfBinary);

            EmailAttachment::query()->create([
                'workspace_id' => $contract->workspace_id,
                'message_id' => $message->id,
                'file_path' => $storedPath,
                'disk' => 'local',
                'file_type' => 'application/pdf',
                'file_size' => strlen($pdfBinary),
            ]);

            return $message->fresh(['account', 'attachments']);
        });

        try {
            $emailLog = $this->centralEmailService->send([
                'to' => $toRecipients,
                'cc' => $ccRecipients,
                'template' => 'contract_email',
                'subject' => $message->subject,
                'workspace_id' => $contract->workspace_id,
                'email_message_id' => $message->id,
                'attachments' => [[
                    'storage_disk' => $message->attachments->first()?->storageDisk() ?: 'local',
                    'storage_path' => (string) $message->attachments->first()?->file_path,
                    'name' => 'contract-'.$contract->contract_number.'.pdf',
                    'mime' => 'application/pdf',
                ]],
                'data' => [
                    'headline' => 'تفعيل العقد '.$contract->contract_number,
                    'intro' => (string) $message->body,
                    'lines' => [
                        'عنوان العقد: '.$contract->title,
                        'قيمة العقد: '.number_format((float) $contract->value, 2).' '.$contract->currency,
                    ],
                    'brand_name' => $account->name,
                    'brand_color' => $account->brand_color ?: '#0f172a',
                ],
                'meta' => [
                    'source' => 'contract_activation',
                    'contract_id' => $contract->id,
                ],
            ]);

            $message->forceFill([
                'sender' => (string) config('email_templates.default_from_address'),
                'delivery_status' => 'sent',
                'delivery_error' => null,
                'delivered_at' => now(),
                'message_id' => $emailLog->provider_message_id ?: $message->message_id,
            ])->save();
        } catch (\Throwable $exception) {
            $message->forceFill([
                'sender' => (string) config('email_templates.default_from_address'),
                'delivery_status' => 'failed',
                'delivery_error' => 'Email delivery failed.',
            ])->save();

            throw new RuntimeException('فشل إرسال العقد عبر البريد.', previous: $exception);
        }

        return $message->fresh(['attachments']);
    }

    /**
     * @return array<int,string>
     */
    private function parseEmails(string $value): array
    {
        return collect(preg_split('/[\r\n,;]+/', $value) ?: [])
            ->map(fn (string $part): string => strtolower(trim($part)))
            ->filter(fn (string $candidate): bool => filter_var($candidate, FILTER_VALIDATE_EMAIL) !== false)
            ->unique()
            ->values()
            ->all();
    }
}
