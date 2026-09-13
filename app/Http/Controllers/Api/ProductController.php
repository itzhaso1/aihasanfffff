<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\Product\StoreProductRequest;
use App\Http\Requests\Product\UpdateProductRequest;
use App\Models\Product;
use App\Services\Product\ProductService;
use App\Support\Tenancy\WorkspaceContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

class ProductController extends Controller
{
    public function __construct(
        private readonly ProductService $productService,
        private readonly WorkspaceContext $workspaceContext,
    ) {}

    public function index(Request $request): JsonResponse
    {
        $products = Product::query()
            ->with(['category', 'variants'])
            ->when($request->string('search')->toString(), function ($query, $search): void {
                $query->where(fn ($q) => $q
                    ->where('name', 'like', '%'.$search.'%')
                    ->orWhere('sku', 'like', '%'.$search.'%')
                    ->orWhere('brand', 'like', '%'.$search.'%'));
            })
            ->when($request->filled('status'), fn ($query) => $query->where('status', $request->string('status')->toString()))
            ->when($request->filled('category_id'), fn ($query) => $query->where('category_id', $request->integer('category_id')))
            ->orderByDesc('id')
            ->paginate((int) $request->input('per_page', 15));

        return response()->json($products);
    }

    public function store(StoreProductRequest $request): JsonResponse
    {
        $product = $this->productService->create([
            ...$request->validated(),
            'slug' => $request->string('slug')->toString() ?: Str::slug($request->string('name')->toString()),
        ]);

        return response()->json(['data' => $product], 201);
    }

    public function show(Product $product): JsonResponse
    {
        $this->ensureInWorkspace($product);
        $this->authorize('view', $product);

        return response()->json(['data' => $product->load(['category', 'variants'])]);
    }

    public function update(UpdateProductRequest $request, Product $product): JsonResponse
    {
        $this->ensureInWorkspace($product);
        $this->authorize('update', $product);
        $updated = $this->productService->update($product, $request->validated());

        return response()->json(['data' => $updated]);
    }

    public function destroy(Product $product): JsonResponse
    {
        $this->ensureInWorkspace($product);
        $this->authorize('delete', $product);
        $this->productService->delete($product);

        return response()->json(status: 204);
    }

    private function ensureInWorkspace(Product $product): void
    {
        $workspaceId = $this->workspaceContext->workspaceId();

        if ($workspaceId !== null && (int) $product->workspace_id !== $workspaceId) {
            abort(404);
        }
    }
}
