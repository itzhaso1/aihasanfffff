<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\Message\StoreMessageRequest;
use App\Jobs\ProcessAIResponse;
use App\Models\Conversation;
use App\Models\Message;
use App\Services\Conversation\ConversationAiEligibilityService;
use App\Services\Conversation\ConversationService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class MessageController extends Controller
{
    public function __construct(
        private readonly ConversationService $conversationService,
        private readonly ConversationAiEligibilityService $aiEligibility,
    ) {}

    public function index(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'conversation_id' => ['required', 'integer', 'exists:conversations,id'],
        ]);

        $messages = Message::query()
            ->with(['user', 'customer'])
            ->where('conversation_id', $validated['conversation_id'])
            ->latest('id')
            ->paginate((int) $request->input('per_page', 30));

        return response()->json($messages);
    }

    public function store(StoreMessageRequest $request): JsonResponse
    {
        $conversation = Conversation::query()
            ->findOrFail($request->integer('conversation_id'));
        $this->authorize('reply', $conversation);

        $payload = $request->validated();
        if ($request->hasHeader('Idempotency-Key')) {
            $payload['idempotency_key'] = trim((string) $request->header('Idempotency-Key'));
        }

        $message = $this->conversationService->addMessage($conversation, $payload, $request->user());

        $conversation = $conversation->fresh() ?? $conversation;
        if ($message->wasRecentlyCreated && $this->aiEligibility->shouldDispatchAiJob($conversation, $message)) {
            ProcessAIResponse::dispatch($conversation->id, $message->id, (int) $conversation->workspace_id);
        }

        return response()->json(['data' => $message], 201);
    }

    public function show(Message $message): JsonResponse
    {
        return response()->json(['data' => $message]);
    }
}
