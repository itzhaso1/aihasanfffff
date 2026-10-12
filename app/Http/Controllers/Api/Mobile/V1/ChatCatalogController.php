<?php

namespace App\Http\Controllers\Api\Mobile\V1;

use App\Enums\ChatCatalog\ChatCatalogInventoryPolicy;
use App\Http\Controllers\Api\Mobile\Concerns\AuthorizesChatCatalog;
use App\Http\Controllers\Api\Mobile\Concerns\ResolvesMobileWorkspace;
use App\Http\Controllers\Api\Mobile\MobileController;
use App\Http\Requests\Chat\StoreChatCatalogCategoryRequest;
use App\Http\Requests\Chat\StoreChatCatalogItemRequest;
use App\Http\Requests\Chat\UpdateChatCatalogCategoryRequest;
use App\Http\Requests\Chat\UpdateChatCatalogItemRequest;
use App\Http\Resources\Mobile\ChatCatalogCategoryResource;
use App\Http\Resources\Mobile\ChatCatalogItemResource;
use App\Models\ChatCatalogCategory;
use App\Models\ChatCatalogItem;
use App\Services\Chat\ChatCatalogService;
use App\Support\Tenancy\WorkspaceContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use InvalidArgumentException;

class ChatCatalogController extends MobileController
{
    use AuthorizesChatCatalog;
    use ResolvesMobileWorkspace;

    public function __construct(
        private readonly ChatCatalogService $catalogService,
        private readonly WorkspaceContext $workspaceContext,
    ) {}

    public function index(Request $request): JsonResponse
    {
        $workspace = $this->requireWorkspace($this->workspaceContext);
        $this->authorizeChatCatalogView($request, $workspace);

        $perPage = max(1, min(50, (int) $request->input('per_page', 20)));
        $search = trim((string) $request->input('q', $request->input('search', '')));
        $activeOnly = $request->has('active_only') ? $request->boolean('active_only') : false;

        $paginator = ChatCatalogItem::query()
            ->with(['category:id,name', 'variants.structuredAttributes', 'media', 'structuredAttributes'])
            ->when($activeOnly, fn ($query) => $query->where('is_active', true))
            ->when($request->filled('item_kind'), fn ($query) => $query->where('item_kind', $request->string('item_kind')->toString()))
            ->when($request->filled('classification'), fn ($query) => $query->where('classification', $request->string('classification')->toString()))
            ->when($request->integer('category_id'), fn ($query, $id) => $query->where('chat_catalog_category_id', $id))
            ->when($search !== '', function ($query) use ($search): void {
                $query->where(function ($inner) use ($search): void {
                    $inner->where('name', 'like', '%'.$search.'%')
                        ->orWhere('sku', 'like', '%'.$search.'%')
                        ->orWhere('description', 'like', '%'.$search.'%');
                });
            })
            ->orderBy('sort_order')
            ->orderBy('name')
            ->cursorPaginate($perPage);

        return $this->ok(
            ChatCatalogItemResource::collection($paginator->items()),
            $this->cursorMeta(
                $paginator->nextCursor()?->encode(),
                $paginator->previousCursor()?->encode(),
                $perPage,
            ),
        );
    }

    public function search(Request $request): JsonResponse
    {
        $workspace = $this->requireWorkspace($this->workspaceContext);
        $this->authorizeChatCatalogView($request, $workspace);

        $validated = $request->validate([
            'q' => ['required', 'string', 'min:1', 'max:255'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:50'],
        ]);

        $request->merge(['search' => $validated['q'], 'active_only' => true]);

        return $this->index($request);
    }

    public function show(Request $request, ChatCatalogItem $item): JsonResponse
    {
        $workspace = $this->requireWorkspace($this->workspaceContext);
        $this->authorizeChatCatalogView($request, $workspace);
        $this->authorize('view', $item);

        if ((int) $item->workspace_id !== (int) $workspace->id) {
            abort(404);
        }

        return $this->ok(new ChatCatalogItemResource($item->load([
            'category',
            'variants.structuredAttributes',
            'media',
            'structuredAttributes',
            'productTemplate.fields',
        ])));
    }

    public function store(StoreChatCatalogItemRequest $request): JsonResponse
    {
        $workspace = $this->requireWorkspace($this->workspaceContext);
        $this->authorizeChatCatalogItemsManage($request, $workspace);

        $payload = $request->validated();
        unset($payload['image_file'], $payload['gallery_files']);
        $payload['image_path'] = $this->storeImage($request, $workspace->id) ?? ($payload['image_path'] ?? null);
        $payload['media'] = array_merge($payload['media'] ?? [], $this->storeGallery($request, $workspace->id));

        try {
            $item = $this->catalogService->createItem($workspace, $payload);
        } catch (InvalidArgumentException $exception) {
            return $this->fail($exception->getMessage(), 422);
        }

        return $this->ok(new ChatCatalogItemResource($item), message: 'تمت إضافة المنتج.', status: 201);
    }

    public function update(UpdateChatCatalogItemRequest $request, ChatCatalogItem $item): JsonResponse
    {
        $workspace = $this->requireWorkspace($this->workspaceContext);
        $this->authorizeChatCatalogItemsManage($request, $workspace);
        $this->authorize('update', $item);
        $this->assertSameWorkspace($item->workspace_id, $workspace->id);

        $payload = $request->validated();
        unset($payload['image_file'], $payload['gallery_files']);
        $image = $this->storeImage($request, $workspace->id);
        if ($image !== null) {
            $payload['image_path'] = $image;
        }
        $gallery = $this->storeGallery($request, $workspace->id);
        if ($gallery !== [] || array_key_exists('media', $payload)) {
            $existing = is_array($payload['media'] ?? null) ? $payload['media'] : [];
            $offset = count($existing);
            foreach ($gallery as $index => $row) {
                $gallery[$index]['sort_order'] = $offset + (int) ($row['sort_order'] ?? $index);
            }
            $payload['media'] = array_merge($existing, $gallery);
        }

        try {
            $updated = $this->catalogService->updateItem($item, $payload);
        } catch (InvalidArgumentException $exception) {
            return $this->fail($exception->getMessage(), 422);
        }

        return $this->ok(new ChatCatalogItemResource($updated), message: 'تم تحديث المنتج.');
    }

    public function activate(Request $request, ChatCatalogItem $item): JsonResponse
    {
        return $this->toggle($request, $item, true);
    }

    public function deactivate(Request $request, ChatCatalogItem $item): JsonResponse
    {
        return $this->toggle($request, $item, false);
    }

    public function updateInventory(Request $request, ChatCatalogItem $item): JsonResponse
    {
        $workspace = $this->requireWorkspace($this->workspaceContext);
        $this->authorizeChatCatalogInventoryManage($request, $workspace);
        $this->assertSameWorkspace($item->workspace_id, $workspace->id);
        $data = $request->validate([
            'stock' => ['nullable', 'integer', 'min:0'],
            'inventory_policy' => ['required', Rule::in(ChatCatalogInventoryPolicy::selectableValues())],
        ]);

        try {
            $updated = $this->catalogService->updateItem($item, $data);
        } catch (InvalidArgumentException $exception) {
            return $this->fail($exception->getMessage(), 422);
        }

        return $this->ok(new ChatCatalogItemResource($updated), message: 'تم تحديث طريقة التوفر.');
    }

    public function destroy(Request $request, ChatCatalogItem $item): JsonResponse
    {
        $workspace = $this->requireWorkspace($this->workspaceContext);
        $this->authorizeChatCatalogItemsManage($request, $workspace);
        $this->authorize('delete', $item);
        $this->assertSameWorkspace($item->workspace_id, $workspace->id);

        $this->catalogService->deleteItem($item);

        return $this->ok(null, message: 'تم أرشفة المنتج.');
    }

    public function categories(Request $request): JsonResponse
    {
        $workspace = $this->requireWorkspace($this->workspaceContext);
        $this->authorizeChatCatalogView($request, $workspace);
        $perPage = max(1, min(100, (int) $request->input('per_page', 50)));

        $paginator = ChatCatalogCategory::query()
            ->orderBy('sort_order')
            ->orderBy('name')
            ->cursorPaginate($perPage);

        return $this->ok(
            ChatCatalogCategoryResource::collection($paginator->items()),
            $this->cursorMeta(
                $paginator->nextCursor()?->encode(),
                $paginator->previousCursor()?->encode(),
                $perPage,
            ),
        );
    }

    public function storeCategory(StoreChatCatalogCategoryRequest $request): JsonResponse
    {
        $workspace = $this->requireWorkspace($this->workspaceContext);
        $this->authorizeChatCatalogCategoriesManage($request, $workspace);

        $payload = $request->validated();
        unset($payload['image_file']);
        $image = $this->storeImage($request, $workspace->id, 'workspaces/'.$workspace->id.'/chat-catalog/categories');
        if ($image !== null) {
            $payload['image_path'] = $image;
        }

        try {
            $category = $this->catalogService->createCategory($workspace, $payload);
        } catch (InvalidArgumentException $exception) {
            return $this->fail($exception->getMessage(), 422);
        }

        return $this->ok(new ChatCatalogCategoryResource($category), message: 'تمت إضافة التصنيف.', status: 201);
    }

    public function updateCategory(UpdateChatCatalogCategoryRequest $request, ChatCatalogCategory $category): JsonResponse
    {
        $workspace = $this->requireWorkspace($this->workspaceContext);
        $this->authorizeChatCatalogCategoriesManage($request, $workspace);
        $this->authorize('update', $category);
        $this->assertSameWorkspace($category->workspace_id, $workspace->id);

        $payload = $request->validated();
        unset($payload['image_file']);
        $image = $this->storeImage($request, $workspace->id, 'workspaces/'.$workspace->id.'/chat-catalog/categories');
        if ($image !== null) {
            $payload['image_path'] = $image;
        }

        try {
            $updated = $this->catalogService->updateCategory($category, $payload);
        } catch (InvalidArgumentException $exception) {
            return $this->fail($exception->getMessage(), 422);
        }

        return $this->ok(new ChatCatalogCategoryResource($updated), message: 'تم تحديث التصنيف.');
    }

    public function destroyCategory(Request $request, ChatCatalogCategory $category): JsonResponse
    {
        $workspace = $this->requireWorkspace($this->workspaceContext);
        $this->authorizeChatCatalogCategoriesManage($request, $workspace);
        $this->authorize('delete', $category);
        $this->assertSameWorkspace($category->workspace_id, $workspace->id);

        try {
            $this->catalogService->deleteCategory($category);
        } catch (InvalidArgumentException $exception) {
            return $this->fail($exception->getMessage(), 422);
        }

        return $this->ok(null, message: 'تم حذف التصنيف.');
    }

    private function toggle(Request $request, ChatCatalogItem $item, bool $active): JsonResponse
    {
        $workspace = $this->requireWorkspace($this->workspaceContext);
        $this->authorizeChatCatalogItemsManage($request, $workspace);
        $this->authorize('update', $item);
        $this->assertSameWorkspace($item->workspace_id, $workspace->id);

        $updated = $this->catalogService->setActive($item, $active);

        return $this->ok(
            new ChatCatalogItemResource($updated),
            message: $active ? 'تم تفعيل المنتج.' : 'تم إيقاف المنتج.',
        );
    }

    private function assertSameWorkspace(int|string|null $itemWorkspaceId, int $workspaceId): void
    {
        if ((int) $itemWorkspaceId !== $workspaceId) {
            abort(404);
        }
    }

    private function storeImage(Request $request, int $workspaceId, ?string $directory = null): ?string
    {
        $file = $request->file('image_file');
        if (! $file) {
            return null;
        }

        return $file->store($directory ?? 'workspaces/'.$workspaceId.'/chat-catalog', 'public');
    }

    /**
     * @return list<array{kind: string, path: string, is_visible: bool, sort_order: int}>
     */
    private function storeGallery(Request $request, int $workspaceId): array
    {
        $files = $request->file('gallery_files', []);
        if (! is_array($files)) {
            $files = $files ? [$files] : [];
        }
        $rows = [];
        foreach (array_values($files) as $index => $file) {
            if (! $file) {
                continue;
            }
            $rows[] = [
                'kind' => 'gallery',
                'path' => $file->store('workspaces/'.$workspaceId.'/chat-catalog', 'public'),
                'is_visible' => true,
                'sort_order' => $index,
            ];
        }

        return $rows;
    }
}
