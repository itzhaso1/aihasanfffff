<?php

declare(strict_types=1);

namespace App\Http\Controllers\Workspace;

use App\Http\Controllers\Controller;
use App\Http\Controllers\Workspace\Concerns\InteractsWithWorkspace;
use App\Models\Conversation;
use App\Models\CustomerLocation;
use App\Models\Order;
use App\Services\Channel\Outbound\ChannelOutboundService;
use App\Services\Conversation\ConversationService;
use App\Services\Inbox\InboxRealtimePublisher;
use App\Services\Location\LocationCaptureService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

class ConversationLocationController extends Controller
{
    use InteractsWithWorkspace;

    public function __construct(
        private readonly LocationCaptureService $captureService,
        private readonly ConversationService $conversationService,
    ) {}

    public function requestCapture(Request $request, Conversation $conversation): JsonResponse|RedirectResponse
    {
        $this->authorize('reply', $conversation);
        $workspace = $this->currentWorkspace();

        $validated = $request->validate([
            'order_id' => ['nullable', 'integer'],
            'purpose' => ['nullable', 'string', 'in:delivery,update_delivery'],
        ]);

        $order = null;
        if (! empty($validated['order_id'])) {
            $order = Order::query()
                ->where('workspace_id', $workspace->id)
                ->find((int) $validated['order_id']);
        }

        $issued = $this->captureService->createCaptureSession(
            workspace: $workspace,
            conversation: $conversation,
            order: $order,
            purpose: (string) ($validated['purpose'] ?? 'delivery'),
        );

        $message = $this->conversationService->addMessage($conversation, [
            'workspace_id' => $workspace->id,
            'customer_id' => $conversation->customer_id,
            'direction' => 'outbound',
            'message_type' => 'text',
            'content' => $issued['prompt_message'],
            'metadata' => [
                'location_capture_session_id' => $issued['session']->id,
                'location_capture_url' => $issued['capture_url'],
            ],
        ], $request->user());

        app(ChannelOutboundService::class)->queueOutboundForMessage($conversation, $message);
        app(InboxRealtimePublisher::class)->publishNewMessage($message, $conversation->fresh() ?? $conversation);

        if ($request->expectsJson()) {
            return response()->json([
                'success' => true,
                'capture_url' => $issued['capture_url'],
                'expires_at' => $issued['expires_at'],
                'message_id' => $message->id,
                'content' => $message->content,
            ], 201);
        }

        return redirect()
            ->route('workspace.conversations.index', ['conversation' => $conversation->id])
            ->with('success', 'تم إرسال رابط تحديد الموقع إلى العميل.');
    }

    public function updateVerification(Request $request, CustomerLocation $location): JsonResponse|RedirectResponse
    {
        $workspace = $this->currentWorkspace();
        abort_if((int) $location->workspace_id !== (int) $workspace->id, 404);

        if ($location->conversation) {
            $this->authorize('update', $location->conversation);
        } elseif ($location->customer) {
            $this->authorize('update', $location->customer);
        }

        $validated = $request->validate([
            'verification_status' => ['required', 'string', 'in:confirmed,needs_adjustment,pending_confirmation'],
        ]);

        $updated = $this->captureService->updateVerificationStatus(
            $workspace,
            $location,
            (string) $validated['verification_status'],
            $request->user(),
        );

        if ($request->expectsJson()) {
            return response()->json([
                'success' => true,
                'location' => $updated->toSummaryArray(),
            ]);
        }

        return back()->with('success', 'تم تحديث حالة توثيق الموقع.');
    }
}
