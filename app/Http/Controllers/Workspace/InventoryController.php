<?php

namespace App\Http\Controllers\Workspace;

use App\Http\Controllers\Controller;
use App\Http\Requests\Inventory\AdjustInventoryRequest;
use App\Models\InventoryMovement;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Services\Inventory\InventoryService;
use App\Support\Authorization\WorkspaceAccess;
use App\Support\Tenancy\WorkspaceContext;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

class InventoryController extends Controller
{
    public function __construct(
        private readonly InventoryService $inventoryService,
        private readonly WorkspaceAccess $workspaceAccess,
    ) {}

    public function index(Request $request): View
    {
        $this->authorizeInventory($request, manage: false);

        $movements = InventoryMovement::query()
            ->with(['product', 'variant', 'user'])
            ->when($request->string('search')->toString(), function ($query, $search): void {
                $query->where(function ($inner) use ($search): void {
                    $inner->where('reference_type', 'like', '%'.$search.'%')
                        ->orWhere('notes', 'like', '%'.$search.'%');
                });
            })
            ->latest('id')
            ->paginate(20)
            ->withQueryString();

        return view('workspace.inventory.index', compact('movements'));
    }

    public function create(Request $request): View
    {
        $this->authorizeInventory($request, manage: true);

        $search = trim((string) $request->string('q'));

        $products = Product::query()
            ->when($search !== '', fn ($query) => $query->where('name', 'like', '%'.$search.'%'))
            ->orderBy('name')
            ->limit(100)
            ->get(['id', 'name', 'stock']);

        $productIds = $products->pluck('id')->all();

        $variants = ProductVariant::query()
            ->when($productIds !== [], fn ($query) => $query->whereIn('product_id', $productIds))
            ->when($productIds === [] && $search !== '', fn ($query) => $query->where('name', 'like', '%'.$search.'%'))
            ->orderBy('name')
            ->limit(200)
            ->get(['id', 'product_id', 'name', 'stock']);

        return view('workspace.inventory.create', [
            'products' => $products,
            'variants' => $variants,
            'search' => $search,
        ]);
    }

    public function store(AdjustInventoryRequest $request): RedirectResponse
    {
        $this->inventoryService->adjustStock(
            productId: $request->integer('product_id'),
            variantId: $request->integer('product_variant_id') ?: null,
            type: $request->string('type')->toString(),
            quantity: $request->integer('quantity'),
            actor: $request->user(),
            referenceType: $request->string('reference_type')->toString() ?: null,
            referenceId: $request->integer('reference_id') ?: null,
            notes: $request->string('notes')->toString() ?: null,
        );

        return redirect()->route('workspace.inventory.index')->with('success', 'تم تسجيل حركة المخزون.');
    }

    private function authorizeInventory(Request $request, bool $manage): void
    {
        $user = $request->user();
        abort_unless($user, 403);

        $workspace = app(WorkspaceContext::class)->workspace();
        abort_unless($workspace, 422, 'Workspace is not resolved.');

        $allowed = $manage
            ? $this->workspaceAccess->canManageInventory($user, $workspace)
            : $this->workspaceAccess->canViewInventory($user, $workspace);

        abort_unless($allowed, 403);
    }
}
