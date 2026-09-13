<?php

namespace App\Http\Controllers\Workspace;

use App\Http\Controllers\Controller;
use App\Http\Controllers\Workspace\Concerns\InteractsWithWorkspace;
use App\Jobs\SyncEmailInboxJob;
use App\Models\EmailAccount;
use App\Models\EmailAttachment;
use App\Models\EmailContact;
use App\Models\EmailLog;
use App\Models\EmailMessage;
use App\Services\Email\WorkspaceEmailSender;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

class EmailController extends Controller
{
    use InteractsWithWorkspace;

    public function index(Request $request): RedirectResponse
    {
        return redirect()->route('workspace.emails.inbox', $this->forwardListFilters($request));
    }

    public function inbox(Request $request): View
    {
        $accounts = EmailAccount::query()->latest('id')->get();
        $currentAccount = $this->resolveCurrentAccount($request, $accounts);
        $search = trim((string) $request->string('search', ''));

        $messages = $this->buildMessagesQuery($currentAccount, 'inbound', $search)
            ->paginate(20)
            ->withQueryString();

        return view('workspace.emails.inbox', [
            'accounts' => $accounts,
            'currentAccount' => $currentAccount,
            'search' => $search,
            'messages' => $messages,
        ]);
    }

    public function sent(Request $request): View
    {
        $accounts = EmailAccount::query()->latest('id')->get();
        $currentAccount = $this->resolveCurrentAccount($request, $accounts);
        $search = trim((string) $request->string('search', ''));

        $messages = $this->buildMessagesQuery($currentAccount, 'outbound', $search)
            ->paginate(20)
            ->withQueryString();

        return view('workspace.emails.sent', [
            'accounts' => $accounts,
            'currentAccount' => $currentAccount,
            'search' => $search,
            'messages' => $messages,
        ]);
    }

    public function showMessage(Request $request, EmailMessage $emailMessage): View
    {
        $emailMessage->load(['account', 'attachments']);
        $threadKey = $emailMessage->thread_key ?: ($emailMessage->in_reply_to ?: $emailMessage->message_id ?: (string) $emailMessage->id);
        $threadMessages = EmailMessage::query()
            ->with(['attachments', 'account'])
            ->where('email_account_id', $emailMessage->email_account_id)
            ->when($threadKey, function ($query) use ($threadKey, $emailMessage): void {
                $query->where(function ($threadQuery) use ($threadKey, $emailMessage): void {
                    $threadQuery
                        ->where('thread_key', $threadKey)
                        ->orWhere('message_id', $threadKey)
                        ->orWhere('in_reply_to', $threadKey)
                        ->orWhere('id', $emailMessage->id);
                });
            })
            ->orderBy('created_at')
            ->get();

        return view('workspace.emails.show', [
            'message' => $emailMessage,
            'threadMessages' => $threadMessages,
            'returnTo' => $this->resolveReturnTo($request),
            'accountId' => $request->integer('account_id') ?: $emailMessage->email_account_id,
            'search' => trim((string) $request->string('search', '')),
        ]);
    }

    public function showLog(EmailLog $emailLog): JsonResponse
    {
        $this->assertSameWorkspace($emailLog->workspace_id);

        return response()->json([
            'data' => [
                'id' => $emailLog->id,
                'workspace_id' => $emailLog->workspace_id,
                'recipient' => $emailLog->recipient,
                'subject' => $emailLog->subject,
                'status' => $emailLog->status,
                'template' => $emailLog->template,
            ],
        ]);
    }

    public function destroyLog(EmailLog $emailLog): JsonResponse
    {
        $this->assertSameWorkspace($emailLog->workspace_id);
        $emailLog->delete();

        return response()->json(['data' => ['deleted' => true]]);
    }

    public function downloadAttachment(EmailAttachment $emailAttachment)
    {
        $this->assertSameWorkspace($emailAttachment->workspace_id);
        try {
            $path = $emailAttachment->assertSafeStoragePath();
        } catch (\RuntimeException) {
            abort(404);
        }
        $disk = Storage::disk($emailAttachment->storageDisk());
        abort_unless($disk->exists($path), 404);

        return $disk->download($path, basename($path));
    }

    public function previewAttachment(EmailAttachment $emailAttachment)
    {
        return $this->downloadAttachment($emailAttachment);
    }

    public function destroyAttachment(EmailAttachment $emailAttachment): JsonResponse
    {
        $this->assertSameWorkspace($emailAttachment->workspace_id);
        $emailAttachment->deleteStoredFile();
        $emailAttachment->delete();

        return response()->json(['data' => ['deleted' => true]]);
    }

    public function compose(Request $request): View
    {
        $accounts = EmailAccount::query()->latest('name')->get();
        $currentAccount = $this->resolveCurrentAccount($request, $accounts);
        $draft = $this->resolveComposeDraft($request, $currentAccount);
        $oldRecipient = $request->session()->getOldInput('recipient');
        if (is_string($oldRecipient)) {
            $draft['recipient'] = $oldRecipient;
        }

        $oldSelectedIds = $request->session()->getOldInput('recipient_contact_ids', []);
        if (! empty($oldSelectedIds) && is_array($oldSelectedIds)) {
            $draft['recipient_contact_ids'] = $oldSelectedIds;
        }

        $contacts = EmailContact::query()
            ->orderBy('name')
            ->limit(100)
            ->get(['id', 'name', 'email']);
        $selectedContactIds = collect($draft['recipient_contact_ids'] ?? [])
            ->map(fn ($id): int => (int) $id)
            ->filter(fn (int $id): bool => $id > 0)
            ->unique()
            ->values();
        $selectedContacts = EmailContact::query()
            ->whereIn('id', $selectedContactIds)
            ->orderBy('name')
            ->get(['id', 'name', 'email']);

        return view('workspace.emails.compose', [
            'accounts' => $accounts,
            'currentAccount' => $currentAccount,
            'draft' => $draft,
            'contacts' => $contacts,
            'selectedContacts' => $selectedContacts,
        ]);
    }

    public function clearComposeDraft(Request $request): RedirectResponse
    {
        $request->session()->forget('emails.compose_draft');

        return redirect()
            ->route('workspace.emails.compose', array_filter([
                'account_id' => $request->integer('account_id') ?: null,
            ]))
            ->with('success', 'تم تنظيف مسودة الرسالة.');
    }

    public function accounts(Request $request): View
    {
        $accounts = EmailAccount::query()->latest('id')->get();
        $editingAccount = null;
        if ($request->filled('account_id')) {
            $editingAccount = EmailAccount::query()->find($request->integer('account_id'));
        }

        return view('workspace.emails.accounts', [
            'accounts' => $accounts,
            'editingAccount' => $editingAccount,
        ]);
    }

    public function contacts(Request $request): View
    {
        $search = trim((string) $request->string('search', ''));
        $contacts = EmailContact::query()
            ->when($search !== '', function ($query) use ($search): void {
                $query->where(function ($innerQuery) use ($search): void {
                    $innerQuery
                        ->where('name', 'like', '%'.$search.'%')
                        ->orWhere('email', 'like', '%'.$search.'%')
                        ->orWhere('normalized_email', 'like', '%'.strtolower($search).'%');
                });
            })
            ->orderBy('name')
            ->paginate(20)
            ->withQueryString();

        $editingContact = null;
        if ($request->filled('contact_id')) {
            $editingContact = EmailContact::query()->find($request->integer('contact_id'));
        }

        return view('workspace.emails.contacts', [
            'contacts' => $contacts,
            'editingContact' => $editingContact,
            'search' => $search,
        ]);
    }

    public function storeContact(Request $request): RedirectResponse
    {
        $workspace = $this->currentWorkspace();
        $validated = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'email', 'max:255'],
        ]);

        $normalizedEmail = $this->normalizeEmail((string) $validated['email']);
        $existing = EmailContact::withoutGlobalScopes()
            ->where('workspace_id', $workspace->id)
            ->where('normalized_email', $normalizedEmail)
            ->first();

        if ($existing) {
            return redirect()
                ->route('workspace.emails.contacts.index', ['contact_id' => $existing->id])
                ->withInput()
                ->with('error', 'هذا البريد الإلكتروني مسجل مسبقًا: '.$existing->name.' — ['.$existing->email.']');
        }

        EmailContact::query()->create([
            'workspace_id' => $workspace->id,
            'name' => $validated['name'],
            'email' => trim((string) $validated['email']),
            'normalized_email' => $normalizedEmail,
        ]);

        return redirect()
            ->route('workspace.emails.contacts.index')
            ->with('success', 'تمت إضافة جهة الاتصال بنجاح.');
    }

    public function updateContact(Request $request, EmailContact $emailContact): RedirectResponse
    {
        $workspace = $this->currentWorkspace();
        $validated = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'email', 'max:255'],
        ]);

        $normalizedEmail = $this->normalizeEmail((string) $validated['email']);
        $existing = EmailContact::withoutGlobalScopes()
            ->where('workspace_id', $workspace->id)
            ->where('normalized_email', $normalizedEmail)
            ->where('id', '!=', $emailContact->id)
            ->first();

        if ($existing) {
            return redirect()
                ->route('workspace.emails.contacts.index', ['contact_id' => $existing->id])
                ->withInput()
                ->with('error', 'هذا البريد الإلكتروني مسجل مسبقًا: '.$existing->name.' — ['.$existing->email.']');
        }

        $emailContact->update([
            'name' => $validated['name'],
            'email' => trim((string) $validated['email']),
            'normalized_email' => $normalizedEmail,
        ]);

        return redirect()
            ->route('workspace.emails.contacts.index', ['contact_id' => $emailContact->id])
            ->with('success', 'تم تحديث جهة الاتصال بنجاح.');
    }

    public function destroyContact(EmailContact $emailContact): RedirectResponse
    {
        $emailContact->delete();

        return redirect()
            ->route('workspace.emails.contacts.index')
            ->with('success', 'تم حذف جهة الاتصال بنجاح.');
    }

    public function searchContacts(Request $request): JsonResponse
    {
        $query = trim((string) $request->string('q', ''));
        $contacts = EmailContact::query()
            ->when($query !== '', function ($builder) use ($query): void {
                $builder->where(function ($innerBuilder) use ($query): void {
                    $innerBuilder
                        ->where('name', 'like', '%'.$query.'%')
                        ->orWhere('email', 'like', '%'.$query.'%')
                        ->orWhere('normalized_email', 'like', '%'.strtolower($query).'%');
                });
            })
            ->orderBy('name')
            ->limit(10)
            ->get(['id', 'name', 'email']);

        return response()->json([
            'contacts' => $contacts->map(fn (EmailContact $contact): array => [
                'id' => $contact->id,
                'name' => $contact->name,
                'email' => $contact->email,
                'registered' => true,
                'label' => $contact->name.' — '.$contact->email.' — ✓ مسجل مسبقًا',
            ])->all(),
        ]);
    }

    public function lookupContact(Request $request): JsonResponse
    {
        $email = trim((string) $request->string('email', ''));
        $contact = $this->findContactByEmail($email);

        if (! $contact) {
            return response()->json([
                'found' => false,
            ]);
        }

        return response()->json([
            'found' => true,
            'contact' => [
                'id' => $contact->id,
                'name' => $contact->name,
                'email' => $contact->email,
                'registered' => true,
                'label' => $contact->name.' — '.$contact->email.' — ✓ مسجل مسبقًا',
            ],
        ]);
    }

    public function storeAccount(Request $request): RedirectResponse
    {
        $workspace = $this->currentWorkspace();
        $validated = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'email' => [
                'required',
                'email',
                'max:255',
                Rule::unique('email_accounts', 'email')->where(fn ($query) => $query->where('workspace_id', $workspace->id)),
            ],
            'password' => ['required', 'string', 'max:255'],
            'imap_host' => ['required', 'string', 'max:255'],
            'imap_port' => ['required', 'integer', 'min:1', 'max:65535'],
            'smtp_host' => ['required', 'string', 'max:255'],
            'smtp_port' => ['required', 'integer', 'min:1', 'max:65535'],
            'brand_color' => ['nullable', 'regex:/^#([A-Fa-f0-9]{6}|[A-Fa-f0-9]{3})$/'],
            'logo' => ['nullable', 'image', 'max:4096'],
            'aliases' => ['nullable', 'string'],
        ]);

        $logoPath = null;
        if ($request->hasFile('logo')) {
            $logoPath = $request->file('logo')->store('workspaces/'.$workspace->id.'/email-logos', 'public');
        }

        EmailAccount::query()->create([
            'workspace_id' => $workspace->id,
            'name' => $validated['name'],
            'email' => $validated['email'],
            'password' => $validated['password'],
            'imap_host' => $validated['imap_host'],
            'imap_port' => $validated['imap_port'],
            'smtp_host' => $validated['smtp_host'],
            'smtp_port' => $validated['smtp_port'],
            'logo_path' => $logoPath,
            'brand_color' => $validated['brand_color'] ?: '#06C2A4',
            'aliases' => $this->parseAliases((string) ($validated['aliases'] ?? '')),
        ]);

        return redirect()->route('workspace.emails.accounts.index')->with('success', 'تم حفظ حساب الشركة بنجاح.');
    }

    public function updateAccount(Request $request, EmailAccount $emailAccount): RedirectResponse
    {
        $workspace = $this->currentWorkspace();
        $validated = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'email' => [
                'required',
                'email',
                'max:255',
                Rule::unique('email_accounts', 'email')
                    ->where(fn ($query) => $query->where('workspace_id', $workspace->id))
                    ->ignore($emailAccount->id),
            ],
            'password' => ['nullable', 'string', 'max:255'],
            'imap_host' => ['required', 'string', 'max:255'],
            'imap_port' => ['required', 'integer', 'min:1', 'max:65535'],
            'smtp_host' => ['required', 'string', 'max:255'],
            'smtp_port' => ['required', 'integer', 'min:1', 'max:65535'],
            'brand_color' => ['nullable', 'regex:/^#([A-Fa-f0-9]{6}|[A-Fa-f0-9]{3})$/'],
            'logo' => ['nullable', 'image', 'max:4096'],
            'remove_logo' => ['nullable', 'boolean'],
            'aliases' => ['nullable', 'string'],
        ]);

        $payload = [
            'name' => $validated['name'],
            'email' => $validated['email'],
            'imap_host' => $validated['imap_host'],
            'imap_port' => $validated['imap_port'],
            'smtp_host' => $validated['smtp_host'],
            'smtp_port' => $validated['smtp_port'],
            'brand_color' => $validated['brand_color'] ?: '#06C2A4',
            'aliases' => $this->parseAliases((string) ($validated['aliases'] ?? '')),
        ];

        if (! empty($validated['password'])) {
            $payload['password'] = $validated['password'];
        }

        if ($request->boolean('remove_logo') && $emailAccount->logo_path) {
            Storage::disk('public')->delete($emailAccount->logo_path);
            $payload['logo_path'] = null;
        }

        if ($request->hasFile('logo')) {
            if ($emailAccount->logo_path) {
                Storage::disk('public')->delete($emailAccount->logo_path);
            }
            $payload['logo_path'] = $request->file('logo')->store('workspaces/'.$emailAccount->workspace_id.'/email-logos', 'public');
        }

        $emailAccount->update($payload);

        return redirect()
            ->route('workspace.emails.accounts.index', ['account_id' => $emailAccount->id])
            ->with('success', 'تم تحديث بيانات الحساب بنجاح.');
    }

    public function destroyAccount(EmailAccount $emailAccount): RedirectResponse
    {
        $attachments = EmailAttachment::query()
            ->whereIn('message_id', EmailMessage::query()
                ->where('email_account_id', $emailAccount->id)
                ->select('id')
            )
            ->get();

        $logoPath = $emailAccount->logo_path;

        DB::transaction(function () use ($emailAccount): void {
            $emailAccount->forceDelete();
        });

        foreach ($attachments as $attachment) {
            $attachment->deleteStoredFile();
        }
        if ($logoPath) {
            Storage::disk('public')->delete($logoPath);
        }

        return redirect()
            ->route('workspace.emails.accounts.index')
            ->with('success', 'تم حذف حساب البريد وكل ملفاته المرتبطة.');
    }

    public function syncAccount(EmailAccount $emailAccount): RedirectResponse
    {
        abort_unless((int) $emailAccount->workspace_id === (int) $this->currentWorkspace()->id, 404);

        SyncEmailInboxJob::dispatch($emailAccount->id, (int) $emailAccount->workspace_id)->onQueue('emails');

        return redirect()
            ->route('workspace.emails.accounts.index', ['account_id' => $emailAccount->id])
            ->with('success', 'تم جدولة مزامنة البريد في الخلفية.');
    }

    public function sendMessage(Request $request, WorkspaceEmailSender $workspaceEmailSender): RedirectResponse
    {
        $workspace = $this->currentWorkspace();
        $validated = $request->validate([
            'email_account_id' => [
                'required',
                'integer',
                Rule::exists('email_accounts', 'id')->where(fn ($query) => $query->where('workspace_id', $workspace->id)),
            ],
            'recipient' => ['nullable', 'string', 'max:4000'],
            'subject' => ['nullable', 'string', 'max:255'],
            'body' => ['required', 'string'],
            'sender_alias' => ['nullable', 'string', 'max:255'],
            'reply_to_message_id' => [
                'nullable',
                'integer',
                Rule::exists('email_messages', 'id')->where(fn ($query) => $query->where('workspace_id', $workspace->id)),
            ],
            'attachments' => ['nullable', 'array', 'max:10'],
            'attachments.*' => ['file', 'max:10240', 'mimes:pdf,jpg,jpeg,png,webp'],
            'recipient_contact_ids' => ['nullable', 'array', 'max:200'],
            'recipient_contact_ids.*' => [
                'nullable',
                'integer',
                Rule::exists('email_contacts', 'id')->where(fn ($query) => $query->where('workspace_id', $workspace->id)),
            ],
        ]);

        $emailAccount = EmailAccount::query()->findOrFail($validated['email_account_id']);
        $replyToMessage = null;

        if (! empty($validated['reply_to_message_id'])) {
            $replyToMessage = EmailMessage::query()->findOrFail($validated['reply_to_message_id']);
            if ($replyToMessage->email_account_id !== $emailAccount->id) {
                abort(404);
            }
        }

        $selectedContactIds = collect($validated['recipient_contact_ids'] ?? [])
            ->map(fn ($id): int => (int) $id)
            ->filter(fn (int $id): bool => $id > 0)
            ->unique()
            ->values()
            ->all();
        $selectedContactsById = EmailContact::query()
            ->whereIn('id', $selectedContactIds)
            ->get(['id', 'name', 'email'])
            ->keyBy('id');
        $selectedRecipientEmails = collect($selectedContactIds)
            ->map(fn (int $id): ?string => $selectedContactsById->get($id)?->email)
            ->filter()
            ->values()
            ->all();
        $manualRecipientEmails = $this->parseRecipientEmails((string) ($validated['recipient'] ?? ''));
        $combinedRecipients = collect(array_merge($selectedRecipientEmails, $manualRecipientEmails))
            ->map(fn (string $email): string => $this->normalizeEmail($email))
            ->filter()
            ->unique()
            ->values()
            ->all();

        if (count($combinedRecipients) === 0) {
            return redirect()
                ->route('workspace.emails.compose', ['account_id' => $emailAccount->id])
                ->withInput()
                ->with('error', 'يرجى إدخال مستلم واحد على الأقل أو اختيار جهات اتصال.');
        }

        $request->session()->put('emails.compose_draft', [
            'email_account_id' => $emailAccount->id,
            'sender_alias' => (string) ($validated['sender_alias'] ?? ''),
            'recipient' => implode(', ', $combinedRecipients),
            'subject' => $validated['subject'] ?? '',
            'body' => $validated['body'],
            'reply_to_message_id' => $replyToMessage?->id,
            'recipient_contact_ids' => $selectedContactIds,
        ]);

        $sender = $emailAccount->email;
        if (! empty($validated['sender_alias'])) {
            $sender = trim($validated['sender_alias']).' <'.$emailAccount->email.'>';
        }

        $threadKey = $replyToMessage?->thread_key ?: ($replyToMessage?->message_id ?: Str::uuid()->toString());

        $message = DB::transaction(function () use ($workspace, $emailAccount, $sender, $validated, $replyToMessage, $threadKey, $request, $combinedRecipients): EmailMessage {
            $message = EmailMessage::query()->create([
                'workspace_id' => $workspace->id,
                'email_account_id' => $emailAccount->id,
                'sender' => $sender,
                'recipient' => implode(', ', $combinedRecipients),
                'subject' => $validated['subject'] ?? '(بدون عنوان)',
                'body' => $validated['body'],
                'type' => 'outbound',
                'delivery_status' => 'sending',
                'message_id' => '<'.Str::uuid().'@'.Str::after($emailAccount->email, '@').'>',
                'in_reply_to' => $replyToMessage?->message_id,
                'thread_key' => $threadKey,
            ]);

            foreach ($request->file('attachments', []) as $file) {
                $path = $file->store('workspaces/'.$workspace->id.'/emails/attachments', 'local');

                EmailAttachment::query()->create([
                    'workspace_id' => $workspace->id,
                    'message_id' => $message->id,
                    'file_path' => $path,
                    'disk' => 'local',
                    'file_type' => $file->getClientMimeType(),
                    'file_size' => $file->getSize(),
                ]);
            }

            return $message->load(['account', 'attachments']);
        });

        try {
            $emailLog = $workspaceEmailSender->send($message);
            $message->forceFill([
                'delivery_status' => 'sent',
                'delivery_error' => null,
                'delivered_at' => now(),
                'message_id' => $emailLog?->provider_message_id ?: $message->message_id,
            ])->save();

            return redirect()
                ->route('workspace.emails.compose', ['account_id' => $emailAccount->id])
                ->with('success', 'تم إرسال الرسالة بنجاح.');
        } catch (\Throwable $exception) {
            $message->forceFill([
                'delivery_status' => 'failed',
                'delivery_error' => 'Email delivery failed.',
            ])->save();

            return redirect()
                ->route('workspace.emails.compose', ['account_id' => $emailAccount->id])
                ->with('error', 'تعذر إرسال الرسالة حاليًا. يرجى المحاولة لاحقًا.');
        }
    }

    public function destroyMessage(Request $request, EmailMessage $emailMessage): RedirectResponse
    {
        $attachments = $emailMessage->attachments;

        DB::transaction(function () use ($emailMessage): void {
            $emailMessage->attachments()->delete();
            $emailMessage->delete();
        });

        foreach ($attachments as $attachment) {
            $attachment->deleteStoredFile();
        }

        $targetRoute = $this->resolveReturnTo($request) === 'sent'
            ? 'workspace.emails.sent'
            : 'workspace.emails.inbox';

        return redirect()
            ->route($targetRoute, array_filter([
                'account_id' => $request->integer('account_id') ?: null,
                'search' => $request->string('search')->toString() ?: null,
            ]))
            ->with('success', 'تم حذف الرسالة والمرفقات المرتبطة بها بنجاح.');
    }

    private function buildMessagesQuery(?EmailAccount $currentAccount, string $type, string $search)
    {
        $messagesQuery = EmailMessage::query()
            ->with('account')
            ->where('type', $type)
            ->latest('created_at');

        if ($currentAccount) {
            $messagesQuery->where('email_account_id', $currentAccount->id);
        }

        if ($search !== '') {
            $messagesQuery->where(function ($query) use ($search): void {
                $query
                    ->where('subject', 'like', '%'.$search.'%')
                    ->orWhere('sender', 'like', '%'.$search.'%')
                    ->orWhere('recipient', 'like', '%'.$search.'%')
                    ->orWhere('body', 'like', '%'.$search.'%');
            });
        }

        return $messagesQuery;
    }

    private function resolveCurrentAccount(Request $request, Collection $accounts): ?EmailAccount
    {
        if ($request->filled('account_id')) {
            $selected = EmailAccount::query()->find($request->integer('account_id'));
            if ($selected) {
                return $selected;
            }
        }

        return $accounts->first();
    }

    private function resolveComposeDraft(Request $request, ?EmailAccount $currentAccount): array
    {
        $storedDraft = $request->session()->get('emails.compose_draft', []);
        $draft = [
            'email_account_id' => $storedDraft['email_account_id'] ?? $currentAccount?->id,
            'sender_alias' => $storedDraft['sender_alias'] ?? '',
            'recipient' => $storedDraft['recipient'] ?? '',
            'subject' => $storedDraft['subject'] ?? '',
            'body' => $storedDraft['body'] ?? '',
            'reply_to_message_id' => $storedDraft['reply_to_message_id'] ?? null,
            'recipient_contact_ids' => $storedDraft['recipient_contact_ids'] ?? [],
        ];

        if ($request->filled('reply_to_message_id') && empty($draft['body'])) {
            $replyMessage = EmailMessage::query()->find($request->integer('reply_to_message_id'));
            if ($replyMessage) {
                $draft['reply_to_message_id'] = $replyMessage->id;
                $draft['email_account_id'] = $replyMessage->email_account_id;
                $draft['recipient'] = $replyMessage->sender;
                $draft['subject'] = 'Re: '.($replyMessage->subject ?: '(بدون عنوان)');
                $draft['body'] = "\n\n---\n".$replyMessage->body;
            }
        }

        if ($request->filled('recipient')) {
            $draft['recipient'] = trim((string) $request->string('recipient'));
        }

        if ($request->filled('recipient_contact_ids')) {
            $recipientContactIds = $request->input('recipient_contact_ids', []);
            if (is_array($recipientContactIds)) {
                $draft['recipient_contact_ids'] = $recipientContactIds;
            }
        }

        if ($request->filled('recipient_contact_id')) {
            $legacySingleContactId = $request->integer('recipient_contact_id');
            if ($legacySingleContactId) {
                $draft['recipient_contact_ids'] = array_values(array_unique(array_merge(
                    is_array($draft['recipient_contact_ids']) ? $draft['recipient_contact_ids'] : [],
                    [$legacySingleContactId]
                )));
            }
        }

        return $draft;
    }

    /**
     * @return array<string, string|int>
     */
    private function forwardListFilters(Request $request): array
    {
        return array_filter([
            'account_id' => $request->integer('account_id') ?: null,
            'search' => trim((string) $request->string('search', '')) ?: null,
        ]);
    }

    private function resolveReturnTo(Request $request): string
    {
        $returnTo = (string) $request->string('return_to', 'inbox');

        return in_array($returnTo, ['inbox', 'sent'], true) ? $returnTo : 'inbox';
    }

    private function findContactByEmail(string $email): ?EmailContact
    {
        $normalizedEmail = $this->normalizeEmail($email);
        if ($normalizedEmail === '') {
            return null;
        }

        return EmailContact::query()
            ->where('normalized_email', $normalizedEmail)
            ->first();
    }

    /**
     * @return array<int, string>
     */
    private function parseRecipientEmails(string $value): array
    {
        return collect(preg_split('/[\r\n,;]+/', $value) ?: [])
            ->map(fn (string $part): string => $this->normalizeEmail($part))
            ->filter()
            ->unique()
            ->values()
            ->all();
    }

    private function normalizeEmail(string $email): string
    {
        $candidate = trim($email);

        if (str_contains($candidate, '<') && str_contains($candidate, '>')) {
            $start = strpos($candidate, '<');
            $end = strrpos($candidate, '>');
            if ($start !== false && $end !== false && $end > $start) {
                $candidate = substr($candidate, $start + 1, $end - $start - 1);
            }
        }

        $candidate = strtolower(trim($candidate));

        return filter_var($candidate, FILTER_VALIDATE_EMAIL) ? $candidate : '';
    }

    /**
     * @return array<int, string>
     */
    private function parseAliases(string $aliasesRaw): array
    {
        $aliases = collect(preg_split('/[\r\n,]+/', $aliasesRaw) ?: [])
            ->map(fn (string $alias): string => trim($alias))
            ->filter()
            ->unique()
            ->take(50)
            ->values()
            ->all();

        return $aliases;
    }
}
