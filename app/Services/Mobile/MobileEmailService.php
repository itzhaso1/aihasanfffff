<?php

namespace App\Services\Mobile;

use App\Models\EmailAccount;
use App\Models\EmailMessage;
use App\Models\User;
use App\Models\Workspace;
use App\Services\Email\WorkspaceEmailSender;
use App\Services\Feature\FeatureAccessService;
use Illuminate\Contracts\Pagination\CursorPaginator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use RuntimeException;

class MobileEmailService
{
    public function __construct(
        private readonly WorkspaceEmailSender $workspaceEmailSender,
        private readonly FeatureAccessService $featureAccessService,
    ) {}

    /**
     * @param  array{search?:string,per_page?:int,email_account_id?:int}  $filters
     */
    public function inbox(Workspace $workspace, array $filters = []): CursorPaginator
    {
        return $this->listMessages($workspace, 'inbound', null, $filters);
    }

    /**
     * @param  array{search?:string,per_page?:int,email_account_id?:int}  $filters
     */
    public function sent(Workspace $workspace, array $filters = []): CursorPaginator
    {
        return $this->listMessages($workspace, 'outbound', null, $filters);
    }

    /**
     * @param  array{search?:string,per_page?:int,email_account_id?:int}  $filters
     */
    public function drafts(Workspace $workspace, array $filters = []): CursorPaginator
    {
        if (! Schema::hasColumn('email_messages', 'folder')) {
            return EmailMessage::query()->whereRaw('1 = 0')->cursorPaginate(1);
        }

        return $this->listMessages($workspace, null, 'draft', $filters);
    }

    public function show(EmailMessage $emailMessage): EmailMessage
    {
        if (
            Schema::hasColumn('email_messages', 'read_at')
            && $emailMessage->type === 'inbound'
            && $emailMessage->read_at === null
        ) {
            $emailMessage->update(['read_at' => now()]);
        }

        return $emailMessage->load(['account', 'attachments']);
    }

    public function markRead(EmailMessage $emailMessage): EmailMessage
    {
        if (Schema::hasColumn('email_messages', 'read_at')) {
            $emailMessage->update(['read_at' => now()]);
        }

        return $emailMessage->refresh();
    }

    public function toggleStar(EmailMessage $emailMessage): EmailMessage
    {
        if (! Schema::hasColumn('email_messages', 'starred_at')) {
            return $emailMessage;
        }

        $emailMessage->update([
            'starred_at' => $emailMessage->starred_at ? null : now(),
        ]);

        return $emailMessage->refresh();
    }

    /**
     * @param  array{
     *     email_account_id:int,
     *     to:string,
     *     subject?:string,
     *     body:string,
     *     reply_to_message_id?:int
     * }  $data
     */
    public function send(Workspace $workspace, User $actor, array $data): EmailMessage
    {
        $emailAccount = EmailAccount::query()->findOrFail($data['email_account_id']);
        $replyToMessage = null;

        if (! empty($data['reply_to_message_id'])) {
            $replyToMessage = EmailMessage::query()->findOrFail($data['reply_to_message_id']);
            if ((int) $replyToMessage->email_account_id !== (int) $emailAccount->id) {
                throw new RuntimeException('رسالة الرد لا تنتمي لنفس حساب البريد.');
            }
        }

        $recipients = collect(explode(',', (string) $data['to']))
            ->map(fn (string $email): string => trim($email))
            ->filter()
            ->unique()
            ->values()
            ->all();

        if ($recipients === []) {
            throw new RuntimeException('يرجى تحديد مستلم واحد على الأقل.');
        }

        // Entitlement check before send (metered per recipient). Campaigns meter separately.
        $this->featureAccessService->assertCanUse(
            $actor,
            $workspace,
            'email',
            'email_sends',
            count($recipients),
        );

        $threadKey = $replyToMessage?->thread_key ?: ($replyToMessage?->message_id ?: Str::uuid()->toString());

        $message = DB::transaction(function () use ($workspace, $emailAccount, $data, $replyToMessage, $threadKey, $recipients): EmailMessage {
            return EmailMessage::query()->create([
                'workspace_id' => $workspace->id,
                'email_account_id' => $emailAccount->id,
                'sender' => $emailAccount->email,
                'recipient' => implode(', ', $recipients),
                'subject' => $data['subject'] ?? '(بدون عنوان)',
                'body' => $data['body'],
                'type' => 'outbound',
                'delivery_status' => 'sending',
                'message_id' => '<'.Str::uuid().'@'.Str::after($emailAccount->email, '@').'>',
                'in_reply_to' => $replyToMessage?->message_id,
                'thread_key' => $threadKey,
            ]);
        });

        $this->workspaceEmailSender->send($message);

        // Consume after successful send — not in WorkspaceEmailSender (avoids double-count with campaigns).
        $this->featureAccessService->consumeUsage($workspace, 'email_sends', count($recipients), enforce: false);

        return $message->refresh()->load(['account', 'attachments']);
    }

    /**
     * @param  array{search?:string,per_page?:int,email_account_id?:int}  $filters
     */
    private function listMessages(
        Workspace $workspace,
        ?string $type,
        ?string $folder,
        array $filters = [],
    ): CursorPaginator {
        $perPage = max(1, min(50, (int) ($filters['per_page'] ?? 20)));
        $search = trim((string) ($filters['search'] ?? ''));
        $accountId = (int) ($filters['email_account_id'] ?? 0);

        $query = EmailMessage::query()
            ->with(['account:id,name,email'])
            ->when($type !== null, fn (Builder $q) => $q->where('type', $type))
            ->when(
                $folder !== null && Schema::hasColumn('email_messages', 'folder'),
                fn (Builder $q) => $q->where('folder', $folder),
            )
            ->when($accountId > 0, fn (Builder $q) => $q->where('email_account_id', $accountId))
            ->when($search !== '', function (Builder $q) use ($search): void {
                $q->where(function (Builder $inner) use ($search): void {
                    $inner->where('subject', 'like', '%'.$search.'%')
                        ->orWhere('sender', 'like', '%'.$search.'%')
                        ->orWhere('recipient', 'like', '%'.$search.'%')
                        ->orWhere('body', 'like', '%'.$search.'%');
                });
            })
            ->orderByDesc('id');

        return $query->cursorPaginate($perPage);
    }
}
