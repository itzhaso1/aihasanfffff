<?php

namespace App\Http\Controllers\Workspace\Commerce;

use App\Http\Controllers\Controller;
use App\Http\Controllers\Workspace\Concerns\InteractsWithWorkspace;
use App\Models\Commerce\CommerceCart;
use App\Support\Commerce\CommerceOrderAccess;
use Illuminate\Http\Request;
use Illuminate\View\View;

class CommerceCartController extends Controller
{
    use InteractsWithWorkspace;

    public function __construct(
        private readonly CommerceOrderAccess $access,
    ) {}

    public function index(Request $request): View
    {
        $workspace = $this->currentWorkspace();
        abort_unless($request->user() && $this->access->canViewCarts($request->user(), $workspace), 403);

        $carts = CommerceCart::query()
            ->with(['customer', 'seller', 'convertedOrder'])
            ->withCount('items')
            ->when(
                $request->filled('status'),
                fn ($query) => $query->where('status', $request->string('status')->toString()),
            )
            ->orderByDesc('id')
            ->paginate(30)
            ->withQueryString();

        return view('workspace.commerce.carts.index', compact('carts'));
    }

    public function show(Request $request, CommerceCart $cart): View
    {
        $workspace = $this->currentWorkspace();
        abort_unless($request->user() && $this->access->canViewCarts($request->user(), $workspace), 403);
        $this->assertSameWorkspace($cart->workspace_id);

        return view('workspace.commerce.carts.show', [
            'cart' => $cart->load([
                'customer',
                'seller',
                'convertedOrder',
                'items.seller',
                'items.item',
                'items.variant',
            ]),
        ]);
    }
}
