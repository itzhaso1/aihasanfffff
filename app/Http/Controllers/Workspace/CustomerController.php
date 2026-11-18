<?php

namespace App\Http\Controllers\Workspace;

use App\Enums\CustomerStatus;
use App\Http\Controllers\Controller;
use App\Http\Controllers\Workspace\Concerns\InteractsWithWorkspace;
use App\Http\Requests\Customer\StoreCustomerRequest;
use App\Http\Requests\Customer\UpdateCustomerRequest;
use App\Models\Customer;
use App\Models\CustomerGroup;
use App\Models\CustomerTag;
use App\Models\Order;
use App\Services\Customer\CustomerCrmService;
use Carbon\Carbon;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

class CustomerController extends Controller
{
    use InteractsWithWorkspace;

    public function __construct(
        private readonly CustomerCrmService $crm,
    ) {}

    public function index(Request $request): View
    {
        $this->authorize('viewAny', Customer::class);

        $tab = $request->string('tab')->trim()->toString();
        $tab = in_array($tab, ['needs_review', 'all'], true) ? $tab : 'all';

        // 1. Calculate KPI Statistics
        $stats = [
            'total' => Customer::query()->count(),
            'new' => Customer::query()->where('created_at', '>=', now()->subDays(30))->count(),
            'needs_review' => Customer::query()->where('status', CustomerStatus::NeedsReview->value)->count(),
            'completed' => Customer::query()->where('status', CustomerStatus::Completed->value)->count(),
            'awaiting_payment' => Customer::query()->where('status', CustomerStatus::AwaitingPayment->value)->count(),
            'in_progress' => Customer::query()->where('status', CustomerStatus::InProgress->value)->count(),
            'delivered' => Customer::query()->where('status', CustomerStatus::Delivered->value)->count(),
            'cancelled' => Customer::query()->where('status', CustomerStatus::Cancelled->value)->count(),
        ];

        // Also count pending review orders
        $pendingReviewOrdersCount = Order::query()
            ->whereNotNull('review_reason')
            ->whereNotIn('status', ['cancelled'])
            ->count();
        $stats['review_orders_count'] = $pendingReviewOrdersCount;

        // 2. Query for "Needs Review" queue tab
        $reviewOrders = null;
        if ($tab === 'needs_review') {
            $reviewOrders = Order::query()
                ->where(function ($query): void {
                    $query->whereNotNull('review_reason')
                        ->orWhereHas('customer', function ($cq): void {
                            $cq->where('status', CustomerStatus::NeedsReview->value);
                        });
                })
                ->whereNotIn('status', ['cancelled'])
                ->with([
                    'customer',
                    'conversation.messages' => function ($mq): void {
                        $mq->latest('id')->limit(1);
                    },
                    'items',
                ])
                ->latest('id')
                ->paginate(15, ['*'], 'review_page')
                ->withQueryString();
        }

        // 3. Query for Customers list
        $search = $request->string('search')->trim()->toString();
        $statusFilter = $request->string('status')->trim()->toString();
        $orderStatusFilter = $request->string('order_status')->trim()->toString();
        $dateFrom = $request->string('date_from')->trim()->toString();
        $dateTo = $request->string('date_to')->trim()->toString();
        $sort = $request->string('sort')->trim()->toString() ?: 'newest';

        $customersQuery = Customer::query()
            ->when($search !== '', function ($query) use ($search): void {
                $query->where(function ($inner) use ($search): void {
                    $inner->where('name', 'like', '%'.$search.'%')
                        ->orWhere('phone', 'like', '%'.$search.'%')
                        ->orWhere('email', 'like', '%'.$search.'%')
                        ->orWhereHas('orders', function ($oq) use ($search): void {
                            $oq->where('order_number', 'like', '%'.$search.'%');
                        });
                });
            })
            ->when($statusFilter !== '', function ($query) use ($statusFilter): void {
                $query->where('status', $statusFilter);
            })
            ->when($orderStatusFilter !== '', function ($query) use ($orderStatusFilter): void {
                $query->whereHas('orders', function ($oq) use ($orderStatusFilter): void {
                    $oq->where('status', $orderStatusFilter);
                });
            })
            ->when($dateFrom !== '', function ($query) use ($dateFrom): void {
                try {
                    $query->where('created_at', '>=', Carbon::parse($dateFrom)->startOfDay());
                } catch (\Throwable) {
                }
            })
            ->when($dateTo !== '', function ($query) use ($dateTo): void {
                try {
                    $query->where('created_at', '<=', Carbon::parse($dateTo)->endOfDay());
                } catch (\Throwable) {
                }
            });

        // Sorting
        match ($sort) {
            'most_orders' => $customersQuery->orderByDesc('orders_count')->orderByDesc('id'),
            'highest_spend' => $customersQuery->orderByDesc('total_purchases')->orderByDesc('id'),
            default => $customersQuery->latest('id'),
        };

        $customers = $customersQuery->paginate(12)->withQueryString();

        return view('workspace.customers.index', compact('customers', 'stats', 'tab', 'reviewOrders'));
    }

    public function show(Customer $customer): View
    {
        $this->authorize('view', $customer);

        $customer->load([
            'orders' => fn ($q) => $q->with('items')->latest('id'),
            'conversations' => fn ($q) => $q->with(['messages' => fn ($mq) => $mq->latest('id')->limit(1)])->latest('last_message_at'),
            'notes.user',
            'tags',
            'groups',
        ]);

        $timeline = $this->crm->timeline($customer);

        return view('workspace.customers.show', compact('customer', 'timeline'));
    }

    public function create(): View
    {
        $this->authorize('create', Customer::class);

        return view('workspace.customers.create');
    }

    public function store(StoreCustomerRequest $request): RedirectResponse
    {
        $this->authorize('create', Customer::class);

        $payload = $request->validated();
        $payload['metadata'] = $this->parseJsonField($request, 'metadata_json');
        $payload['party_type'] = $payload['party_type'] ?? Customer::PARTY_TYPE_INDIVIDUAL;
        $payload['status'] = $payload['status'] ?? CustomerStatus::NeedsReview->value;

        if (! empty($payload['country_code'])) {
            $payload['country_code'] = strtoupper((string) $payload['country_code']);
        }

        $customer = Customer::query()->create($payload);

        return redirect()->route('workspace.customers.show', $customer)->with('success', 'تم إنشاء العميل بنجاح.');
    }

    public function edit(Customer $customer): View
    {
        $this->authorize('update', $customer);

        return view('workspace.customers.edit', [
            'customer' => $customer,
            'metadataJson' => json_encode($customer->metadata ?? [], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE),
        ]);
    }

    public function update(UpdateCustomerRequest $request, Customer $customer): RedirectResponse
    {
        $this->authorize('update', $customer);

        $payload = $request->validated();
        $payload['metadata'] = $this->parseJsonField($request, 'metadata_json', $customer->metadata ?? []);
        if (! empty($payload['country_code'])) {
            $payload['country_code'] = strtoupper((string) $payload['country_code']);
        }
        $customer->update($payload);

        return redirect()->route('workspace.customers.show', $customer)->with('success', 'تم تحديث العميل بنجاح.');
    }

    public function updateStatus(Request $request, Customer $customer): RedirectResponse
    {
        $this->authorize('update', $customer);

        $validated = $request->validate([
            'status' => ['required', Rule::enum(CustomerStatus::class)],
            'note' => ['nullable', 'string', 'max:500'],
        ]);

        $newStatus = CustomerStatus::from($validated['status']);
        $oldStatus = $customer->statusEnum();

        $customer->update(['status' => $newStatus]);

        $noteText = 'تم تغيير حالة العميل من "'.$oldStatus->label().'" إلى "'.$newStatus->label().'".';
        if (! empty($validated['note'])) {
            $noteText .= ' (ملاحظة: '.$validated['note'].')';
        }

        $this->crm->addNote($customer, $noteText, $request->user());

        return back()->with('success', 'تم تحديث حالة العميل بنجاح.');
    }

    public function orderReviewAction(Request $request, Order $order): RedirectResponse
    {
        $this->authorize('update', $order->customer);

        $validated = $request->validate([
            'action' => ['required', Rule::in(['approve', 'reject', 'in_progress'])],
            'note' => ['nullable', 'string', 'max:500'],
        ]);

        $action = $validated['action'];
        $customer = $order->customer;

        match ($action) {
            'approve' => (function () use ($order, $customer): void {
                $order->update([
                    'status' => 'confirmed',
                    'review_reason' => null,
                ]);
                $customer?->update(['status' => CustomerStatus::AwaitingPayment]);
            })(),
            'reject' => (function () use ($order, $customer): void {
                $order->update([
                    'status' => 'cancelled',
                    'cancelled_at' => now(),
                    'review_reason' => 'تم رفض الطلب بواسطة الإدارة',
                ]);
                $customer?->update(['status' => CustomerStatus::Cancelled]);
            })(),
            'in_progress' => (function () use ($order, $customer): void {
                $order->update([
                    'status' => 'confirmed',
                    'fulfillment_status' => 'processing',
                    'review_reason' => null,
                ]);
                $customer?->update(['status' => CustomerStatus::InProgress]);
            })(),
        };

        if ($customer) {
            $actionLabel = match ($action) {
                'approve' => 'اعتماد الطلب '.$order->order_number,
                'reject' => 'رفض وإلغاء الطلب '.$order->order_number,
                'in_progress' => 'بدء تنفيذ الطلب '.$order->order_number,
            };
            $noteText = 'إجراء مراجعة: '.$actionLabel;
            if (! empty($validated['note'])) {
                $noteText .= ' - '.$validated['note'];
            }
            $this->crm->addNote($customer, $noteText, $request->user());
        }

        return back()->with('success', 'تم تنفيذ الإجراء بنجاح.');
    }

    public function destroy(Customer $customer): RedirectResponse
    {
        $this->authorize('delete', $customer);

        $customer->delete();

        return redirect()->route('workspace.customers.index')->with('success', 'تم حذف العميل.');
    }

    public function storeNote(Request $request, Customer $customer): RedirectResponse
    {
        $this->authorize('update', $customer);

        $validated = $request->validate([
            'body' => ['required', 'string', 'max:5000'],
        ]);

        $this->crm->addNote($customer, $validated['body'], $request->user());

        return back()->with('success', 'تمت إضافة الملاحظة.');
    }

    public function attachTag(Request $request, Customer $customer): RedirectResponse
    {
        $this->authorize('update', $customer);

        $validated = $request->validate([
            'tag_id' => ['required', 'integer'],
        ]);

        $tag = CustomerTag::query()
            ->where('workspace_id', $customer->workspace_id)
            ->whereKey($validated['tag_id'])
            ->firstOrFail();

        $this->crm->attachTag($customer, $tag);

        return back()->with('success', 'تم ربط الوسم بالعميل.');
    }

    public function detachTag(Customer $customer, CustomerTag $tag): RedirectResponse
    {
        $this->authorize('update', $customer);

        $this->crm->detachTag($customer, $tag);

        return back()->with('success', 'تم إزالة الوسم من العميل.');
    }

    public function attachGroup(Request $request, Customer $customer): RedirectResponse
    {
        $this->authorize('update', $customer);

        $validated = $request->validate([
            'group_id' => ['required', 'integer'],
        ]);

        $group = CustomerGroup::query()
            ->where('workspace_id', $customer->workspace_id)
            ->whereKey($validated['group_id'])
            ->firstOrFail();

        $this->crm->attachGroup($customer, $group);

        return back()->with('success', 'تم إضافة العميل إلى المجموعة.');
    }

    public function detachGroup(Customer $customer, CustomerGroup $group): RedirectResponse
    {
        $this->authorize('update', $customer);

        $this->crm->detachGroup($customer, $group);

        return back()->with('success', 'تم إزالة العميل من المجموعة.');
    }
}
