<?php

namespace App\Http\Controllers\Workspace\Pos;

use App\Http\Requests\Pos\StorePosMenuItemRequest;
use App\Http\Requests\Pos\UpdatePosMenuItemRequest;
use App\Models\PosItemCategory;
use App\Models\PosMenuItem;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

class PosMenuItemController extends PosBaseController
{
    public function index(Request $request): View
    {
        $this->authorizePos($request, 'menu.manage');
        $workspace = $this->currentWorkspace();

        $items = PosMenuItem::query()
            ->with('category:id,name')
            ->when($request->string('search')->toString(), function ($query, $search): void {
                $query->where(function ($inner) use ($search): void {
                    $inner->where('name', 'like', '%'.$search.'%')
                        ->orWhere('item_type', 'like', '%'.$search.'%');
                });
            })
            ->when($request->integer('category_id'), fn ($query, $categoryId) => $query->where('pos_item_category_id', $categoryId))
            ->orderBy('sort_order')
            ->orderBy('name')
            ->paginate(30)
            ->withQueryString();

        $types = PosMenuItem::query()
            ->select('item_type')
            ->distinct()
            ->orderBy('item_type')
            ->pluck('item_type')
            ->filter()
            ->values();

        $menuSliderImages = collect(data_get((array) ($workspace->settings ?? []), 'pos.menu_slider_images', []))
            ->filter(fn ($path): bool => is_string($path) && $path !== '')
            ->values();

        return view('workspace.pos.items.index', [
            'items' => $items,
            'types' => $types,
            'categories' => PosItemCategory::query()->orderBy('sort_order')->orderBy('name')->get(['id', 'name', 'is_active', 'sort_order']),
            'menuSliderImages' => $menuSliderImages,
        ]);
    }

    public function store(StorePosMenuItemRequest $request): RedirectResponse
    {
        $this->authorizePos($request, 'menu.manage');

        $validated = $request->validated();
        $workspace = $this->currentWorkspace();
        $imagePath = null;
        if ($request->hasFile('image_file')) {
            $imagePath = $request->file('image_file')?->store('workspaces/'.$workspace->id.'/pos-items', 'public');
        }

        PosMenuItem::query()->create([
            'name' => $validated['name'],
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

        return back()->with('success', 'تمت إضافة الصنف بنجاح.');
    }

    public function update(UpdatePosMenuItemRequest $request, PosMenuItem $item): RedirectResponse
    {
        $this->authorizePos($request, 'menu.manage');
        $this->authorize('update', $item);

        $validated = $request->validated();
        $workspace = $this->currentWorkspace();
        $imagePath = $item->image_path;
        if ((bool) ($validated['remove_image'] ?? false)) {
            $imagePath = null;
        }
        if ($request->hasFile('image_file')) {
            $imagePath = $request->file('image_file')?->store('workspaces/'.$workspace->id.'/pos-items', 'public');
        }

        $item->update([
            'name' => $validated['name'],
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

        return back()->with('success', 'تم تحديث الصنف.');
    }

    public function destroy(Request $request, PosMenuItem $item): RedirectResponse
    {
        $this->authorizePos($request, 'menu.manage');
        $this->authorize('delete', $item);

        $item->delete();

        return back()->with('success', 'تم حذف الصنف.');
    }
}
