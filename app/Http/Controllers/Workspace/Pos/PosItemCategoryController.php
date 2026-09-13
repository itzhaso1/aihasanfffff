<?php

namespace App\Http\Controllers\Workspace\Pos;

use App\Http\Requests\Pos\StorePosItemCategoryRequest;
use App\Http\Requests\Pos\UpdatePosItemCategoryRequest;
use App\Models\PosItemCategory;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class PosItemCategoryController extends PosBaseController
{
    public function store(StorePosItemCategoryRequest $request): RedirectResponse
    {
        $this->authorizePos($request, 'menu.manage');

        $workspace = $this->currentWorkspace();
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

        PosItemCategory::query()->create([
            'name' => $validated['name'],
            'is_active' => (bool) ($validated['is_active'] ?? true),
            'sort_order' => (int) ($validated['sort_order'] ?? 0),
        ]);

        return back()->with('success', 'تمت إضافة التصنيف.');
    }

    public function update(UpdatePosItemCategoryRequest $request, PosItemCategory $category): RedirectResponse
    {
        $this->authorizePos($request, 'menu.manage');
        $this->authorize('update', $category);

        $workspace = $this->currentWorkspace();
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

        return back()->with('success', 'تم تحديث التصنيف.');
    }

    public function destroy(Request $request, PosItemCategory $category): RedirectResponse
    {
        $this->authorizePos($request, 'menu.manage');
        $this->authorize('delete', $category);

        if ($category->items()->exists()) {
            return back()->with('error', 'لا يمكن حذف تصنيف مرتبط بأصناف. انقل الأصناف أولاً.');
        }

        $category->delete();

        return back()->with('success', 'تم حذف التصنيف.');
    }
}
