<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\Message\StoreMessageRequest;
use App\Jobs\ProcessAIResponse;
use App\Models\Message;
use App\Services\Conversation\ConversationService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class MessageController extends Controller
{
    public function __construct(
        private readonly ConversationService $conversationService,
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
        $conversation = \App\Models\Conversation::query()
            ->findOrFail($request->integer('conversation_id'));
        $this->authorize('update', $conversation);

        $message = $this->conversationService->addMessage($conversation, $request->validated(), $request->user());

        if ($message->direction === 'inbound' && $conversation->ai_enabled) {
            ProcessAIResponse::dispatch($conversation->id, $message->id);
        }

        return response()->json(['data' => $message], 201);
    }

    public function show(Message $message): JsonResponse
    {
        return response()->json(['data' => $message]);
    }
}
