<?php

namespace App\Http\Controllers\Api\Finance\V1;

use App\Http\Controllers\Api\Finance\Concerns\HandlesFinanceClient;
use App\Http\Controllers\Api\Finance\FinanceApiController;
use App\Models\Finance\FinanceInvoiceItem;
use App\Models\InventoryMovement;
use App\Models\Product;
use App\Services\Finance\Api\FinanceClientPresenter;
use App\Support\Tenancy\WorkspaceContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class CatalogController extends FinanceApiController
{
    use HandlesFinanceClient;

    public function __construct(
        private readonly WorkspaceContext $workspaceContext,
        private readonly FinanceClientPresenter $presenter,
    ) {}

    public function products(Request $request): JsonResponse
    {
        $workspace = $this->clientWorkspace($this->workspaceContext);
        $this->clientActor($request, $workspace, 'finance.view');

        $validated = $request->validate([
            'search' => ['nullable', 'string', 'max:255'],
            'per_page' => ['sometimes', 'integer', 'min:1', 'max:100'],
        ]);

        $page = Product::query()
            ->with('category')
            ->when($validated['search'] ?? null, function ($query, $search): void {
                $query->where(function ($inner) use ($search): void {
                    $inner->where('name', 'like', '%'.$search.'%')
                        ->orWhere('sku', 'like', '%'.$search.'%')
                        ->orWhere('barcode', 'like', '%'.$search.'%');
                });
            })
            ->latest('id')
            ->paginate((int) ($validated['per_page'] ?? 25));

        $ids = $page->getCollection()->pluck('id')->all();
        $sales = FinanceInvoiceItem::query()
            ->selectRaw('product_id, COALESCE(SUM(quantity),0) as sold_qty, COALESCE(SUM(total),0) as sold_total')
            ->whereNotNull('product_id')
            ->whereIn('product_id', $ids)
            ->groupBy('product_id')
            ->get()
            ->keyBy('product_id');

        return $this->ok(
            $page->getCollection()->map(function (Product $product) use ($sales) {
                $row = $sales->get($product->id);

                return $this->presenter->product($product, [
                    'sold_qty' => $row?->sold_qty ?? 0,
                    'sold_total' => $row?->sold_total ?? 0,
                ]);
            })->values()->all(),
            meta: $this->pageMeta($page),
        );
    }

    public function showProduct(Request $request, Product $product): JsonResponse
    {
        $this->clientActor($request, $this->clientWorkspace($this->workspaceContext), 'finance.view');
        $product->load('category');
        $row = FinanceInvoiceItem::query()
            ->selectRaw('COALESCE(SUM(quantity),0) as sold_qty, COALESCE(SUM(total),0) as sold_total')
            ->where('product_id', $product->id)
            ->first();

        return $this->ok($this->presenter->product($product, [
            'sold_qty' => $row?->sold_qty ?? 0,
            'sold_total' => $row?->sold_total ?? 0,
        ]));
    }

    public function inventory(Request $request): JsonResponse
    {
        $workspace = $this->clientWorkspace($this->workspaceContext);
        $this->clientActor($request, $workspace, 'finance.view');

        $validated = $request->validate([
            'search' => ['nullable', 'string', 'max:255'],
            'per_page' => ['sometimes', 'integer', 'min:1', 'max:100'],
        ]);

        $page = InventoryMovement::query()
            ->with(['product', 'user'])
            ->when($validated['search'] ?? null, function ($query, $search): void {
                $query->whereHas('product', fn ($product) => $product->where('name', 'like', '%'.$search.'%'));
            })
            ->latest('id')
            ->paginate((int) ($validated['per_page'] ?? 25));

        return $this->ok(
            $page->getCollection()->map(fn (InventoryMovement $movement) => $this->presenter->inventoryMovement($movement))->values()->all(),
            meta: $this->pageMeta($page),
        );
    }
}
