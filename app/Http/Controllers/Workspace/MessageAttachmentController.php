<?php

namespace App\Http\Controllers\Workspace;

use App\Http\Controllers\Controller;
use App\Models\MessageAttachment;
use App\Services\Mobile\MessageAttachmentService;
use App\Support\Tenancy\WorkspaceContext;
use Illuminate\Http\Request;

class MessageAttachmentController extends Controller
{
    public function download(Request $request, MessageAttachment $attachment)
    {
        $message = $attachment->message()->firstOrFail();
        $this->authorize('view', $message->conversation);

        return app(WorkspaceContext::class)->runForId((int) $attachment->workspace_id, function () use ($attachment) {
            return app(MessageAttachmentService::class)->stream($attachment);
        });
    }
}
