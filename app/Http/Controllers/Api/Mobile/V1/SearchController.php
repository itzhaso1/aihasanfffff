<?php

namespace App\Http\Controllers\Api\Mobile\V1;

use App\Http\Controllers\Api\Mobile\Concerns\ResolvesMobileWorkspace;
use App\Http\Controllers\Api\Mobile\MobileController;
use App\Http\Resources\Mobile\ConversationResource;
use App\Http\Resources\Mobile\CustomerResource;
use App\Http\Resources\Mobile\EmailMessageResource;
use App\Models\Conversation;
use App\Models\Customer;
use App\Models\EmailMessage;
use App\Services\Mobile\ConversationInboxService;
use App\Support\Tenancy\WorkspaceContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Schema;

class SearchController extends MobileController
{
    use ResolvesMobileWorkspace;

    public function __construct(
        private readonly ConversationInboxService $conversationInboxService,
        private readonly WorkspaceContext $workspaceContext,
    ) {}

    public function search(Request $request): JsonResponse
    {
        $user = $request->user();
        if (! $user) {
            return $this->fail('غير مصرح.', 401);
        }

        $validated = $request->validate([
            'q' => ['required', 'string', 'min:1', 'max:255'],
            'limit' => ['nullable', 'integer', 'min:1', 'max:30'],
        ]);

        $workspace = $this->requireWorkspace($this->workspaceContext);
        $query = trim($validated['q']);
        $limit = (int) ($validated['limit'] ?? 10);

        $conversations = Conversation::query()
            ->with(['customer:id,name,phone,email', 'messages' => fn ($q) => $q->latest('id')->limit(1)])
            ->where(function ($builder) use ($query): void {
                $builder->where('external_id', 'like', '%'.$query.'%')
                    ->orWhereHas('customer', fn ($customer) => $customer
                        ->where('name', 'like', '%'.$query.'%')
                        ->orWhere('phone', 'like', '%'.$query.'%')
                        ->orWhere('email', 'like', '%'.$query.'%'))
                    ->orWhereHas('messages', fn ($messages) => $messages->where('content', 'like', '%'.$query.'%'));
            })
            ->orderByDesc('last_message_at')
            ->limit($limit)
            ->get()
            ->map(function (Conversation $conversation) use ($user): Conversation {
                $conversation->unread_count = $this->conversationInboxService->unreadCountForConversation($conversation, $user);
                $conversation->last_message = $conversation->messages->first();

                return $conversation;
            });

        $customers = Customer::query()
            ->where(function ($builder) use ($query): void {
                $builder->where('name', 'like', '%'.$query.'%')
                    ->orWhere('phone', 'like', '%'.$query.'%')
                    ->orWhere('email', 'like', '%'.$query.'%');
            })
            ->orderBy('name')
            ->limit($limit)
            ->get();

        $emails = collect();
        if (Schema::hasTable('email_messages')) {
            $emails = EmailMessage::query()
                ->with(['account:id,name,email'])
                ->where(function ($builder) use ($query): void {
                    $builder->where('subject', 'like', '%'.$query.'%')
                        ->orWhere('sender', 'like', '%'.$query.'%')
                        ->orWhere('recipient', 'like', '%'.$query.'%')
                        ->orWhere('body', 'like', '%'.$query.'%');
                })
                ->orderByDesc('id')
                ->limit($limit)
                ->get();
        }

        return $this->ok([
            'conversations' => ConversationResource::collection($conversations),
            'customers' => CustomerResource::collection($customers),
            'emails' => EmailMessageResource::collection($emails),
        ], [
            'query' => $query,
            'workspace_id' => $workspace->id,
        ]);
    }
}
