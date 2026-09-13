<?php

namespace App\Http\Controllers\Workspace;

use App\Http\Controllers\Controller;
use App\Http\Requests\Category\StoreCategoryRequest;
use App\Http\Requests\Category\UpdateCategoryRequest;
use App\Models\Category;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\View\View;

class CategoryController extends Controller
{
    public function index(Request $request): View
    {
        $this->authorize('viewAny', Category::class);

        $categories = Category::query()
            ->when($request->string('search')->toString(), function ($query, $search): void {
                $query->where('name', 'like', '%'.$search.'%');
            })
            ->latest('id')
            ->paginate(12)
            ->withQueryString();

        return view('workspace.categories.index', compact('categories'));
    }

    public function create(): View
    {
        $this->authorize('create', Category::class);

        return view('workspace.categories.create');
    }

    public function store(StoreCategoryRequest $request): RedirectResponse
    {
        $this->authorize('create', Category::class);

        $payload = $request->validated();
        $payload['slug'] = $payload['slug'] ?? Str::slug($payload['name']);
        $payload['is_active'] = (bool) ($payload['is_active'] ?? true);

        Category::query()->create($payload);

        return redirect()->route('workspace.categories.index')->with('success', 'تم إنشاء التصنيف بنجاح.');
    }

    public function edit(Category $category): View
    {
        $this->authorize('update', $category);

        return view('workspace.categories.edit', compact('category'));
    }

    public function update(UpdateCategoryRequest $request, Category $category): RedirectResponse
    {
        $this->authorize('update', $category);

        $payload = $request->validated();
        $payload['slug'] = $payload['slug'] ?? $category->slug;
        if (array_key_exists('is_active', $payload)) {
            $payload['is_active'] = (bool) $payload['is_active'];
        }

        $category->update($payload);

        return redirect()->route('workspace.categories.index')->with('success', 'تم تحديث التصنيف بنجاح.');
    }

    public function destroy(Category $category): RedirectResponse
    {
        $this->authorize('delete', $category);

        $category->delete();

        return redirect()->route('workspace.categories.index')->with('success', 'تم حذف التصنيف.');
    }
}
