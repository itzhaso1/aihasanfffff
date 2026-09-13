<?php

namespace App\Http\Controllers\Workspace;

use App\Http\Controllers\Controller;
use App\Http\Controllers\Workspace\Concerns\InteractsWithWorkspace;
use App\Http\Requests\Product\StoreProductRequest;
use App\Http\Requests\Product\UpdateProductRequest;
use App\Models\Category;
use App\Models\Product;
use App\Services\Product\ProductService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\View\View;

class ProductController extends Controller
{
    use InteractsWithWorkspace;

    public function __construct(private readonly ProductService $productService) {}

    public function index(Request $request): View
    {
        $this->authorize('viewAny', Product::class);

        $products = Product::query()
            ->with('category')
            ->withCount('variants')
            ->when($request->string('search')->toString(), function ($query, $search): void {
                $query->where(function ($inner) use ($search): void {
                    $inner->where('name', 'like', '%'.$search.'%')
                        ->orWhere('sku', 'like', '%'.$search.'%');
                });
            })
            ->when($request->filled('status'), fn ($query) => $query->where('status', $request->string('status')->toString()))
            ->latest('id')
            ->paginate(12)
            ->withQueryString();

        return view('workspace.products.index', [
            'products' => $products,
            'categories' => Category::query()->orderBy('name')->get(['id', 'name']),
        ]);
    }

    public function create(): View
    {
        $this->authorize('create', Product::class);

        return view('workspace.products.create', [
            'categories' => Category::query()->orderBy('name')->get(['id', 'name']),
        ]);
    }

    public function store(StoreProductRequest $request): RedirectResponse
    {
        $this->authorize('create', Product::class);

        $payload = $request->validated();
        $payload['slug'] = $payload['slug'] ?? Str::slug($payload['name']);
        $payload['attributes'] = $this->parseJsonField($request, 'attributes_json');
        $payload['variants'] = $this->parseJsonField($request, 'variants_json');
        $payload['images'] = $this->storeImages($request);

        $this->productService->create($payload);

        return redirect()->route('workspace.products.index')->with('success', 'تم إنشاء المنتج بنجاح.');
    }

    public function edit(Product $product): View
    {
        $this->authorize('update', $product);

        return view('workspace.products.edit', [
            'product' => $product->load('variants'),
            'categories' => Category::query()->orderBy('name')->get(['id', 'name']),
            'attributesJson' => json_encode($product->attributes ?? [], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE),
            'variantsJson' => json_encode($product->variants->map(fn ($item) => [
                'id' => $item->id,
                'name' => $item->name,
                'sku' => $item->sku,
                'price' => $item->price,
                'sale_price' => $item->sale_price,
                'stock' => $item->stock,
                'status' => $item->status,
                'attributes' => $item->attributes,
            ])->values()->all(), JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE),
        ]);
    }

    public function update(UpdateProductRequest $request, Product $product): RedirectResponse
    {
        $this->authorize('update', $product);

        $payload = $request->validated();
        $payload['slug'] = $payload['slug'] ?? $product->slug;
        $payload['attributes'] = $this->parseJsonField($request, 'attributes_json', $product->attributes ?? []);
        $payload['variants'] = $this->parseJsonField(
            $request,
            'variants_json',
            $product->variants()
                ->get(['id', 'name', 'sku', 'attributes', 'price', 'sale_price', 'stock', 'status'])
                ->toArray()
        );

        $newImages = $this->storeImages($request);
        if ($newImages !== []) {
            $payload['images'] = $newImages;
        }

        $this->productService->update($product, $payload);

        return redirect()->route('workspace.products.index')->with('success', 'تم تحديث المنتج بنجاح.');
    }

    public function destroy(Product $product): RedirectResponse
    {
        $this->authorize('delete', $product);

        $this->productService->delete($product);

        return redirect()->route('workspace.products.index')->with('success', 'تم حذف المنتج.');
    }

    private function storeImages(Request $request): array
    {
        $request->validate([
            'image_files.*' => ['nullable', 'image', 'mimes:jpg,jpeg,png,webp', 'max:4096'],
        ]);

        $workspace = $this->currentWorkspace();
        $paths = [];

        foreach ((array) $request->file('image_files', []) as $image) {
            if (! $image) {
                continue;
            }
            $paths[] = $image->store('workspaces/'.$workspace->id.'/products', 'public');
        }

        return $paths;
    }
}
