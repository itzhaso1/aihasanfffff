<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\Category\StoreCategoryRequest;
use App\Http\Requests\Category\UpdateCategoryRequest;
use App\Models\Category;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

class CategoryController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $categories = Category::query()
            ->when($request->string('search')->toString(), function ($query, $search): void {
                $query->where('name', 'like', '%'.$search.'%');
            })
            ->when($request->has('is_active'), function ($query) use ($request): void {
                $query->where('is_active', $request->boolean('is_active'));
            })
            ->latest('id')
            ->paginate((int) $request->input('per_page', 15));

        return response()->json($categories);
    }

    public function store(StoreCategoryRequest $request): JsonResponse
    {
        $category = Category::query()->create([
            ...$request->validated(),
            'slug' => $request->string('slug')->toString() ?: Str::slug($request->string('name')->toString()),
            'is_active' => $request->boolean('is_active', true),
        ]);

        return response()->json(['data' => $category], 201);
    }

    public function show(Category $category): JsonResponse
    {
        $this->authorize('view', $category);

        return response()->json(['data' => $category]);
    }

    public function update(UpdateCategoryRequest $request, Category $category): JsonResponse
    {
        $this->authorize('update', $category);

        $category->update([
            ...$request->validated(),
            'slug' => $request->string('slug')->toString() ?: ($category->slug ?: Str::slug($category->name)),
        ]);

        return response()->json(['data' => $category->refresh()]);
    }

    public function destroy(Category $category): JsonResponse
    {
        $this->authorize('delete', $category);
        $category->delete();

        return response()->json(status: 204);
    }
}
