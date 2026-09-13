<?php

namespace App\Http\Controllers\Api\Mobile\V1;

use App\Http\Controllers\Api\Mobile\Concerns\ResolvesMobileWorkspace;
use App\Http\Controllers\Api\Mobile\MobileController;
use App\Http\Resources\Mobile\ConversationResource;
use App\Http\Resources\Mobile\MessageResource;
use App\Models\Conversation;
use App\Models\ConversationUserState;
use App\Services\Mobile\ConversationInboxService;
use App\Support\Tenancy\WorkspaceContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class ConversationController extends MobileController
{
    use ResolvesMobileWorkspace;

    public function __construct(
        private readonly ConversationInboxService $conversationInboxService,
        private readonly WorkspaceContext $workspaceContext,
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
            'channel' => $request->input('channel'),
            'search' => $request->input('search'),
            'per_page' => $perPage,
        ]);

        $items = collect($paginator->items())->map(function (Conversation $conversation) use ($user): Conversation {
            $conversation->unread_count = $this->conversationInboxService->unreadCountForConversation($conversation, $user);
            $conversation->muted = $conversation->user_muted_at !== null;
            $conversation->archived = $conversation->user_archived_at !== null;
            $conversation->last_message = $conversation->messages->first();

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
        $this->authorize('view', $conversation);

        $user = $request->user();
        $conversation->load(['customer']);
        $conversation->unread_count = $this->conversationInboxService->unreadCountForConversation($conversation, $user);
        $state = ConversationUserState::query()
            ->where('conversation_id', $conversation->id)
            ->where('user_id', $user->id)
            ->first();
        $conversation->muted = $state?->muted_at !== null;
        $conversation->archived = $state?->archived_at !== null;
        $conversation->last_message = $conversation->messages()->latest('id')->first();

        return $this->ok(new ConversationResource($conversation));
    }

    public function messages(Request $request, Conversation $conversation): JsonResponse
    {
        $this->authorize('view', $conversation);

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
        $this->authorize('update', $conversation);

        $validated = $request->validate([
            'content' => ['required', 'string'],
            'message_type' => ['nullable', 'string', 'max:32'],
            'idempotency_key' => ['nullable', 'string', 'max:128'],
        ]);

        $headerKey = trim((string) $request->header('Idempotency-Key', ''));

        $message = $this->conversationInboxService->sendMessage($conversation, $request->user(), [
            'direction' => 'outbound',
            'content' => $validated['content'],
            'message_type' => $validated['message_type'] ?? 'text',
            'idempotency_key' => $validated['idempotency_key'] ?? ($headerKey !== '' ? $headerKey : null),
        ]);

        return $this->ok(new MessageResource($message), message: 'تم إرسال الرسالة بنجاح.', status: 201);
    }

    public function read(Request $request, Conversation $conversation): JsonResponse
    {
        $this->authorize('view', $conversation);

        $validated = $request->validate([
            'message_id' => ['nullable', 'integer'],
        ]);

        $this->conversationInboxService->markRead(
            $conversation,
            $request->user(),
            isset($validated['message_id']) ? (int) $validated['message_id'] : null,
        );

        return $this->ok(message: 'تم تعليم المحادثة كمقروءة.');
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
}
