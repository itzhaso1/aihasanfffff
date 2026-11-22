<?php

namespace App\Http\Controllers\Api\Mobile\V1;

use App\Http\Controllers\Api\Mobile\Concerns\ResolvesMobileWorkspace;
use App\Http\Controllers\Api\Mobile\MobileController;
use App\Http\Resources\Mobile\ConversationResource;
use App\Http\Resources\Mobile\MessageResource;
use App\Models\Conversation;
use App\Models\ConversationUserState;
use App\Services\Conversation\ConversationClassificationService;
use App\Services\Conversation\ConversationPresenceService;
use App\Services\Conversation\ConversationService;
use App\Services\Conversation\Exceptions\InvalidHandoffTransitionException;
use App\Services\Inbox\InboxHumanHandoffService;
use App\Services\Inbox\InboxRealtimePublisher;
use App\Services\Mobile\ConversationInboxService;
use App\Services\Mobile\MobileInboxActor;
use App\Services\Worker\WorkerInboxException;
use App\Support\Tenancy\WorkspaceContext;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use InvalidArgumentException;

class ConversationController extends MobileController
{
    use ResolvesMobileWorkspace;

    public function __construct(
        private readonly ConversationInboxService $conversationInboxService,
        private readonly ConversationClassificationService $classification,
        private readonly WorkspaceContext $workspaceContext,
        private readonly MobileInboxActor $inboxActor,
    ) {}

    public function index(Request $request): JsonResponse
    {
        $user = $request->user();
        if (! $user) {
            return $this->fail('غير مصرح.', 401);
        }

        $workspace = $this->requireWorkspace($this->workspaceContext);
        $perPage = max(1, min(50, (int) $request->input('per_page', 20)));

        $paginator = $this->conversationInboxService->listForUser($user, $workspace, [
            'filter' => $request->input('filter', 'all'),
            'handoff' => $request->input('handoff'),
            'channel' => $request->input('channel'),
            'search' => $request->input('search'),
            'customer_priority' => $request->input('customer_priority'),
            'payment_status' => $request->input('payment_status'),
            'review_status' => $request->input('review_status'),
            'per_page' => $perPage,
        ]);

        $items = collect($paginator->items())->map(function (Conversation $conversation) use ($user): Conversation {
            $conversation->unread_count = isset($conversation->unread_count)
                ? (int) $conversation->unread_count
                : $this->conversationInboxService->unreadCountForConversation($conversation, $user);
            $conversation->muted = $conversation->user_muted_at !== null;
            $conversation->archived = $conversation->user_archived_at !== null;
            $conversation->last_message = $conversation->messages->first();
            $conversation->setAttribute('can_reply', $this->inboxActor->canReply($user, $conversation));

            return $conversation;
        });

        return $this->ok(
            ConversationResource::collection($items),
            $this->cursorMeta(
                $paginator->nextCursor()?->encode(),
                $paginator->previousCursor()?->encode(),
                $perPage,
            ),
        );
    }

    public function show(Request $request, Conversation $conversation): JsonResponse
    {
        $user = $request->user();
        try {
            $this->inboxActor->assertReadable($user, $conversation);
        } catch (ModelNotFoundException) {
            return $this->fail('العنصر المطلوب غير موجود.', 404);
        }
        $conversation->load(['customer', 'assignedUser']);
        if (app(InboxHumanHandoffService::class)->repairAssistantHandoffClaim($conversation)) {
            $conversation = $conversation->fresh(['customer']) ?? $conversation;
        }
        $conversation->unread_count = $this->conversationInboxService->unreadCountForConversation($conversation, $user);
        $state = ConversationUserState::query()
            ->where('conversation_id', $conversation->id)
            ->where('user_id', $user->id)
            ->first();
        $conversation->muted = $state?->muted_at !== null;
        $conversation->archived = $state?->archived_at !== null;
        $conversation->last_message = $conversation->messages()->latest('id')->first();
        $conversation->setAttribute('can_reply', $this->inboxActor->canReply($user, $conversation));

        return $this->ok(new ConversationResource($conversation));
    }

    public function messages(Request $request, Conversation $conversation): JsonResponse
    {
        try {
            $this->inboxActor->assertReadable($request->user(), $conversation);
        } catch (ModelNotFoundException) {
            return $this->fail('العنصر المطلوب غير موجود.', 404);
        }

        $afterId = $request->integer('after_id', 0);
        if ($afterId > 0) {
            $messages = Message::query()
                ->where('conversation_id', $conversation->id)
                ->where('id', '>', $afterId)
                ->with(['user:id,name', 'customer:id,name', 'attachments'])
                ->orderBy('id', 'asc')
                ->limit(50)
                ->get();

            return $this->ok(
                MessageResource::collection($messages),
                ['count' => $messages->count(), 'polled' => true],
            );
        }

        app(InboxHumanHandoffService::class)->repairAssistantHandoffClaim($conversation);

        $perPage = max(1, min(50, (int) $request->input('per_page', 30)));
        $paginator = $this->conversationInboxService->messages($conversation, $perPage);

        return $this->ok(
            MessageResource::collection($paginator->items()),
            $this->cursorMeta(
                $paginator->nextCursor()?->encode(),
                $paginator->previousCursor()?->encode(),
                $perPage,
            ),
        );
    }

    public function storeMessage(Request $request, Conversation $conversation): JsonResponse
    {
        Cache::forget("conversation:{$conversation->id}:active_typing");

        Log::info('chat.send_message', [
            'received' => true,
            'workspace_id' => $conversation->workspace_id,
            'conversation_id' => $conversation->id,
            'user_id' => $request->user()?->id,
            'message_type' => $request->input('message_type', 'text'),
        ]);

        try {
            $this->inboxActor->assertSendable($request->user(), $conversation);
        } catch (AuthorizationException) {
            return $this->fail('لا تملك صلاحية تنفيذ هذا الإجراء.', 403);
        } catch (ModelNotFoundException) {
            return $this->fail('غير موجود.', 404);
        }

        $validated = $request->validate([
            'content' => ['required', 'string'],
            'direction' => ['nullable', 'string', 'in:outbound,internal_note'],
            'message_type' => ['nullable', 'string', 'max:32'],
            'idempotency_key' => ['nullable', 'string', 'max:128'],
        ]);

        $headerKey = trim((string) $request->header('Idempotency-Key', ''));

        try {
            $message = $this->conversationInboxService->sendMessage($conversation, $request->user(), [
                'direction' => $validated['direction'] ?? 'outbound',
                'content' => $validated['content'],
                'message_type' => $validated['message_type'] ?? 'text',
                'idempotency_key' => $validated['idempotency_key'] ?? ($headerKey !== '' ? $headerKey : null),
            ], $request);
        } catch (ModelNotFoundException) {
            return $this->fail('غير موجود.', 404);
        } catch (WorkerInboxException $exception) {
            return $this->fail($exception->getMessage(), 422);
        } catch (AuthorizationException) {
            return $this->fail('لا تملك صلاحية تنفيذ هذا الإجراء.', 403);
        }

        Cache::forget("conversation:{$conversation->id}:active_typing");

        Log::info('chat.send_message', [
            'created' => true,
            'workspace_id' => $conversation->workspace_id,
            'conversation_id' => $conversation->id,
            'user_id' => $request->user()?->id,
            'message_id' => $message->id,
            'status' => 201,
        ]);

        return $this->ok(new MessageResource($message), message: 'تم إرسال الرسالة بنجاح.', status: 201);
    }

    public function read(Request $request, Conversation $conversation): JsonResponse
    {
        $user = $request->user();
        if (! $user) {
            return $this->fail('غير مصرح.', 401);
        }

        try {
            $this->inboxActor->assertReadable($user, $conversation);
        } catch (ModelNotFoundException) {
            return $this->fail('العنصر المطلوب غير موجود.', 404);
        }

        $validated = $request->validate([
            'message_id' => ['nullable', 'integer'],
        ]);

        $this->conversationInboxService->markRead(
            $conversation,
            $user,
            isset($validated['message_id']) ? (int) $validated['message_id'] : null,
        );

        $unread = $this->conversationInboxService->unreadCountForConversation($conversation, $user);

        return $this->ok(['unread_count' => $unread], message: 'تم تعليم المحادثة كمقروءة.');
    }

    public function archive(Request $request, Conversation $conversation): JsonResponse
    {
        $this->authorize('update', $conversation);

        $archived = $request->boolean('archived', true);
        $this->conversationInboxService->archive($conversation, $request->user(), $archived);

        return $this->ok(message: $archived ? 'تم أرشفة المحادثة.' : 'تم إلغاء أرشفة المحادثة.');
    }

    public function close(Request $request, Conversation $conversation): JsonResponse
    {
        $this->authorize('update', $conversation);
        $conversation->update(['status' => 'closed']);
        app(InboxRealtimePublisher::class)->publishConversationUpdate($conversation->fresh() ?? $conversation);

        return $this->ok(new ConversationResource($conversation->fresh(['customer', 'assignedUser'])), message: 'تم إغلاق المحادثة بنجاح.');
    }

    public function reopen(Request $request, Conversation $conversation): JsonResponse
    {
        $this->authorize('update', $conversation);
        $conversation->update(['status' => 'open']);
        app(InboxRealtimePublisher::class)->publishConversationUpdate($conversation->fresh() ?? $conversation);

        return $this->ok(new ConversationResource($conversation->fresh(['customer', 'assignedUser'])), message: 'تمت إعادة فتح المحادثة بنجاح.');
    }

    public function mute(Request $request, Conversation $conversation): JsonResponse
    {
        $this->authorize('update', $conversation);

        $muted = $request->boolean('muted', true);
        $this->conversationInboxService->mute($conversation, $request->user(), $muted);

        return $this->ok(message: $muted ? 'تم كتم المحادثة.' : 'تم إلغاء كتم المحادثة.');
    }

    public function updateAi(Request $request, Conversation $conversation): JsonResponse
    {
        $validated = $request->validate([
            'ai_enabled' => ['required', 'boolean'],
        ]);

        $enabled = (bool) $validated['ai_enabled'];
        $this->authorize($enabled ? 'resumeAi' : 'update', $conversation);
        $actor = $request->user();
        if (! $actor) {
            return $this->fail('غير مصرح.', 401);
        }

        try {
            $conversation = $this->conversationInboxService->toggleAi(
                $conversation,
                $actor,
                $enabled,
            );
        } catch (InvalidHandoffTransitionException|InvalidArgumentException $exception) {
            return $this->fail($exception->getMessage(), 422);
        }

        return $this->ok(
            new ConversationResource($conversation->load(['customer', 'assignedUser'])),
            message: $enabled ? 'تم تفعيل الذكاء الاصطناعي.' : 'تم إيقاف رد الذكاء الاصطناعي.',
        );
    }

    public function updateClassification(Request $request, Conversation $conversation): JsonResponse
    {
        $this->authorize('update', $conversation);

        $validated = $request->validate([
            'customer_priority' => ['required', 'string'],
            'payment_status' => ['required', 'string'],
            'review_status' => ['required', 'string'],
        ]);

        $conversation = $this->classification->applyManual($conversation, $request->user(), $validated);
        app(InboxRealtimePublisher::class)->publishConversationUpdate($conversation->fresh() ?? $conversation);

        return $this->ok(new ConversationResource($conversation), message: 'تم تحديث تصنيف المحادثة.');
    }

    public function store(Request $request): JsonResponse
    {
        $this->authorize('create', Conversation::class);

        $validated = $request->validate([
            'customer_id' => ['nullable', 'integer', 'exists:customers,id'],
            'customer_name' => ['nullable', 'string', 'max:255'],
            'customer_phone' => ['nullable', 'string', 'max:50'],
            'channel' => ['required', 'string', 'in:whatsapp,instagram,facebook_messenger,email,web,manual,telegram,tiktok'],
            'external_id' => ['nullable', 'string', 'max:255'],
            'status' => ['nullable', 'string', 'in:open,closed,archived'],
            'ai_enabled' => ['nullable', 'boolean'],
            'metadata' => ['nullable', 'array'],
            'initial_message' => ['nullable', 'string'],
        ]);

        $workspace = $this->requireWorkspace($this->workspaceContext);
        $customerId = $validated['customer_id'] ?? null;

        if (! $customerId && ! empty($validated['customer_name'])) {
            $customer = Customer::create([
                'workspace_id' => $workspace->id,
                'name' => $validated['customer_name'],
                'phone' => $validated['customer_phone'] ?? null,
                'whatsapp' => $validated['channel'] === 'whatsapp' ? ($validated['customer_phone'] ?? null) : null,
            ]);
            $customerId = $customer->id;
        }

        $convData = [
            'workspace_id' => $workspace->id,
            'customer_id' => $customerId,
            'channel' => $validated['channel'],
            'external_id' => $validated['external_id'] ?? ($validated['customer_phone'] ?? null),
            'status' => $validated['status'] ?? 'open',
            'ai_enabled' => $request->boolean('ai_enabled', true),
            'metadata' => $validated['metadata'] ?? null,
        ];

        $conversationService = app(ConversationService::class);
        $conversation = $conversationService->create($convData);

        if (! empty($validated['initial_message'])) {
            $conversationService->addMessage($conversation, [
                'workspace_id' => $workspace->id,
                'direction' => 'outbound',
                'body' => $validated['initial_message'],
            ], $request->user());
        }

        return $this->ok(
            new ConversationResource($conversation->fresh(['customer', 'messages'])),
            message: 'تم إنشاء المحادثة بنجاح.',
            status: 201,
        );
    }

    public function destroy(Conversation $conversation): JsonResponse
    {
        $this->authorize('delete', $conversation);
        $conversation->delete();

        return $this->ok(message: 'تم حذف المحادثة.');
    }

    public function typing(Request $request, Conversation $conversation): JsonResponse
    {
        $user = $request->user();
        if (! $user) {
            return $this->unauthorized();
        }

        $isTyping = $request->boolean('is_typing', true);
        $senderName = $user->name ?? 'موظف';
        $senderType = 'user';

        $profile = $user->relationLoaded('workerProfile') ? $user->workerProfile : $user->workerProfile()->first();
        if ($profile !== null) {
            $senderType = 'worker';
            $senderName = $profile->display_name ?: $user->name ?: 'موظف مستقل';
        }

        $res = app(ConversationPresenceService::class)->setTyping(
            $conversation,
            $user,
            $isTyping,
            senderType: $senderType,
            senderName: $senderName,
        );

        return $this->ok([
            'status' => 'ok',
            'is_typing' => $isTyping,
            'typing_users' => $res['typing_users'] ?? [],
            'typing_summary' => $res['typing_summary'] ?? '',
            'active_responder' => $conversation->currentResponder(),
        ]);
    }

    public function presence(Conversation $conversation): JsonResponse
    {
        $this->authorize('view', $conversation);
        $state = app(ConversationPresenceService::class)->getConversationState($conversation);

        return $this->ok($state);
    }

    public function presencePing(Request $request, Conversation $conversation): JsonResponse
    {
        $this->authorize('view', $conversation);
        $user = $request->user();
        if ($user === null) {
            return $this->fail('غير مصرح.', 401);
        }

        $stateInput = $request->string('state', ConversationPresenceService::STATE_VIEWING)->toString();
        $state = app(ConversationPresenceService::class)->ping($conversation, $user, $stateInput);

        return $this->ok($state);
    }

    public function presenceLeave(Request $request, Conversation $conversation): JsonResponse
    {
        $this->authorize('view', $conversation);
        $user = $request->user();
        if ($user !== null) {
            app(ConversationPresenceService::class)->leave($conversation, $user);
        }

        return $this->ok(['status' => 'ok']);
    }
}
