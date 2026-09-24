<x-communication-layout>
    <x-slot name="header"><h2 class="text-xl font-semibold">علاقات التوافقية (Compatibility Relationships)</h2></x-slot>
    <div class="py-8">
        <div class="mx-auto max-w-6xl px-4">
            @include('workspace.partials.nav')
            @if(session('status'))
                <div class="mb-4 rounded bg-green-100 p-3 text-sm text-green-800">{{ session('status') }}</div>
            @endif

            <div class="mb-6 flex gap-4 text-sm font-medium border-b pb-2">
                <a href="{{ route('workspace.commerce.intelligence.index') }}" class="text-gray-600 hover:text-indigo-600">الملف التجاري</a>
                <a href="{{ route('workspace.commerce.intelligence.attributes') }}" class="text-gray-600 hover:text-indigo-600">تعاريف الخصائص</a>
                <a href="{{ route('workspace.commerce.intelligence.requirements') }}" class="text-gray-600 hover:text-indigo-600">المتطلبات</a>
                <a href="{{ route('workspace.commerce.intelligence.rules') }}" class="text-gray-600 hover:text-indigo-600">القواعد</a>
                <a href="{{ route('workspace.commerce.intelligence.compatibilities') }}" class="border-b-2 border-indigo-600 pb-2 text-indigo-600">التوافقية</a>
            </div>

            <div class="mb-8 rounded-xl border bg-white p-6 shadow-sm">
                <h3 class="text-lg font-bold mb-4">إضافة علاقة توافقية للمنتج</h3>
                <form method="POST" action="{{ route('workspace.commerce.intelligence.compatibilities.store') }}" class="grid grid-cols-1 md:grid-cols-3 gap-4">
                    @csrf
                    <div>
                        <label class="block text-xs font-medium text-gray-700">معرف المنتج المصدر (Source Item ID)</label>
                        <input type="number" name="source_entity_id" placeholder="e.g. 15" required class="mt-1 block w-full rounded border-gray-300 text-sm">
                    </div>
                    <div>
                        <label class="block text-xs font-medium text-gray-700">نوع التوافق (Compatibility Type)</label>
                        <select name="compatibility_type" class="mt-1 block w-full rounded border-gray-300 text-sm">
                            @foreach(['compatible' => 'متوافق (Compatible)', 'incompatible' => 'غير متوافق (Incompatible)', 'requires_adapter' => 'يتطلب محول (Requires Adapter)', 'conditional' => 'مشروط (Conditional)'] as $k => $v)
                                <option value="{{ $k }}">{{ $v }}</option>
                            @endforeach
                        </select>
                    </div>
                    <div>
                        <label class="block text-xs font-medium text-gray-700">المرجع المستهدف (Target Reference JSON)</label>
                        <input type="text" name="target_reference" placeholder='{"make": "Toyota", "model": "Camry", "year": 2020}' class="mt-1 block w-full rounded border-gray-300 font-mono text-xs">
                    </div>
                    <div class="md:col-span-3 flex justify-end pt-2">
                        <button type="submit" class="rounded bg-indigo-600 px-4 py-1.5 text-sm text-white hover:bg-indigo-700">إضافة التوافق</button>
                    </div>
                </form>
            </div>

            <div class="overflow-x-auto rounded-xl border bg-white">
                <table class="min-w-full text-sm">
                    <thead class="bg-gray-50">
                        <tr>
                            <th class="px-4 py-3 text-right">#</th>
                            <th class="px-4 py-3 text-right">المنتج المصدر</th>
                            <th class="px-4 py-3 text-right">نوع التوافق</th>
                            <th class="px-4 py-3 text-right">المرجع المستهدف</th>
                            <th class="px-4 py-3 text-right">المصدر (Provenance)</th>
                            <th class="px-4 py-3 text-right">إجراءات</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y">
                        @forelse($compatibilities as $c)
                            <tr>
                                <td class="px-4 py-3">#{{ $c->id }}</td>
                                <td class="px-4 py-3">#{{ $c->source_entity_id }} ({{ $c->source_entity_type }})</td>
                                <td class="px-4 py-3 font-semibold">{{ $c->compatibility_type?->value ?? $c->compatibility_type }}</td>
                                <td class="px-4 py-3 font-mono text-xs">{{ json_encode($c->target_reference, JSON_UNESCAPED_UNICODE) }}</td>
                                <td class="px-4 py-3 text-xs text-gray-500">{{ $c->provenance?->value ?? $c->provenance }}</td>
                                <td class="px-4 py-3">
                                    <form method="POST" action="{{ route('workspace.commerce.intelligence.compatibilities.destroy', $c) }}" onsubmit="return confirm('حذف علاقة التوافق؟')">
                                        @csrf
                                        @method('DELETE')
                                        <button class="text-xs text-red-600 hover:underline">حذف</button>
                                    </form>
                                </td>
                            </tr>
                        @empty
                            <tr><td colspan="6" class="px-4 py-6 text-center text-gray-500">لا توجد سجلات توافق حالياً.</td></tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
        </div>
    </div>
</x-communication-layout>
