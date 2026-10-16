<?php

namespace App\Http\Controllers\Api\Mobile\V1;

use App\Http\Controllers\Api\Mobile\Concerns\ResolvesMobileWorkspace;
use App\Http\Controllers\Api\Mobile\MobileController;
use App\Http\Resources\Mobile\ConversationResource;
use App\Http\Resources\Mobile\MessageResource;
use App\Models\Conversation;
use App\Models\ConversationUserState;
use App\Services\Conversation\ConversationClassificationService;
use App\Services\Conversation\Exceptions\InvalidHandoffTransitionException;
use App\Services\Inbox\InboxHumanHandoffService;
use App\Services\Mobile\ConversationInboxService;
use App\Services\Mobile\MobileInboxActor;
use App\Services\Worker\WorkerInboxException;
use App\Support\Tenancy\WorkspaceContext;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
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
            $conversation->unread_count = $this->conversationInboxService->unreadCountForConversation($conversation, $user);
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
        $conversation->load(['customer']);
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
            'message_type' => ['nullable', 'string', 'max:32'],
            'idempotency_key' => ['nullable', 'string', 'max:128'],
        ]);

        $headerKey = trim((string) $request->header('Idempotency-Key', ''));

        try {
            $message = $this->conversationInboxService->sendMessage($conversation, $request->user(), [
                'direction' => 'outbound',
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

        return $this->ok(new ConversationResource($conversation), message: 'تم تحديث تصنيف المحادثة.');
    }
}
