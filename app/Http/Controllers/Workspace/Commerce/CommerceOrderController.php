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
        abort_unless($request->user() && $this->access->canViewOrders($request->user(), $workspace), 403);

        $orders = Order::query()
            ->where('source', 'commerce')
            ->with(['customer', 'sellerAllocations.seller'])
            ->withCount(['items', 'inventoryReservations'])
            ->when(
                $request->filled('status'),
                fn ($query) => $query->where('commerce_status', $request->string('status')->toString()),
            )
            ->orderByDesc('id')
            ->paginate(30)
            ->withQueryString();

        return view('workspace.commerce.orders.index', compact('orders'));
    }

    public function show(Request $request, Order $order): View
    {
        $workspace = $this->currentWorkspace();
        abort_unless($request->user() && $this->access->canViewOrders($request->user(), $workspace), 403);
        $this->assertSameWorkspace($order->workspace_id);
        abort_unless($order->source === 'commerce', 404);

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
