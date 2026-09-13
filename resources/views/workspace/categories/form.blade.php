@php($category = $category ?? null)
<div>
    <label class="mb-1 block text-sm text-gray-700">الاسم</label>
    <input name="name" required value="{{ old('name', $category?->name) }}" class="w-full rounded-lg border-gray-300" />
</div>
<div>
    <label class="mb-1 block text-sm text-gray-700">Slug</label>
    <input name="slug" value="{{ old('slug', $category?->slug) }}" class="w-full rounded-lg border-gray-300" />
</div>
<div>
    <label class="mb-1 block text-sm text-gray-700">الوصف</label>
    <textarea name="description" class="w-full rounded-lg border-gray-300">{{ old('description', $category?->description) }}</textarea>
</div>
<label class="inline-flex items-center gap-2">
    <input type="checkbox" name="is_active" value="1" @checked(old('is_active', $category?->is_active ?? true))>
    <span class="text-sm">نشط</span>
</label>
