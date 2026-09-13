<?php

namespace App\Http\Controllers\Api\Mobile\V1;

use App\Http\Controllers\Api\Mobile\MobileController;
use App\Http\Resources\Mobile\MessageAttachmentResource;
use App\Models\Message;
use App\Models\MessageAttachment;
use App\Services\Mobile\MessageAttachmentService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class AttachmentController extends MobileController
{
    public function __construct(
        private readonly MessageAttachmentService $messageAttachmentService,
    ) {}

    public function store(Request $request, Message $message): JsonResponse
    {
        $this->authorize('update', $message->conversation);

        $request->validate([
            'file' => ['required', 'file', 'max:10240'],
        ]);

        try {
            $attachment = $this->messageAttachmentService->upload(
                $message,
                $request->file('file'),
                $request->user(),
            );
        } catch (\InvalidArgumentException $exception) {
            return $this->fail($exception->getMessage(), 422);
        } catch (\RuntimeException $exception) {
            return $this->fail($exception->getMessage(), 500);
        }

        return $this->ok(
            new MessageAttachmentResource($attachment),
            message: 'تم رفع المرفق بنجاح.',
            status: 201,
        );
    }

    public function download(Request $request, int $attachment)
    {
        if (! $request->hasValidSignature()) {
            return $this->fail('رابط التحميل غير صالح أو منتهي.', 403);
        }

        $workspaceId = (int) $request->query('workspace');
        if ($workspaceId <= 0) {
            return $this->fail('مساحة العمل غير محددة.', 403);
        }

        try {
            $record = $this->messageAttachmentService->findOwned($attachment, $workspaceId);
        } catch (\RuntimeException) {
            return $this->fail('المرفق غير موجود.', 404);
        }

        app(\App\Support\Tenancy\WorkspaceContext::class)->runForId($workspaceId, function () use ($request, $record) {
            if ($request->user()) {
                $record->loadMissing('message.conversation');
                $this->authorize('view', $record->message->conversation);
            }
        });

        try {
            return app(\App\Support\Tenancy\WorkspaceContext::class)->runForId(
                $workspaceId,
                fn () => $this->messageAttachmentService->stream($record)
            );
        } catch (\RuntimeException $exception) {
            return $this->fail($exception->getMessage(), 404);
        }
    }
}
