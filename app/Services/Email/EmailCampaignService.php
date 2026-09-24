<?php

namespace App\Services\Email;

use App\Jobs\Email\ProcessEmailCampaignJob;
use App\Models\EmailAccount;
use App\Models\EmailCampaign;
use App\Models\EmailCampaignRecipient;
use App\Models\EmailContact;
use App\Models\EmailContactGroup;
use App\Models\EmailMessage;
use App\Models\User;
use App\Models\Workspace;
use App\Services\Feature\FeatureAccessService;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use RuntimeException;

class EmailCampaignService
{
    public function __construct(
        private readonly FeatureAccessService $featureAccessService,
        private readonly EmailContactService $emailContactService,
        private readonly WorkspaceEmailSender $workspaceEmailSender,
    ) {}

    /**
     * @param  array{
     *     email_account_id:int,
     *     subject:string,
     *     body:string,
     *     contact_ids?:array<int,int>|null,
     *     group_ids?:array<int,int>|null,
     *     all_contacts?:bool,
     *     emails?:array<int,string>|null,
     *     confirm_all?:bool
     * }  $data
     */
    public function createAndQueue(Workspace $workspace, User $actor, array $data): EmailCampaign
    {
        $account = EmailAccount::query()
            ->where('workspace_id', $workspace->id)
            ->findOrFail($data['email_account_id']);

        $recipients = $this->resolveRecipients($workspace, $data);

        if ($recipients->isEmpty()) {
            throw ValidationException::withMessages([
                'recipients' => ['يرجى تحديد مستلم واحد على الأقل.'],
            ]);
        }

        if (! empty($data['all_contacts']) && empty($data['confirm_all'])) {
            throw ValidationException::withMessages([
                'confirm_all' => ['يلزم تأكيد الإرسال لجميع جهات الاتصال.'],
            ]);
        }

        $this->featureAccessService->assertCanUse(
            $actor,
            $workspace,
            'email',
            'email_sends',
            $recipients->count(),
        );

        $campaign = DB::transaction(function () use ($workspace, $actor, $account, $data, $recipients): EmailCampaign {
            $campaign = EmailCampaign::query()->create([
                'workspace_id' => $workspace->id,
                'user_id' => $actor->id,
                'email_account_id' => $account->id,
                'subject' => $data['subject'],
                'body' => $data['body'],
                'status' => EmailCampaign::STATUS_QUEUED,
                'recipient_count' => $recipients->count(),
                'sent_count' => 0,
                'failed_count' => 0,
                'queued_at' => now(),
            ]);

            foreach ($recipients as $recipient) {
                EmailCampaignRecipient::query()->create([
                    'workspace_id' => $workspace->id,
                    'email_campaign_id' => $campaign->id,
                    'email_contact_id' => $recipient['contact_id'],
                    'email' => $recipient['email'],
                    'name' => $recipient['name'],
                    'status' => EmailCampaignRecipient::STATUS_PENDING,
                ]);
            }

            return $campaign;
        });

        ProcessEmailCampaignJob::dispatch($campaign->id, (int) $campaign->workspace_id);

        return $campaign->load(['account:id,name,email'])->loadCount('recipients');
    }

    public function cancel(EmailCampaign $campaign, User $actor): EmailCampaign
    {
        if (! in_array($campaign->status, [
            EmailCampaign::STATUS_DRAFT,
            EmailCampaign::STATUS_QUEUED,
            EmailCampaign::STATUS_SENDING,
        ], true)) {
            throw new RuntimeException('لا يمكن إلغاء هذه الحملة.');
        }

        $campaign->forceFill([
            'status' => EmailCampaign::STATUS_CANCELLED,
            'completed_at' => now(),
        ])->save();

        EmailCampaignRecipient::query()
            ->where('email_campaign_id', $campaign->id)
            ->where('status', EmailCampaignRecipient::STATUS_PENDING)
            ->update(['status' => EmailCampaignRecipient::STATUS_SKIPPED]);

        return $campaign->refresh();
    }

    public function findForWorkspace(Workspace $workspace, int $campaignId): EmailCampaign
    {
        return EmailCampaign::query()
            ->where('workspace_id', $workspace->id)
            ->with(['account:id,name,email'])
            ->withCount([
                'recipients',
                'recipients as pending_count' => fn ($q) => $q->where('status', EmailCampaignRecipient::STATUS_PENDING),
            ])
            ->findOrFail($campaignId);
    }

    /**
     * Send one campaign recipient privately (one EmailMessage). Consumes email_sends +1 on success.
     */
    public function sendRecipient(EmailCampaignRecipient $recipient): void
    {
        $recipient = EmailCampaignRecipient::withoutGlobalScopes()
            ->whereKey($recipient->id)
            ->firstOrFail();

        $campaign = EmailCampaign::withoutGlobalScopes()
            ->with(['account'])
            ->findOrFail($recipient->email_campaign_id);

        if ($campaign->status === EmailCampaign::STATUS_CANCELLED) {
            $recipient->forceFill([
                'status' => EmailCampaignRecipient::STATUS_SKIPPED,
            ])->save();

            return;
        }

        if ($recipient->status !== EmailCampaignRecipient::STATUS_PENDING) {
            return;
        }

        $workspace = Workspace::query()->findOrFail($campaign->workspace_id);
        $account = $campaign->account;

        if (! $account) {
            throw new RuntimeException('حساب البريد غير موجود.');
        }

        $message = $this->resolveCampaignOutboundMessage($recipient, $campaign, $account);

        if ($message->delivery_status === 'sent') {
            $this->finalizeRecipientSend($recipient, $campaign, $workspace, $message);

            return;
        }

        $classifier = app(CustomerMailboxErrorClassifier::class);

        try {
            $message->forceFill([
                'delivery_status' => 'sending',
                'delivery_error' => null,
            ])->save();

            $this->workspaceEmailSender->send($message);
            $this->finalizeRecipientSend($recipient, $campaign, $workspace, $message);
        } catch (\Throwable $exception) {
            $safeError = $classifier->safeStoredError($exception, 'send');

            $message->forceFill([
                'delivery_status' => 'failed',
                'delivery_error' => $safeError,
            ])->save();

            if ($classifier->isRetryable($exception)) {
                throw $exception;
            }

            $recipient->forceFill([
                'status' => EmailCampaignRecipient::STATUS_FAILED,
                'email_message_id' => $message->id,
                'error_message' => $safeError,
            ])->save();

            $campaign->increment('failed_count');

            throw $exception;
        }
    }

    private function resolveCampaignOutboundMessage(
        EmailCampaignRecipient $recipient,
        EmailCampaign $campaign,
        EmailAccount $account,
    ): EmailMessage {
        if ($recipient->email_message_id) {
            $existing = EmailMessage::withoutGlobalScopes()
                ->whereKey($recipient->email_message_id)
                ->where('workspace_id', $campaign->workspace_id)
                ->first();

            if ($existing) {
                return $existing;
            }
        }

        $message = EmailMessage::withoutGlobalScopes()->create([
            'workspace_id' => $campaign->workspace_id,
            'email_account_id' => $account->id,
            'sender' => $account->email,
            'recipient' => $recipient->email,
            'subject' => $campaign->subject,
            'body' => $campaign->body,
            'type' => 'outbound',
            'delivery_status' => 'pending',
            'message_id' => '<'.Str::uuid().'@'.Str::after((string) $account->email, '@').'>',
            'thread_key' => Str::uuid()->toString(),
        ]);

        $recipient->forceFill(['email_message_id' => $message->id])->save();

        return $message;
    }

    private function finalizeRecipientSend(
        EmailCampaignRecipient $recipient,
        EmailCampaign $campaign,
        Workspace $workspace,
        EmailMessage $message,
    ): void {
        if ($recipient->status === EmailCampaignRecipient::STATUS_SENT) {
            return;
        }

        $message->forceFill([
            'delivery_status' => 'sent',
            'delivered_at' => $message->delivered_at ?? now(),
            'delivery_error' => null,
        ])->save();

        $recipient->forceFill([
            'status' => EmailCampaignRecipient::STATUS_SENT,
            'email_message_id' => $message->id,
            'sent_at' => now(),
            'error_message' => null,
        ])->save();

        $campaign->increment('sent_count');

        // Consume after successful send — campaigns own metering (not WorkspaceEmailSender).
        $this->featureAccessService->consumeUsage($workspace, 'email_sends', 1, enforce: false);
    }

    public function refreshCampaignStatus(EmailCampaign $campaign): EmailCampaign
    {
        $campaign = EmailCampaign::withoutGlobalScopes()->findOrFail($campaign->id);

        if (in_array($campaign->status, [
            EmailCampaign::STATUS_CANCELLED,
            EmailCampaign::STATUS_COMPLETED,
            EmailCampaign::STATUS_PARTIAL,
            EmailCampaign::STATUS_FAILED,
        ], true)) {
            return $campaign;
        }

        $pending = EmailCampaignRecipient::query()
            ->where('email_campaign_id', $campaign->id)
            ->where('status', EmailCampaignRecipient::STATUS_PENDING)
            ->exists();

        if ($pending) {
            return $campaign;
        }

        $sent = (int) $campaign->sent_count;
        $failed = (int) $campaign->failed_count;

        $status = match (true) {
            $sent > 0 && $failed === 0 => EmailCampaign::STATUS_COMPLETED,
            $sent > 0 && $failed > 0 => EmailCampaign::STATUS_PARTIAL,
            $sent === 0 && $failed > 0 => EmailCampaign::STATUS_FAILED,
            default => EmailCampaign::STATUS_COMPLETED,
        };

        $campaign->forceFill([
            'status' => $status,
            'completed_at' => now(),
        ])->save();

        return $campaign->refresh();
    }

    /**
     * @param  array{
     *     contact_ids?:array<int,int>|null,
     *     group_ids?:array<int,int>|null,
     *     all_contacts?:bool,
     *     emails?:array<int,string>|null
     * }  $data
     * @return Collection<int, array{email:string,name:?string,contact_id:?int}>
     */
    private function resolveRecipients(Workspace $workspace, array $data): Collection
    {
        /** @var array<string, array{email:string,name:?string,contact_id:?int}> $map */
        $map = [];

        $add = function (string $email, ?string $name = null, ?int $contactId = null) use (&$map): void {
            $normalized = $this->emailContactService->normalizeEmail($email);
            if ($normalized === '' || isset($map[$normalized])) {
                return;
            }

            $map[$normalized] = [
                'email' => $normalized,
                'name' => $name,
                'contact_id' => $contactId,
            ];
        };

        if (! empty($data['all_contacts'])) {
            EmailContact::query()
                ->where('workspace_id', $workspace->id)
                ->orderBy('id')
                ->each(function (EmailContact $contact) use ($add): void {
                    $add($contact->normalized_email ?: $contact->email, $contact->name, $contact->id);
                });
        }

        foreach ($data['contact_ids'] ?? [] as $contactId) {
            $contact = EmailContact::query()
                ->where('workspace_id', $workspace->id)
                ->find((int) $contactId);
            if ($contact) {
                $add($contact->normalized_email ?: $contact->email, $contact->name, $contact->id);
            }
        }

        foreach ($data['group_ids'] ?? [] as $groupId) {
            $group = EmailContactGroup::query()
                ->where('workspace_id', $workspace->id)
                ->with('contacts')
                ->find((int) $groupId);
            if (! $group) {
                continue;
            }
            foreach ($group->contacts as $contact) {
                $add($contact->normalized_email ?: $contact->email, $contact->name, $contact->id);
            }
        }

        foreach ($data['emails'] ?? [] as $email) {
            $normalized = $this->emailContactService->normalizeEmail((string) $email);
            if ($normalized === '') {
                continue;
            }
            $contact = EmailContact::withoutGlobalScopes()
                ->where('workspace_id', $workspace->id)
                ->where('normalized_email', $normalized)
                ->first();
            $add($normalized, $contact?->name, $contact?->id);
        }

        return collect(array_values($map));
    }
}
