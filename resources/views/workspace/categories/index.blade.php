<x-communication-layout>
    <x-slot name="header">
        <h2 class="text-xl font-semibold">التصنيفات</h2>
    </x-slot>
    <div class="py-8">
        <div class="mx-auto max-w-7xl px-4">
            @include('workspace.partials.nav')
            @include('partials.flash')
            <div class="mb-4 flex items-center justify-between">
                <form method="GET" class="flex gap-2">
                    <input name="search" value="{{ request('search') }}" placeholder="بحث..." class="rounded-lg border-gray-300 text-sm" />
                    <button class="rounded-lg bg-gray-800 px-3 py-2 text-sm text-white">بحث</button>
                </form>
                <a href="{{ route('workspace.categories.create') }}" class="rounded-lg bg-blue-600 px-4 py-2 text-sm text-white">إضافة تصنيف</a>
            </div>
            <div class="overflow-hidden rounded-xl border border-gray-200 bg-white">
                <table class="min-w-full divide-y divide-gray-200 text-sm">
                    <thead class="bg-gray-50">
                        <tr>
                            <th class="px-4 py-3 text-right">الاسم</th>
                            <th class="px-4 py-3 text-right">Slug</th>
                            <th class="px-4 py-3 text-right">الحالة</th>
                            <th class="px-4 py-3 text-right"></th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-gray-100">
                        @forelse($categories as $category)
                            <tr>
                                <td class="px-4 py-3">{{ $category->name }}</td>
                                <td class="px-4 py-3">{{ $category->slug }}</td>
                                <td class="px-4 py-3">{{ $category->is_active ? 'نشط' : 'غير نشط' }}</td>
                                <td class="px-4 py-3 text-left">
                                    <a href="{{ route('workspace.categories.edit', $category) }}" class="text-blue-600">تعديل</a>
                                    <form method="POST" action="{{ route('workspace.categories.destroy', $category) }}" class="inline">
                                        @csrf @method('DELETE')
                                        <button class="mr-3 text-red-600" onclick="return confirm('تأكيد الحذف؟')">حذف</button>
                                    </form>
                                </td>
                            </tr>
                        @empty
                            <tr><td colspan="4" class="px-4 py-6 text-center text-gray-500">لا توجد بيانات.</td></tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
            <div class="mt-4">{{ $categories->links() }}</div>
        </div>
    </div>
</x-communication-layout>
