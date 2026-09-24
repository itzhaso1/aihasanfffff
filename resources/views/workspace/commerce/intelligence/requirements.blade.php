<x-communication-layout>
    <x-slot name="header"><h2 class="text-xl font-semibold">تعاريف المتطلبات (Requirement Definitions)</h2></x-slot>
    <div class="py-8">
        <div class="mx-auto max-w-6xl px-4">
            @include('workspace.partials.nav')
            @if(session('status'))
                <div class="mb-4 rounded bg-green-100 p-3 text-sm text-green-800">{{ session('status') }}</div>
            @endif

            <div class="mb-6 flex gap-4 text-sm font-medium border-b pb-2">
                <a href="{{ route('workspace.commerce.intelligence.index') }}" class="text-gray-600 hover:text-indigo-600">الملف التجاري</a>
                <a href="{{ route('workspace.commerce.intelligence.attributes') }}" class="text-gray-600 hover:text-indigo-600">تعاريف الخصائص</a>
                <a href="{{ route('workspace.commerce.intelligence.requirements') }}" class="border-b-2 border-indigo-600 pb-2 text-indigo-600">المتطلبات</a>
                <a href="{{ route('workspace.commerce.intelligence.rules') }}" class="text-gray-600 hover:text-indigo-600">القواعد</a>
                <a href="{{ route('workspace.commerce.intelligence.compatibilities') }}" class="text-gray-600 hover:text-indigo-600">التوافقية</a>
            </div>

            <div class="mb-8 rounded-xl border bg-white p-6 shadow-sm">
                <h3 class="text-lg font-bold mb-4">إضافة متطلب جديد للعميل</h3>
                <form method="POST" action="{{ route('workspace.commerce.intelligence.requirements.store') }}" class="grid grid-cols-1 md:grid-cols-3 gap-4">
                    @csrf
                    <div>
                        <label class="block text-xs font-medium text-gray-700">مفتاح المتطلب (Key)</label>
                        <input type="text" name="key" placeholder="e.g. bust_cm, vehicle_model" required class="mt-1 block w-full rounded border-gray-300 text-sm">
                    </div>
                    <div>
                        <label class="block text-xs font-medium text-gray-700">التسمية (Label)</label>
                        <input type="text" name="label" placeholder="e.g. محيط الصدر, موديل السيارة" required class="mt-1 block w-full rounded border-gray-300 text-sm">
                    </div>
                    <div>
                        <label class="block text-xs font-medium text-gray-700">نوع البيانات</label>
                        <select name="data_type" class="mt-1 block w-full rounded border-gray-300 text-sm">
                            @foreach(['string', 'integer', 'decimal', 'boolean', 'enum', 'measurement', 'dimension'] as $t)
                                <option value="{{ $t }}">{{ $t }}</option>
                            @endforeach
                        </select>
                    </div>
                    <div>
                        <label class="block text-xs font-medium text-gray-700">الوحدة (Unit optional)</label>
                        <input type="text" name="unit" placeholder="e.g. cm, year" class="mt-1 block w-full rounded border-gray-300 text-sm">
                    </div>
                    <div class="md:col-span-2 flex items-center gap-4 pt-4">
                        <label class="flex items-center gap-1 text-xs"><input type="checkbox" name="is_required" value="1" checked> إجباري للتقييم</label>
                        <button type="submit" class="mr-auto rounded bg-indigo-600 px-4 py-1.5 text-sm text-white hover:bg-indigo-700">إضافة المتطلب</button>
                    </div>
                </form>
            </div>

            <div class="overflow-x-auto rounded-xl border bg-white">
                <table class="min-w-full text-sm">
                    <thead class="bg-gray-50">
                        <tr>
                            <th class="px-4 py-3 text-right">المفتاح</th>
                            <th class="px-4 py-3 text-right">التسمية</th>
                            <th class="px-4 py-3 text-right">نوع البيانات</th>
                            <th class="px-4 py-3 text-right">إجباري</th>
                            <th class="px-4 py-3 text-right">إجراءات</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y">
                        @forelse($requirements as $req)
                            <tr>
                                <td class="px-4 py-3 font-mono text-xs text-indigo-700">{{ $req->key }}</td>
                                <td class="px-4 py-3">{{ $req->label }}</td>
                                <td class="px-4 py-3">{{ $req->data_type?->value ?? $req->data_type }}</td>
                                <td class="px-4 py-3">{{ $req->is_required ? 'نعم' : 'اختياري' }}</td>
                                <td class="px-4 py-3">
                                    <form method="POST" action="{{ route('workspace.commerce.intelligence.requirements.destroy', $req) }}" onsubmit="return confirm('حذف المتطلب؟')">
                                        @csrf
                                        @method('DELETE')
                                        <button class="text-xs text-red-600 hover:underline">حذف</button>
                                    </form>
                                </td>
                            </tr>
                        @empty
                            <tr><td colspan="5" class="px-4 py-6 text-center text-gray-500">لا توجد متطلبات حالياً.</td></tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
        </div>
    </div>
</x-communication-layout>
