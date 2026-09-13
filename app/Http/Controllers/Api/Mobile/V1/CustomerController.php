<?php

namespace App\Http\Controllers\Api\Mobile\V1;

use App\Http\Controllers\Api\Mobile\Concerns\ResolvesMobileWorkspace;
use App\Http\Controllers\Api\Mobile\MobileController;
use App\Http\Resources\Mobile\ConversationResource;
use App\Http\Resources\Mobile\CustomerResource;
use App\Models\Customer;
use App\Services\Mobile\ConversationInboxService;
use App\Support\Tenancy\WorkspaceContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class CustomerController extends MobileController
{
    use ResolvesMobileWorkspace;

    public function __construct(
        private readonly ConversationInboxService $conversationInboxService,
        private readonly WorkspaceContext $workspaceContext,
    ) {}

    public function show(Customer $customer): JsonResponse
    {
        $this->authorize('view', $customer);

        return $this->ok(new CustomerResource($customer));
    }

    public function conversations(Request $request, Customer $customer): JsonResponse
    {
        $this->authorize('view', $customer);

        $user = $request->user();
        $perPage = max(1, min(50, (int) $request->input('per_page', 20)));

        $paginator = $customer->conversations()
            ->with([
                'customer:id,name,phone,email',
                'messages' => fn ($q) => $q->latest('id')->limit(1),
            ])
            ->orderByDesc('last_message_at')
            ->orderByDesc('id')
            ->cursorPaginate($perPage);

        $items = collect($paginator->items())->map(function ($conversation) use ($user) {
            $conversation->unread_count = $this->conversationInboxService->unreadCountForConversation($conversation, $user);
            $conversation->last_message = $conversation->messages->first();

            return $conversation;
        });

        return $this->ok(
            ConversationResource::collection($items),
            $this->cursorMeta(
                $paginator->nextCursor()?->encode(),
                $paginator->previousCursor()?->encode(),
                $perPage,
            ),
        );
    }

}
