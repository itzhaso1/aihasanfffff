<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\Ai\GenerateReplyRequest;
use App\Models\AiSetting;
use App\Models\Conversation;
use App\Models\Message;
use App\Services\AI\AIService;
use App\Services\AI\AiSafety;
use App\Services\Feature\FeatureAccessService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class AiController extends Controller
{
    public function __construct(
        private readonly AIService $aiService,
        private readonly AiSafety $safety,
        private readonly FeatureAccessService $featureAccess,
    ) {}

    public function settings(): JsonResponse
    {
        $settings = AiSetting::query()->first();

        return response()->json(['data' => $settings]);
    }

    public function updateSettings(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'name' => ['nullable', 'string', 'max:255'],
            'instructions' => ['nullable', 'string', 'max:2000'],
            'tone' => ['nullable', 'string', 'max:255'],
            'reply_style' => ['nullable', 'string', 'max:255'],
            'rules' => ['nullable', 'array'],
            'business_information' => ['nullable', 'array'],
            'provider' => ['nullable', 'string', 'max:64'],
            'model' => ['nullable', 'string', 'max:255'],
            'max_tokens' => ['nullable', 'integer', 'min:64', 'max:'.$this->safety->capMaxTokens(4096)],
            'temperature' => ['nullable', 'numeric', 'min:0', 'max:1'],
            'is_active' => ['nullable', 'boolean'],
        ]);

        if (isset($validated['provider'])) {
            $validated['provider'] = $this->safety->resolveProvider($validated['provider']);
        }
        if (isset($validated['model'])) {
            $provider = $validated['provider'] ?? (string) config('ai.default_provider', 'google_ai_studio');
            $validated['model'] = $this->safety->resolveModel($this->safety->resolveProvider($provider), $validated['model']);
        }
        if (isset($validated['max_tokens'])) {
            $validated['max_tokens'] = $this->safety->capMaxTokens((int) $validated['max_tokens']);
        }
        if (isset($validated['temperature'])) {
            $validated['temperature'] = $this->safety->capTemperature((float) $validated['temperature']);
        }

        $settings = AiSetting::query()->firstOrCreate([], [
            'name' => 'AI Assistant',
            'provider' => config('ai.default_provider', 'google_ai_studio'),
            'model' => config('ai.google_ai_studio.model', 'gemini-2.5-flash'),
            'temperature' => config('ai.google_ai_studio.temperature', 0.3),
            'max_tokens' => config('ai.google_ai_studio.max_tokens', 1024),
        ]);
        $settings->update($validated);

        return response()->json(['data' => $settings->refresh()]);
    }

    public function generateReply(GenerateReplyRequest $request): JsonResponse
    {
        $conversation = Conversation::query()->findOrFail($request->integer('conversation_id'));
        $this->authorize('update', $conversation);
        $currentWorkspaceId = app(\App\Support\Tenancy\WorkspaceContext::class)->workspaceId();
        if ($currentWorkspaceId === null || (int) $conversation->workspace_id !== (int) $currentWorkspaceId) {
            abort(404);
        }
        $workspace = $conversation->workspace;
        $user = $request->user();
        if (! $user || ! $workspace || ! $this->featureAccess->hasFeature($user, $workspace, 'ai')) {
            abort(403, 'ميزة الذكاء الاصطناعي غير متاحة.');
        }

        $message = Message::query()
            ->where('conversation_id', $conversation->id)
            ->whereKey($request->integer('message_id'))
            ->firstOrFail();

        $reply = $this->aiService->generateReply($conversation, $message);

        $outbound = Message::query()->create([
            'workspace_id' => $conversation->workspace_id,
            'conversation_id' => $conversation->id,
            'customer_id' => $conversation->customer_id,
            'direction' => 'outbound',
            'message_type' => 'text',
            'content' => $reply,
            'ai_generated' => true,
        ]);

        return response()->json([
            'data' => [
                'reply' => $reply,
                'message' => $outbound,
            ],
        ]);
    }
}
