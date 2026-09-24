<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\Ai\GenerateReplyRequest;
use App\Http\Requests\Ai\UpdateAiSettingRequest;
use App\Http\Resources\Mobile\AiSettingResource;
use App\Models\Conversation;
use App\Models\Message;
use App\Services\AI\AIService;
use App\Services\AI\AiSettingManagementService;
use App\Services\Feature\FeatureAccessService;
use App\Support\Authorization\WorkspaceAccess;
use App\Support\Tenancy\WorkspaceContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class AiController extends Controller
{
    public function __construct(
        private readonly AIService $aiService,
        private readonly AiSettingManagementService $aiSettings,
        private readonly FeatureAccessService $featureAccess,
        private readonly WorkspaceContext $workspaceContext,
        private readonly WorkspaceAccess $workspaceAccess,
    ) {}

    public function settings(Request $request): JsonResponse
    {
        $workspace = $this->requireWorkspace();
        $user = $request->user();
        if (! $user || ! $this->workspaceAccess->canManageAiSettings($user, $workspace)) {
            abort(403);
        }

        $setting = $this->aiSettings->forWorkspace($workspace);

        return response()->json(['data' => new AiSettingResource($setting)]);
    }

    public function updateSettings(UpdateAiSettingRequest $request): JsonResponse
    {
        $workspace = $this->requireWorkspace();
        $setting = $this->aiSettings->update($workspace, $request->validated());

        return response()->json(['data' => new AiSettingResource($setting)]);
    }

    public function generateReply(GenerateReplyRequest $request): JsonResponse
    {
        $conversation = Conversation::query()->findOrFail($request->integer('conversation_id'));
        $this->authorize('update', $conversation);
        $currentWorkspaceId = $this->workspaceContext->workspaceId();
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

    private function requireWorkspace()
    {
        $workspace = $this->workspaceContext->workspace();
        abort_if($workspace === null, 403);

        return $workspace;
    }
}
