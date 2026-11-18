<?php

namespace App\Http\Controllers\Workspace\Commerce;

use App\Http\Controllers\Controller;
use App\Http\Controllers\Workspace\Concerns\InteractsWithWorkspace;
use App\Models\Order;
use App\Support\Commerce\CommerceOrderAccess;
use Illuminate\Http\Request;
use Illuminate\View\View;

class CommerceOrderController extends Controller
{
    use InteractsWithWorkspace;

    public function __construct(
        private readonly CommerceOrderAccess $access,
    ) {}

    public function index(Request $request): View
    {
        $workspace = $this->currentWorkspace();
        $workspaceId = (int) $workspace->id;
        abort_unless($request->user() && $this->access->canViewOrders($request->user(), $workspace), 403);

        $search = $request->string('search')->toString();
        $status = $request->string('status')->toString();

        $baseQuery = Order::query()->where('workspace_id', $workspaceId);

        $stats = [
            'total' => (clone $baseQuery)->count(),
            'pending_payment' => (clone $baseQuery)->where(function ($q): void {
                $q->where('commerce_status', 'pending_payment')->orWhere('payment_status', 'pending');
            })->count(),
            'completed' => (clone $baseQuery)->whereIn('status', ['completed', 'confirmed'])->count(),
            'cancelled' => (clone $baseQuery)->where(function ($q): void {
                $q->where('commerce_status', 'cancelled')->orWhere('status', 'cancelled');
            })->count(),
            'needs_review' => (clone $baseQuery)->where(function ($q): void {
                $q->where('status', 'draft')->orWhereNotNull('review_reason');
            })->count(),
            'total_amount' => (float) (clone $baseQuery)->sum('total_amount'),
        ];

        $orders = Order::query()
            ->where('workspace_id', $workspaceId)
            ->with(['customer', 'items', 'sellerAllocations.seller'])
            ->withCount(['items', 'inventoryReservations'])
            ->when($status, function ($query, $status): void {
                if ($status === 'needs_review') {
                    $query->where(function ($q): void {
                        $q->where('status', 'draft')->orWhereNotNull('review_reason');
                    });
                } elseif ($status === 'pending_payment') {
                    $query->where(function ($q): void {
                        $q->where('commerce_status', 'pending_payment')->orWhere('payment_status', 'pending');
                    });
                } elseif ($status === 'paid') {
                    $query->where('payment_status', 'paid');
                } elseif ($status === 'completed') {
                    $query->whereIn('status', ['completed', 'confirmed']);
                } elseif ($status === 'cancelled') {
                    $query->where(function ($q): void {
                        $q->where('commerce_status', 'cancelled')->orWhere('status', 'cancelled');
                    });
                } else {
                    $query->where(function ($q) use ($status): void {
                        $q->where('commerce_status', $status)->orWhere('status', $status);
                    });
                }
            })
            ->when($search, function ($query, $search): void {
                $query->where(function ($q) use ($search): void {
                    $q->where('order_number', 'like', "%{$search}%")
                        ->orWhereHas('customer', function ($cq) use ($search): void {
                            $cq->where('name', 'like', "%{$search}%")
                                ->orWhere('phone', 'like', "%{$search}%");
                        });
                });
            })
            ->orderByDesc('id')
            ->paginate(25)
            ->withQueryString();

        return view('workspace.commerce.orders.index', compact('orders', 'stats', 'status', 'search'));
    }

    public function show(Request $request, Order $order): View
    {
        $workspace = $this->currentWorkspace();
        abort_unless($request->user() && $this->access->canViewOrders($request->user(), $workspace), 403);
        $this->assertSameWorkspace($order->workspace_id);

        return view('workspace.commerce.orders.show', [
            'order' => $order->load([
                'customer',
                'commerceCart',
                'items.commerceSeller',
                'sellerAllocations.seller',
                'inventoryReservations',
            ]),
        ]);
    }
}
