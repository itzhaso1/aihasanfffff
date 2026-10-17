<?php

namespace App\Http\Controllers\Api\Mobile\V1;

use App\Http\Controllers\Api\Mobile\Concerns\ResolvesMobileWorkspace;
use App\Http\Controllers\Api\Mobile\MobileController;
use App\Models\ConversationBulkSend;
use App\Services\Inbox\ConversationBulkMessageService;
use App\Support\Tenancy\WorkspaceContext;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class ConversationBulkMessageController extends MobileController
{
    use ResolvesMobileWorkspace;

    public function __construct(
        private readonly ConversationBulkMessageService $bulkMessages,
        private readonly WorkspaceContext $workspaceContext,
    ) {}

    public function store(Request $request): JsonResponse
    {
        $workspace = $this->requireWorkspace($this->workspaceContext);
        $validated = $request->validate([
            'conversation_ids' => ['nullable', 'array', 'max:500'],
            'conversation_ids.*' => ['integer', 'distinct'],
            'select_all' => ['nullable', 'boolean'],
            'filters' => ['nullable', 'array'],
            'filters.filter' => ['nullable', 'in:all,unread,archived'],
            'filters.channel' => ['nullable', 'string', 'max:64'],
            'filters.search' => ['nullable', 'string', 'max:255'],
            'filters.handoff' => ['nullable', 'string', 'max:32'],
            'filters.customer_priority' => ['nullable', 'string', 'max:32'],
            'filters.payment_status' => ['nullable', 'string', 'max:32'],
            'filters.review_status' => ['nullable', 'string', 'max:32'],
            'content' => ['required', 'string', 'max:4000'],
        ]);
        $selectAll = (bool) ($validated['select_all'] ?? false);
        $ids = array_map('intval', $validated['conversation_ids'] ?? []);
        if (! $selectAll && $ids === []) {
            return $this->fail('اختر محادثة واحدة على الأقل أو فعّل تحديد الكل.', 422);
        }

        try {
            $bulk = $this->bulkMessages->create(
                $request->user(),
                $workspace,
                $ids,
                $selectAll,
                $validated['filters'] ?? [],
                $validated['content'],
            );
        } catch (ModelNotFoundException) {
            return $this->fail('إحدى المحادثات غير موجودة أو لا تملك صلاحية الوصول إليها.', 404);
        } catch (AuthorizationException) {
            return $this->fail('لا تملك صلاحية تنفيذ هذا الإجراء.', 403);
        } catch (\InvalidArgumentException $exception) {
            return $this->fail($exception->getMessage(), 422);
        }

        return $this->ok($this->summary($bulk), message: 'تمت جدولة الإرسال الجماعي.', status: 202);
    }

    public function show(Request $request, string $bulkSend): JsonResponse
    {
        try {
            $workspace = $this->requireWorkspace($this->workspaceContext);

            return $this->ok(
                $this->bulkMessages->status($request->user(), $workspace, $bulkSend),
            );
        } catch (ModelNotFoundException) {
            return $this->fail('عملية الإرسال غير موجودة.', 404);
        }
    }

    /**
     * @return array<string, mixed>
     */
    private function summary(ConversationBulkSend $bulk): array
    {
        return [
            'bulk_send_id' => (string) $bulk->id,
            'queued_count' => $bulk->queued_count,
            'skipped_count' => $bulk->skipped_count,
            'selection_limited' => (bool) data_get($bulk->metadata, 'selection_limited', false),
            'skipped_preview' => $bulk->items
                ->where('status', 'skipped')
                ->take(20)
                ->map(fn ($item): array => [
                    'conversation_id' => $item->conversation_id,
                    'reason' => $item->error_code,
                    'message' => $item->error_message,
                ])
                ->values()
                ->all(),
        ];
    }
}
