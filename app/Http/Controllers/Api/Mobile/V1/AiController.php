<?php

namespace App\Http\Controllers\Api\Mobile\V1;

use App\Http\Controllers\Api\Mobile\Concerns\ResolvesMobileWorkspace;
use App\Http\Controllers\Api\Mobile\MobileController;
use App\Models\AiSetting;
use App\Models\Conversation;
use App\Models\Message;
use App\Services\AI\AiSafety;
use App\Services\AI\AIService;
use App\Services\Feature\FeatureAccessService;
use App\Support\Tenancy\WorkspaceContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

class AiController extends MobileController
{
    use ResolvesMobileWorkspace;

    public function __construct(
        private readonly AIService $aiService,
        private readonly FeatureAccessService $featureAccessService,
        private readonly WorkspaceContext $workspaceContext,
        private readonly AiSafety $safety,
    ) {}

    public function suggestReply(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'conversation_id' => ['required', 'integer'],
            'content' => ['nullable', 'string', 'max:8000'],
            'persist' => ['nullable', 'boolean'],
        ]);

        $conversation = Conversation::query()->findOrFail($validated['conversation_id']);
        $this->authorize('view', $conversation);

        $workspace = $this->requireWorkspace($this->workspaceContext);
        if ((int) $conversation->workspace_id !== (int) $workspace->id) {
            abort(404);
        }
        $user = $request->user();
        if (! $user || ! $this->featureAccessService->hasFeature($user, $workspace, 'ai')) {
            return $this->fail('ميزة الذكاء الاصطناعي غير متاحة لباقتك.', 403);
        }

        if ($request->boolean('persist')) {
            return $this->fail('حفظ الرد الآلي غير مدعوم من هذا المسار.', 422);
        }

        $latestInbound = Message::query()
            ->where('conversation_id', $conversation->id)
            ->where('direction', 'inbound')
            ->latest('id')
            ->first();

        $override = trim((string) ($validated['content'] ?? ''));
        $inbound = $latestInbound instanceof Message && ($override === '' || $override === trim((string) $latestInbound->content))
            ? $latestInbound
            : new Message([
                'content' => $this->safety->truncateInput($override !== '' ? $override : (string) ($latestInbound?->content ?? '')),
                'direction' => 'inbound',
                'conversation_id' => $conversation->id,
                'workspace_id' => $conversation->workspace_id,
            ]);
        if (! $inbound->exists) {
            $inbound->setRelation('conversation', $conversation);
        }

        if (! $this->workspaceAssistantEnabled((int) $workspace->id)) {
            return $this->fail('المساعد غير مفعّل.', 422);
        }

        try {
            $suggestion = $this->aiService->generateReply($conversation, $inbound);
        } catch (\Throwable) {
            return $this->fail('تعذر توليد اقتراح الرد حالياً.', 503);
        }

        if ($suggestion === '') {
            return $this->fail('المساعد غير مفعّل.', 422);
        }

        return $this->ok([
            'suggestion' => $suggestion,
            'meta' => ['persisted' => false],
        ]);
    }

    public function summarizeConversation(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'conversation_id' => ['required', 'integer'],
            'limit' => ['nullable', 'integer', 'min:1', 'max:50'],
        ]);

        $conversation = Conversation::query()->findOrFail($validated['conversation_id']);
        $this->authorize('view', $conversation);
        $workspace = $this->requireWorkspace($this->workspaceContext);
        if ((int) $conversation->workspace_id !== (int) $workspace->id) {
            abort(404);
        }

        $limit = max(1, min(20, (int) ($validated['limit'] ?? 12)));

        $messages = Message::query()
            ->where('conversation_id', $conversation->id)
            ->orderByDesc('id')
            ->limit($limit)
            ->get(['id', 'content', 'direction', 'created_at'])
            ->reverse()
            ->values();

        $transcript = $this->safety->truncateInput(
            $messages
                ->map(fn (Message $message): string => trim((string) $message->content))
                ->filter()
                ->implode("\n")
        );

        if ($transcript === '') {
            return $this->ok([
                'summary' => 'لا توجد رسائل كافية للتلخيص.',
                'meta' => ['source' => 'local', 'message_count' => 0],
            ]);
        }

        $user = $request->user();

        if ($user && $this->featureAccessService->hasFeature($user, $workspace, 'ai')) {
            $synthetic = new Message([
                'content' => 'لخص المحادثة الحالية باختصار بالعربية اعتمادًا على الرسائل المتاحة فقط.',
                'direction' => 'inbound',
                'conversation_id' => $conversation->id,
                'workspace_id' => $conversation->workspace_id,
            ]);
            $synthetic->setRelation('conversation', $conversation);

            if ($this->workspaceAssistantEnabled((int) $workspace->id)) {
                try {
                    $summary = $this->aiService->generateReply($conversation, $synthetic);
                    if ($summary !== '') {
                        return $this->ok([
                            'summary' => $summary,
                            'meta' => [
                                'source' => 'ai',
                                'message_count' => $messages->count(),
                            ],
                        ]);
                    }
                } catch (\Throwable) {
                    // fall through to local summary
                }
            }
        }

        return $this->ok([
            'summary' => Str::limit($transcript, 500),
            'meta' => [
                'source' => 'local',
                'message_count' => $messages->count(),
            ],
        ]);
    }

    private function workspaceAssistantEnabled(int $workspaceId): bool
    {
        $setting = AiSetting::withoutGlobalScopes()
            ->where('workspace_id', $workspaceId)
            ->first();

        return $setting === null || (bool) $setting->is_active;
    }
}
