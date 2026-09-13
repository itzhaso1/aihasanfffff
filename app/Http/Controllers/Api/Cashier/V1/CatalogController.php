<?php

namespace App\Http\Controllers\Api\Cashier\V1;

use App\Http\Controllers\Api\Cashier\CashierController;
use App\Http\Controllers\Api\Cashier\Concerns\AuthorizesCashier;
use App\Http\Controllers\Api\Cashier\Concerns\ResolvesCashierWorkspace;
use App\Http\Requests\Pos\StorePosItemCategoryRequest;
use App\Http\Requests\Pos\StorePosMenuItemRequest;
use App\Http\Requests\Pos\UpdatePosItemCategoryRequest;
use App\Http\Requests\Pos\UpdatePosMenuItemRequest;
use App\Http\Resources\Cashier\MenuItemResource;
use App\Models\PosItemCategory;
use App\Models\PosMenuItem;
use App\Services\Feature\FeatureAccessService;
use App\Support\Tenancy\WorkspaceContext;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class CatalogController extends CashierController
{
    use AuthorizesCashier;
    use ResolvesCashierWorkspace;

    public function __construct(
        private readonly WorkspaceContext $workspaceContext,
        private readonly FeatureAccessService $featureAccessService,
    ) {}

    public function categories(Request $request): JsonResponse
    {
        $workspace = $this->requireWorkspace($this->workspaceContext);
        $this->authorizeCashier($request, $workspace);
        $this->ensurePos($workspace);

        $categories = PosItemCategory::query()
            ->orderBy('sort_order')
            ->orderBy('name')
            ->get(['id', 'name', 'sort_order', 'is_active']);

        return $this->ok([
            'categories' => $categories->map(fn (PosItemCategory $category) => $this->categoryPayload($category))->values(),
        ]);
    }

    public function storeCategory(StorePosItemCategoryRequest $request): JsonResponse
    {
        $workspace = $this->requireWorkspace($this->workspaceContext);
        $this->authorizeCashier($request, $workspace, 'menu.manage');
        $this->ensurePos($workspace);

        $validated = $request->validated();
        $request->validate([
            'name' => [
                'required',
                'string',
                'max:255',
                Rule::unique('pos_item_categories', 'name')
                    ->where(fn ($query) => $query->where('workspace_id', $workspace->id)),
            ],
        ]);

        $category = PosItemCategory::query()->create([
            'name' => $validated['name'],
            'is_active' => (bool) ($validated['is_active'] ?? true),
            'sort_order' => (int) ($validated['sort_order'] ?? 0),
        ]);

        return $this->ok(
            $this->categoryPayload($category),
            message: 'تمت إضافة التصنيف.',
            status: 201,
        );
    }

    public function updateCategory(UpdatePosItemCategoryRequest $request, PosItemCategory $category): JsonResponse
    {
        $workspace = $this->requireWorkspace($this->workspaceContext);
        $this->authorizeCashier($request, $workspace, 'menu.manage');
        $this->ensurePos($workspace);
        $this->authorize('update', $category);

        $validated = $request->validated();
        $request->validate([
            'name' => [
                'required',
                'string',
                'max:255',
                Rule::unique('pos_item_categories', 'name')
                    ->where(fn ($query) => $query->where('workspace_id', $workspace->id))
                    ->ignore($category->id),
            ],
        ]);

        $category->update([
            'name' => $validated['name'],
            'is_active' => (bool) ($validated['is_active'] ?? false),
            'sort_order' => (int) ($validated['sort_order'] ?? 0),
        ]);

        return $this->ok(
            $this->categoryPayload($category->fresh()),
            message: 'تم تحديث التصنيف.',
        );
    }

    public function destroyCategory(Request $request, PosItemCategory $category): JsonResponse
    {
        $workspace = $this->requireWorkspace($this->workspaceContext);
        $this->authorizeCashier($request, $workspace, 'menu.manage');
        $this->ensurePos($workspace);
        $this->authorize('delete', $category);

        // Web SoT: cannot delete category linked to items.
        if ($category->items()->exists()) {
            return $this->fail('لا يمكن حذف تصنيف مرتبط بأصناف. انقل الأصناف أولاً.', 422);
        }

        $category->delete();

        return $this->ok(message: 'تم حذف التصنيف.');
    }

    public function items(Request $request): JsonResponse
    {
        $workspace = $this->requireWorkspace($this->workspaceContext);
        $this->authorizeCashier($request, $workspace);
        $this->ensurePos($workspace);

        $search = trim((string) $request->query('q', ''));
        $barcode = trim((string) $request->query('barcode', ''));
        $sku = trim((string) $request->query('sku', ''));
        $categoryId = $request->integer('category_id') ?: null;
        $activeOnly = $request->boolean('active_only', true);

        $query = PosMenuItem::query()
            ->with(['category:id,name', 'product:id,workspace_id,stock'])
            ->orderBy('sort_order')
            ->orderBy('name');

        if ($activeOnly) {
            $query->where('is_active', true);
        }

        if ($categoryId) {
            $query->where('pos_item_category_id', $categoryId);
        }

        if ($barcode !== '') {
            $query->where('barcode', $barcode);
        }

        if ($sku !== '') {
            $query->where('sku', $sku);
        }

        if ($search !== '') {
            $query->where(function ($inner) use ($search): void {
                $inner->where('name', 'like', '%'.$search.'%')
                    ->orWhere('sku', 'like', '%'.$search.'%')
                    ->orWhere('barcode', 'like', '%'.$search.'%')
                    ->orWhere('item_type', 'like', '%'.$search.'%');
            });
        }

        $perPage = min(100, max(1, (int) $request->query('per_page', 50)));
        $items = $query->paginate($perPage);

        return $this->ok([
            'items' => MenuItemResource::collection($items->getCollection()),
        ], meta: [
            'current_page' => $items->currentPage(),
            'per_page' => $items->perPage(),
            'total' => $items->total(),
            'last_page' => $items->lastPage(),
        ]);
    }

    public function show(Request $request, PosMenuItem $item): JsonResponse
    {
        $workspace = $this->requireWorkspace($this->workspaceContext);
        $this->authorizeCashier($request, $workspace);
        $this->ensurePos($workspace);

        $item->load(['category:id,name', 'product:id,workspace_id,stock']);

        return $this->ok([
            'item' => new MenuItemResource($item),
        ]);
    }

    public function storeItem(StorePosMenuItemRequest $request): JsonResponse
    {
        $workspace = $this->requireWorkspace($this->workspaceContext);
        $this->authorizeCashier($request, $workspace, 'menu.manage');
        $this->ensurePos($workspace);

        $validated = $request->validated();
        $imagePath = null;
        if ($request->hasFile('image_file')) {
            $imagePath = $request->file('image_file')?->store('workspaces/'.$workspace->id.'/pos-items', 'public');
        }

        $item = PosMenuItem::query()->create([
            'name' => $validated['name'],
            'sku' => $validated['sku'] ?? null,
            'barcode' => $validated['barcode'] ?? null,
            'pos_item_category_id' => $validated['pos_item_category_id'] ?? null,
            'item_type' => $validated['item_type'] ?? 'عام',
            'size_label' => $validated['size_label'] ?? null,
            'description' => $validated['description'] ?? null,
            'price' => $validated['price'],
            'currency' => $validated['currency'] ?? 'USD',
            'is_active' => (bool) ($validated['is_active'] ?? true),
            'sort_order' => (int) ($validated['sort_order'] ?? 0),
            'image_path' => $imagePath,
        ]);

        $item->load(['category:id,name']);

        return $this->ok([
            'item' => new MenuItemResource($item),
        ], message: 'تمت إضافة الصنف بنجاح.', status: 201);
    }

    public function updateItem(UpdatePosMenuItemRequest $request, PosMenuItem $item): JsonResponse
    {
        $workspace = $this->requireWorkspace($this->workspaceContext);
        $this->authorizeCashier($request, $workspace, 'menu.manage');
        $this->ensurePos($workspace);
        $this->authorize('update', $item);

        $validated = $request->validated();
        $imagePath = $item->image_path;
        if ((bool) ($validated['remove_image'] ?? false)) {
            $imagePath = null;
        }
        if ($request->hasFile('image_file')) {
            $imagePath = $request->file('image_file')?->store('workspaces/'.$workspace->id.'/pos-items', 'public');
        }

        $item->update([
            'name' => $validated['name'],
            'sku' => $validated['sku'] ?? $item->sku,
            'barcode' => $validated['barcode'] ?? $item->barcode,
            'pos_item_category_id' => $validated['pos_item_category_id'] ?? null,
            'item_type' => $validated['item_type'] ?? 'عام',
            'size_label' => $validated['size_label'] ?? null,
            'description' => $validated['description'] ?? null,
            'price' => $validated['price'],
            'currency' => $validated['currency'] ?? $item->currency,
            'is_active' => (bool) ($validated['is_active'] ?? false),
            'sort_order' => (int) ($validated['sort_order'] ?? 0),
            'image_path' => $imagePath,
        ]);

        $item->load(['category:id,name']);

        return $this->ok([
            'item' => new MenuItemResource($item),
        ], message: 'تم تحديث الصنف.');
    }

    public function destroyItem(Request $request, PosMenuItem $item): JsonResponse
    {
        $workspace = $this->requireWorkspace($this->workspaceContext);
        $this->authorizeCashier($request, $workspace, 'menu.manage');
        $this->ensurePos($workspace);
        $this->authorize('delete', $item);

        $item->delete();

        return $this->ok(message: 'تم حذف الصنف.');
    }

    /**
     * @return array<string, mixed>
     */
    private function categoryPayload(PosItemCategory $category): array
    {
        return [
            'id' => $category->id,
            'name' => $category->name,
            'sort_order' => (int) $category->sort_order,
            'is_active' => (bool) $category->is_active,
        ];
    }

    private function ensurePos(\App\Models\Workspace $workspace): void
    {
        if (! $this->featureAccessService->workspaceHasFeature($workspace, 'pos')) {
            throw new HttpResponseException(
                $this->fail('الكاشير غير متاح في باقتك الحالية', 403, meta: [
                    'pos_enabled' => false,
                    'plans_url' => url('/workspace/billing'),
                ])
            );
        }
    }
}
