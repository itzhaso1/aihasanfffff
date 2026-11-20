<?php

namespace App\Http\Controllers\Api\Mobile\V1;

use App\Enums\CustomerStatus;
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

    public function stats(Request $request): JsonResponse
    {
        $this->authorize('viewAny', Customer::class);

        $stats = [
            'total' => Customer::query()->count(),
            'needs_review' => Customer::query()->where('status', CustomerStatus::NeedsReview->value)->count(),
            'completed' => Customer::query()->where('status', CustomerStatus::Completed->value)->count(),
            'awaiting_payment' => Customer::query()->where('status', CustomerStatus::AwaitingPayment->value)->count(),
            'paid' => Customer::query()->where('status', CustomerStatus::Paid->value)->count(),
            'in_progress' => Customer::query()->where('status', CustomerStatus::InProgress->value)->count(),
            'delivered' => Customer::query()->where('status', CustomerStatus::Delivered->value)->count(),
            'cancelled' => Customer::query()->where('status', CustomerStatus::Cancelled->value)->count(),
        ];

        return $this->ok($stats);
    }

    public function index(Request $request): JsonResponse
    {
        $this->authorize('viewAny', Customer::class);
        $search = $request->string('search')->trim()->toString();
        $status = $request->string('status')->trim()->toString();
        $perPage = max(1, min(50, (int) $request->input('per_page', 20)));

        $paginator = Customer::query()
            ->when($search !== '', function ($q) use ($search) {
                $q->where(function ($sub) use ($search) {
                    $sub->where('name', 'like', "%{$search}%")
                        ->orWhere('phone', 'like', "%{$search}%")
                        ->orWhere('email', 'like', "%{$search}%");
                });
            })
            ->when($status !== '', function ($q) use ($status) {
                $q->where('status', $status);
            })
            ->latest('id')
            ->cursorPaginate($perPage);

        return $this->ok(
            CustomerResource::collection($paginator->items()),
            $this->cursorMeta(
                $paginator->nextCursor()?->encode(),
                $paginator->previousCursor()?->encode(),
                $perPage,
            ),
        );
    }

    public function store(Request $request): JsonResponse
    {
        $this->authorize('create', Customer::class);

        $validated = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'phone' => ['nullable', 'string', 'max:50'],
            'status' => ['nullable', 'string', 'in:needs_review,completed,awaiting_payment,paid,in_progress,delivered,cancelled'],
            'email' => ['nullable', 'email', 'max:255'],
            'whatsapp' => ['nullable', 'string', 'max:50'],
            'city' => ['nullable', 'string', 'max:100'],
            'notes' => ['nullable', 'string'],
        ]);

        $workspace = $this->requireWorkspace($this->workspaceContext);
        $validated['workspace_id'] = $workspace->id;

        $notes = $validated['notes'] ?? null;
        unset($validated['notes']);

        $customer = Customer::create($validated);

        if ($notes) {
            $customer->notes()->create([
                'workspace_id' => $workspace->id,
                'user_id' => $request->user()?->id,
                'body' => $notes,
            ]);
        }

        return $this->ok(new CustomerResource($customer), message: 'تم إنشاء العميل بنجاح.', status: 201);
    }

    public function show(Customer $customer): JsonResponse
    {
        $this->authorize('view', $customer);
        $customer->loadMissing(['notes.user']);

        return $this->ok(new CustomerResource($customer));
    }

    public function update(Request $request, Customer $customer): JsonResponse
    {
        $this->authorize('update', $customer);

        $validated = $request->validate([
            'name' => ['sometimes', 'required', 'string', 'max:255'],
            'phone' => ['nullable', 'string', 'max:50'],
            'status' => ['nullable', 'string', 'in:needs_review,completed,awaiting_payment,paid,in_progress,delivered,cancelled'],
            'email' => ['nullable', 'email', 'max:255'],
            'whatsapp' => ['nullable', 'string', 'max:50'],
            'city' => ['nullable', 'string', 'max:100'],
        ]);

        $customer->update($validated);

        return $this->ok(new CustomerResource($customer), message: 'تم تحديث بيانات العميل بنجاح.');
    }

    public function storeNote(Request $request, Customer $customer): JsonResponse
    {
        $this->authorize('update', $customer);

        $validated = $request->validate([
            'body' => ['required', 'string'],
        ]);

        $note = $customer->notes()->create([
            'workspace_id' => $customer->workspace_id,
            'user_id' => $request->user()?->id,
            'body' => $validated['body'],
        ]);

        return $this->ok($note, message: 'تم إضافة الملاحظة بنجاح.', status: 201);
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

        $paginatorItems = $paginator->items();
        $unreadCounts = $this->conversationInboxService->unreadCountsForConversations($paginatorItems, $user);

        $items = collect($paginatorItems)->map(function ($conversation) use ($unreadCounts) {
            $conversation->unread_count = $unreadCounts[$conversation->id] ?? 0;
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

    public function destroy(Customer $customer): JsonResponse
    {
        $this->authorize('delete', $customer);
        $customer->delete();

        return $this->ok(message: 'تم حذف العميل بنجاح.');
    }
}
