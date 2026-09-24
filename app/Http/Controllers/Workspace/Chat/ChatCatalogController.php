<?php

namespace App\Http\Controllers\Workspace\Chat;

use App\Http\Controllers\Controller;
use App\Http\Controllers\Workspace\Concerns\InteractsWithWorkspace;
use App\Http\Requests\Chat\StoreChatCatalogCategoryRequest;
use App\Http\Requests\Chat\StoreChatCatalogItemRequest;
use App\Http\Requests\Chat\UpdateChatCatalogCategoryRequest;
use App\Http\Requests\Chat\UpdateChatCatalogItemRequest;
use App\Models\ChatCatalogCategory;
use App\Models\ChatCatalogItem;
use App\Services\Chat\ChatCatalogRootService;
use App\Services\Chat\ChatCatalogService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;
use InvalidArgumentException;

class ChatCatalogController extends Controller
{
    use InteractsWithWorkspace;

    public function __construct(
        private readonly ChatCatalogService $catalogService,
        private readonly ChatCatalogRootService $catalogRoot,
    ) {}

    public function index(Request $request): View
    {
        $this->authorize('viewAny', ChatCatalogItem::class);

        $items = ChatCatalogItem::query()
            ->with(['category', 'media', 'variants', 'digitalPools'])
            ->when($request->string('search')->toString(), function ($query, $search): void {
                $query->where(function ($inner) use ($search): void {
                    $inner->where('name', 'like', '%'.$search.'%')
                        ->orWhere('sku', 'like', '%'.$search.'%')
                        ->orWhere('barcode', 'like', '%'.$search.'%');
                });
            })
            ->when($request->filled('kind'), fn ($query) => $query->where('item_kind', $request->string('kind')->toString()))
            ->when($request->filled('classification'), fn ($query) => $query->where('classification', $request->string('classification')->toString()))
            ->when($request->filled('category_id'), fn ($query) => $query->where('chat_catalog_category_id', (int) $request->input('category_id')))
            ->when($request->filled('inventory_policy'), fn ($query) => $query->where('inventory_policy', $request->string('inventory_policy')->toString()))
            ->when($request->filled('is_active'), fn ($query) => $query->where('is_active', $request->boolean('is_active')))
            ->orderBy('sort_order')
            ->orderBy('name')
            ->paginate(20)
            ->withQueryString();

        $categories = ChatCatalogCategory::query()
            ->with('parent:id,name')
            ->withCount('items')
            ->orderBy('sort_order')
            ->orderBy('name')
            ->get();

        return view('workspace.chat-catalog.index', [
            'items' => $items,
            'categories' => $categories,
            'catalog' => $this->catalogRoot->forWorkspace($this->currentWorkspace()),
        ]);
    }

    public function create(): View
    {
        $this->authorize('create', ChatCatalogItem::class);

        return view('workspace.chat-catalog.create', [
            'categories' => ChatCatalogCategory::query()->orderBy('name')->get(['id', 'name']),
            'catalog' => $this->catalogRoot->forWorkspace($this->currentWorkspace()),
        ]);
    }

    public function store(StoreChatCatalogItemRequest $request): RedirectResponse
    {
        $workspace = $this->currentWorkspace();
        $payload = $request->validated();
        if ($request->file('image_file')) {
            $payload['image_path'] = $request->file('image_file')->store('workspaces/'.$workspace->id.'/chat-catalog', 'public');
        }

        try {
            $this->catalogService->createItem($workspace, $payload);
        } catch (InvalidArgumentException $exception) {
            return back()->withInput()->withErrors(['name' => $exception->getMessage()]);
        }

        return redirect()->route('workspace.chat-catalog.index')->with('success', 'تمت إضافة صنف كتالوج الشات بنجاح.');
    }

    public function edit(ChatCatalogItem $item): View
    {
        $this->authorize('update', $item);
        $this->assertSameWorkspace($item->workspace_id);

        return view('workspace.chat-catalog.edit', [
            'item' => $item->load(['variants', 'media', 'structuredAttributes']),
            'categories' => ChatCatalogCategory::query()->orderBy('name')->get(['id', 'name']),
            'catalog' => $this->catalogRoot->forWorkspace($this->currentWorkspace()),
        ]);
    }

    public function update(UpdateChatCatalogItemRequest $request, ChatCatalogItem $item): RedirectResponse
    {
        $this->authorize('update', $item);
        $this->assertSameWorkspace($item->workspace_id);

        $payload = $request->validated();
        if ($request->file('image_file')) {
            $payload['image_path'] = $request->file('image_file')->store('workspaces/'.$item->workspace_id.'/chat-catalog', 'public');
        }

        try {
            $this->catalogService->updateItem($item, $payload);
        } catch (InvalidArgumentException $exception) {
            return back()->withInput()->withErrors(['name' => $exception->getMessage()]);
        }

        return redirect()->route('workspace.chat-catalog.index')->with('success', 'تم تحديث صنف كتالوج الشات بنجاح.');
    }

    public function activate(ChatCatalogItem $item): RedirectResponse
    {
        $this->authorize('update', $item);
        $this->assertSameWorkspace($item->workspace_id);
        $this->catalogService->setActive($item, true);

        return back()->with('success', 'تم تفعيل الصنف.');
    }

    public function deactivate(ChatCatalogItem $item): RedirectResponse
    {
        $this->authorize('update', $item);
        $this->assertSameWorkspace($item->workspace_id);
        $this->catalogService->setActive($item, false);

        return back()->with('success', 'تم إيقاف الصنف.');
    }

    public function destroy(ChatCatalogItem $item): RedirectResponse
    {
        $this->authorize('delete', $item);
        $this->assertSameWorkspace($item->workspace_id);
        $this->catalogService->deleteItem($item);

        return redirect()->route('workspace.chat-catalog.index')->with('success', 'تم أرشفة الصنف.');
    }

    public function storeCategory(StoreChatCatalogCategoryRequest $request): RedirectResponse
    {
        $workspace = $this->currentWorkspace();
        $payload = $request->validated();
        if ($request->file('image_file')) {
            $payload['image_path'] = $request->file('image_file')->store('workspaces/'.$workspace->id.'/chat-catalog/categories', 'public');
        }

        try {
            $this->catalogService->createCategory($workspace, $payload);
        } catch (InvalidArgumentException $exception) {
            return back()->withInput()->withErrors(['category_name' => $exception->getMessage()]);
        }

        return back()->with('success', 'تمت إضافة التصنيف بنجاح.');
    }

    public function updateCategory(UpdateChatCatalogCategoryRequest $request, ChatCatalogCategory $category): RedirectResponse
    {
        $this->authorize('update', $category);
        $this->assertSameWorkspace($category->workspace_id);

        $payload = $request->validated();
        if ($request->file('image_file')) {
            $payload['image_path'] = $request->file('image_file')->store('workspaces/'.$category->workspace_id.'/chat-catalog/categories', 'public');
        }

        try {
            $this->catalogService->updateCategory($category, $payload);
        } catch (InvalidArgumentException $exception) {
            return back()->withInput()->withErrors(['category_name' => $exception->getMessage()]);
        }

        return back()->with('success', 'تم تحديث التصنيف بنجاح.');
    }

    public function destroyCategory(ChatCatalogCategory $category): RedirectResponse
    {
        $this->authorize('delete', $category);
        $this->assertSameWorkspace($category->workspace_id);

        try {
            $this->catalogService->deleteCategory($category);
        } catch (InvalidArgumentException $exception) {
            return back()->withErrors(['category' => $exception->getMessage()]);
        }

        return back()->with('success', 'تم حذف التصنيف بنجاح.');
    }
}
