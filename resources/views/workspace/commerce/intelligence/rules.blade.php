<x-communication-layout>
    <x-slot name="header"><h2 class="text-xl font-semibold">قواعد الذكاء التجاري (Intelligence Rules)</h2></x-slot>
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
                <a href="{{ route('workspace.commerce.intelligence.rules') }}" class="border-b-2 border-indigo-600 pb-2 text-indigo-600">القواعد</a>
                <a href="{{ route('workspace.commerce.intelligence.compatibilities') }}" class="text-gray-600 hover:text-indigo-600">التوافقية</a>
            </div>

            <div class="mb-8 rounded-xl border bg-white p-6 shadow-sm">
                <h3 class="text-lg font-bold mb-4">إضافة قاعدة تقييم استئثارية/توصية جديدة</h3>
                <form method="POST" action="{{ route('workspace.commerce.intelligence.rules.store') }}" class="space-y-4 max-w-2xl">
                    @csrf
                    <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                        <div>
                            <label class="block text-xs font-medium text-gray-700">اسم القاعدة</label>
                            <input type="text" name="name" placeholder="e.g. استبعاد المقاسات الصغيرة" required class="mt-1 block w-full rounded border-gray-300 text-sm">
                        </div>
                        <div>
                            <label class="block text-xs font-medium text-gray-700">نوع القاعدة (Rule Type)</label>
                            <select name="rule_type" class="mt-1 block w-full rounded border-gray-300 text-sm">
                                @foreach(['incompatibility' => 'عدم توافق (Incompatibility)', 'required_attribute' => 'خاصية مطلوبة (Required Attribute)', 'recommendation_constraint' => 'قيد توصية (Constraint)'] as $k => $v)
                                    <option value="{{ $k }}">{{ $v }}</option>
                                @endforeach
                            </select>
                        </div>
                    </div>

                    <div>
                        <label class="block text-xs font-medium text-gray-700">الشروط (JSON format)</label>
                        <textarea name="conditions" rows="3" placeholder='[{"field": "waist", "operator": "<", "value": 70}]' required class="mt-1 block w-full rounded border-gray-300 font-mono text-xs"></textarea>
                    </div>

                    <div>
                        <label class="block text-xs font-medium text-gray-700">الإجراء (JSON format)</label>
                        <textarea name="actions" rows="2" placeholder='{"message": "هذا المنتج غير مناسب لمقاسات الخصر الأقل من 70 سم."}' required class="mt-1 block w-full rounded border-gray-300 font-mono text-xs"></textarea>
                    </div>

                    <button type="submit" class="rounded bg-indigo-600 px-4 py-2 text-sm text-white hover:bg-indigo-700">إضافة القاعدة</button>
                </form>
            </div>

            <div class="overflow-x-auto rounded-xl border bg-white">
                <table class="min-w-full text-sm">
                    <thead class="bg-gray-50">
                        <tr>
                            <th class="px-4 py-3 text-right">القاعدة</th>
                            <th class="px-4 py-3 text-right">النوع</th>
                            <th class="px-4 py-3 text-right">الأولوية</th>
                            <th class="px-4 py-3 text-right">إجراءات</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y">
                        @forelse($rules as $rule)
                            <tr>
                                <td class="px-4 py-3 font-medium">{{ $rule->name }}</td>
                                <td class="px-4 py-3">{{ $rule->rule_type?->value ?? $rule->rule_type }}</td>
                                <td class="px-4 py-3">{{ $rule->priority }}</td>
                                <td class="px-4 py-3">
                                    <form method="POST" action="{{ route('workspace.commerce.intelligence.rules.destroy', $rule) }}" onsubmit="return confirm('حذف القاعدة؟')">
                                        @csrf
                                        @method('DELETE')
                                        <button class="text-xs text-red-600 hover:underline">حذف</button>
                                    </form>
                                </td>
                            </tr>
                        @empty
                            <tr><td colspan="4" class="px-4 py-6 text-center text-gray-500">لا توجد قواعد حالياً.</td></tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
        </div>
    </div>
</x-communication-layout>
