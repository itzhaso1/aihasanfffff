<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\Mobile\V1;

use App\Http\Controllers\Api\Mobile\Concerns\ResolvesMobileWorkspace;
use App\Http\Controllers\Api\Mobile\MobileController;
use App\Http\Resources\Mobile\MessageResource;
use App\Models\Conversation;
use App\Models\CustomerLocation;
use App\Models\Order;
use App\Services\Channel\Outbound\ChannelOutboundService;
use App\Services\Conversation\ConversationService;
use App\Services\Inbox\InboxRealtimePublisher;
use App\Services\Location\LocationCaptureService;
use App\Services\Mobile\MobileInboxActor;
use App\Support\Tenancy\WorkspaceContext;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class ConversationLocationController extends MobileController
{
    use ResolvesMobileWorkspace;

    public function __construct(
        private readonly LocationCaptureService $captureService,
        private readonly ConversationService $conversationService,
        private readonly WorkspaceContext $workspaceContext,
        private readonly MobileInboxActor $inboxActor,
    ) {}

    public function requestCapture(Request $request, Conversation $conversation): JsonResponse
    {
        $user = $request->user();
        if (! $user) {
            return $this->fail('غير مصرح.', 401);
        }

        $workspace = $this->requireWorkspace($this->workspaceContext);
        try {
            $this->inboxActor->assertSendable($user, $conversation);
        } catch (ModelNotFoundException) {
            return $this->fail('العنصر المطلوب غير موجود.', 404);
        } catch (AuthorizationException) {
            return $this->fail('غير مصرح لك بهذا الإجراء.', 403);
        }

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
        ], $user);

        app(ChannelOutboundService::class)->queueOutboundForMessage($conversation, $message);
        app(InboxRealtimePublisher::class)->publishNewMessage($message, $conversation->fresh() ?? $conversation);

        return $this->ok(
            data: [
                'capture_url' => $issued['capture_url'],
                'expires_at' => $issued['expires_at'],
                'platform' => $issued['platform'],
                'supports_native_location' => $issued['supports_native_location'],
                'message' => new MessageResource($message->load(['attachments', 'user', 'customer'])),
            ],
            message: 'تم إنشاء وإرسال رابط تحديد الموقع.',
            status: 201,
        );
    }

    public function updateVerification(Request $request, CustomerLocation $location): JsonResponse
    {
        $user = $request->user();
        if (! $user) {
            return $this->fail('غير مصرح.', 401);
        }

        $workspace = $this->requireWorkspace($this->workspaceContext);
        if ((int) $location->workspace_id !== (int) $workspace->id) {
            return $this->fail('العنصر المطلوب غير موجود.', 404);
        }

        $validated = $request->validate([
            'verification_status' => ['required', 'string', 'in:confirmed,needs_adjustment,pending_confirmation'],
        ]);

        $updated = $this->captureService->updateVerificationStatus(
            $workspace,
            $location,
            (string) $validated['verification_status'],
            $user,
        );

        return $this->ok(
            data: ['location' => $updated->toSummaryArray()],
            message: 'تم تحديث حالة الموقع.',
        );
    }
}
