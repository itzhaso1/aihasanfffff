@extends('layouts.financial', ['pageTitle' => 'قوائم الأسعار'])

@section('content')
    <div class="space-y-4">
        <h2 class="text-xl font-bold text-slate-900">إدارة قوائم الأسعار</h2>

        <div class="grid gap-4 xl:grid-cols-3">
            <article class="rounded-2xl border border-slate-200 bg-white p-4 shadow-sm">
                <h3 class="mb-3 text-sm font-bold">إضافة قائمة أسعار</h3>
                <form method="POST" action="{{ route('workspace.finance.price-lists.store') }}" class="space-y-3">
                    @csrf
                    <div>
                        <label class="mb-1 block text-xs font-semibold text-slate-600">الاسم</label>
                        <input type="text" name="name" value="{{ old('name') }}" required class="w-full rounded-lg border-slate-300 text-sm">
                    </div>
                    <div class="grid grid-cols-2 gap-2">
                        <div>
                            <label class="mb-1 block text-xs font-semibold text-slate-600">الكود</label>
                            <input type="text" name="code" value="{{ old('code') }}" class="w-full rounded-lg border-slate-300 text-sm">
                        </div>
                        <div>
                            <label class="mb-1 block text-xs font-semibold text-slate-600">العملة</label>
                            <input type="text" name="currency" value="{{ old('currency', $workspaceCurrency) }}" maxlength="3" class="w-full rounded-lg border-slate-300 text-sm">
                        </div>
                    </div>
                    <div class="grid grid-cols-2 gap-2">
                        <div>
                            <label class="mb-1 block text-xs font-semibold text-slate-600">سارية من</label>
                            <input type="date" name="effective_from" value="{{ old('effective_from') }}" class="w-full rounded-lg border-slate-300 text-sm">
                        </div>
                        <div>
                            <label class="mb-1 block text-xs font-semibold text-slate-600">إلى</label>
                            <input type="date" name="effective_to" value="{{ old('effective_to') }}" class="w-full rounded-lg border-slate-300 text-sm">
                        </div>
                    </div>
                    <div>
                        <label class="mb-1 block text-xs font-semibold text-slate-600">ملاحظات</label>
                        <textarea name="notes" rows="2" class="w-full rounded-lg border-slate-300 text-sm">{{ old('notes') }}</textarea>
                    </div>
                    <button class="w-full rounded-lg bg-[#06C2A4] px-3 py-2 text-xs font-semibold text-white hover:bg-[#05ab91]">حفظ</button>
                </form>
            </article>

            <article class="xl:col-span-2 rounded-2xl border border-slate-200 bg-white p-4 shadow-sm">
                <form method="GET" action="{{ route('workspace.finance.price-lists.index') }}" class="mb-3 grid gap-2 sm:grid-cols-4">
                    <input type="text" name="search" value="{{ $filters['search'] }}" placeholder="بحث بالاسم أو الكود" class="rounded-lg border-slate-300 text-sm sm:col-span-2">
                    <select name="status" class="rounded-lg border-slate-300 text-sm">
                        <option value="">كل الحالات</option>
                        @foreach(['draft' => 'مسودة', 'approved' => 'معتمدة', 'cancelled' => 'ملغاة'] as $value => $label)
                            <option value="{{ $value }}" @selected($filters['status'] === $value)>{{ $label }}</option>
                        @endforeach
                    </select>
                    <button class="rounded-lg bg-slate-900 px-3 py-2 text-xs font-semibold text-white">فلترة</button>
                </form>

                <div class="overflow-x-auto">
                    <table class="min-w-full divide-y divide-slate-200 text-sm">
                        <thead class="bg-slate-50">
                            <tr>
                                <th class="px-2 py-2 text-right">الاسم</th>
                                <th class="px-2 py-2 text-right">الكود</th>
                                <th class="px-2 py-2 text-right">الحالة</th>
                                <th class="px-2 py-2 text-right">عدد العناصر</th>
                                <th class="px-2 py-2 text-left">إجراء</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-slate-100">
                            @forelse($priceLists as $list)
                                <tr>
                                    <td class="px-2 py-2 font-semibold">{{ $list->name }}</td>
                                    <td class="px-2 py-2">{{ $list->code ?: '—' }}</td>
                                    <td class="px-2 py-2">{{ $list->status }}</td>
                                    <td class="px-2 py-2">{{ $list->items_count }}</td>
                                    <td class="px-2 py-2">
                                        <a href="{{ route('workspace.finance.price-lists.index', ['price_list_id' => $list->id]) }}" class="rounded-md border border-slate-300 px-2 py-1 text-xs font-semibold text-slate-700 hover:bg-slate-100">فتح</a>
                                    </td>
                                </tr>
                            @empty
                                <tr><td colspan="5" class="px-2 py-8 text-center text-slate-500">لا توجد قوائم أسعار.</td></tr>
                            @endforelse
                        </tbody>
                    </table>
                </div>
                <div class="mt-3">{{ $priceLists->links() }}</div>
            </article>
        </div>

        @if($selectedList)
            <article class="rounded-2xl border border-slate-200 bg-white p-4 shadow-sm">
                <div class="mb-4 flex flex-wrap items-center justify-between gap-2">
                    <h3 class="text-lg font-bold">{{ $selectedList->name }} <span class="text-sm font-normal text-slate-500">({{ $selectedList->status }})</span></h3>
                    <div class="flex items-center gap-2">
                        <form method="POST" action="{{ route('workspace.finance.price-lists.approve', $selectedList) }}">
                            @csrf
                            <button class="rounded-lg bg-emerald-600 px-3 py-2 text-xs font-semibold text-white hover:bg-emerald-700">اعتماد</button>
                        </form>
                        <form method="POST" action="{{ route('workspace.finance.price-lists.mark-draft', $selectedList) }}">
                            @csrf
                            <button class="rounded-lg border border-slate-300 px-3 py-2 text-xs font-semibold text-slate-700 hover:bg-slate-100">إرجاع مسودة</button>
                        </form>
                        <form method="POST" action="{{ route('workspace.finance.price-lists.cancel', $selectedList) }}">
                            @csrf
                            <button class="rounded-lg bg-rose-600 px-3 py-2 text-xs font-semibold text-white hover:bg-rose-700">إلغاء</button>
                        </form>
                    </div>
                </div>

                <form method="POST" action="{{ route('workspace.finance.price-lists.update', $selectedList) }}" class="mb-4 grid gap-3 rounded-xl border border-slate-200 p-3 md:grid-cols-6">
                    @csrf
                    @method('PUT')
                    <div class="md:col-span-2">
                        <label class="mb-1 block text-xs font-semibold text-slate-600">اسم القائمة</label>
                        <input type="text" name="name" value="{{ old('name', $selectedList->name) }}" class="w-full rounded-lg border-slate-300 text-sm">
                    </div>
                    <div>
                        <label class="mb-1 block text-xs font-semibold text-slate-600">الكود</label>
                        <input type="text" name="code" value="{{ old('code', $selectedList->code) }}" class="w-full rounded-lg border-slate-300 text-sm">
                    </div>
                    <div>
                        <label class="mb-1 block text-xs font-semibold text-slate-600">العملة</label>
                        <input type="text" name="currency" value="{{ old('currency', $selectedList->currency) }}" class="w-full rounded-lg border-slate-300 text-sm">
                    </div>
                    <div>
                        <label class="mb-1 block text-xs font-semibold text-slate-600">من</label>
                        <input type="date" name="effective_from" value="{{ old('effective_from', optional($selectedList->effective_from)->toDateString()) }}" class="w-full rounded-lg border-slate-300 text-sm">
                    </div>
                    <div>
                        <label class="mb-1 block text-xs font-semibold text-slate-600">إلى</label>
                        <input type="date" name="effective_to" value="{{ old('effective_to', optional($selectedList->effective_to)->toDateString()) }}" class="w-full rounded-lg border-slate-300 text-sm">
                    </div>
                    <div class="md:col-span-6">
                        <label class="mb-1 block text-xs font-semibold text-slate-600">ملاحظات</label>
                        <textarea name="notes" rows="2" class="w-full rounded-lg border-slate-300 text-sm">{{ old('notes', $selectedList->notes) }}</textarea>
                    </div>
                    <div class="md:col-span-6">
                        <button class="rounded-lg bg-slate-900 px-4 py-2 text-xs font-semibold text-white">تحديث القائمة</button>
                    </div>
                </form>

                <div class="grid gap-4 xl:grid-cols-3">
                    <form method="POST" action="{{ route('workspace.finance.price-lists.items.store', $selectedList) }}" class="rounded-xl border border-slate-200 p-3">
                        @csrf
                        <h4 class="mb-2 text-sm font-bold">إضافة عنصر تسعير</h4>
                        <div class="space-y-2">
                            <div>
                                <label class="mb-1 block text-xs text-slate-600">اختر منتجًا (اختياري)</label>
                                <select name="product_id" class="w-full rounded-lg border-slate-300 text-sm">
                                    <option value="">-- خدمة / اسم حر --</option>
                                    @foreach($products as $product)
                                        <option value="{{ $product->id }}">{{ $product->name }}{{ $product->sku ? ' ('.$product->sku.')' : '' }}</option>
                                    @endforeach
                                </select>
                            </div>
                            <div>
                                <label class="mb-1 block text-xs text-slate-600">الاسم (للخدمة أو عند عدم اختيار منتج)</label>
                                <input type="text" name="product_name" class="w-full rounded-lg border-slate-300 text-sm" placeholder="اسم المنتج/الخدمة">
                            </div>
                            <div class="grid grid-cols-2 gap-2">
                                <div>
                                    <label class="mb-1 block text-xs text-slate-600">SKU</label>
                                    <input type="text" name="sku" class="w-full rounded-lg border-slate-300 text-sm">
                                </div>
                                <div>
                                    <label class="mb-1 block text-xs text-slate-600">أقل كمية</label>
                                    <input type="number" step="0.001" min="0.001" name="min_quantity" value="1" class="w-full rounded-lg border-slate-300 text-sm">
                                </div>
                            </div>
                            <div class="grid grid-cols-2 gap-2">
                                <div>
                                    <label class="mb-1 block text-xs text-slate-600">السعر</label>
                                    <input type="number" step="0.01" min="0.01" name="price" required class="w-full rounded-lg border-slate-300 text-sm">
                                </div>
                                <div>
                                    <label class="mb-1 block text-xs text-slate-600">الضريبة %</label>
                                    <input type="number" step="0.01" min="0" max="100" name="tax_rate" value="0" class="w-full rounded-lg border-slate-300 text-sm">
                                </div>
                            </div>
                            <button class="w-full rounded-lg bg-[#06C2A4] px-3 py-2 text-xs font-semibold text-white hover:bg-[#05ab91]">إضافة</button>
                        </div>
                    </form>

                    <div class="xl:col-span-2 overflow-x-auto">
                        <table class="min-w-full divide-y divide-slate-200 text-sm">
                            <thead class="bg-slate-50">
                                <tr>
                                    <th class="px-2 py-2 text-right">العنصر</th>
                                    <th class="px-2 py-2 text-right">الكمية الدنيا</th>
                                    <th class="px-2 py-2 text-right">السعر</th>
                                    <th class="px-2 py-2 text-right">الضريبة</th>
                                    <th class="px-2 py-2 text-left">إجراءات</th>
                                </tr>
                            </thead>
                            <tbody class="divide-y divide-slate-100">
                                @forelse($selectedList->items as $item)
                                    <tr>
                                        <td class="px-2 py-2">
                                            <p class="font-semibold">{{ $item->product_name }}</p>
                                            <p class="text-xs text-slate-500">{{ $item->sku ?: 'بدون SKU' }}</p>
                                        </td>
                                        <td class="px-2 py-2">{{ number_format((float) $item->min_quantity, 3) }}</td>
                                        <td class="px-2 py-2">{{ number_format((float) $item->price, 2) }}</td>
                                        <td class="px-2 py-2">{{ number_format((float) $item->tax_rate, 2) }}%</td>
                                        <td class="px-2 py-2">
                                            <details>
                                                <summary class="cursor-pointer text-xs font-semibold text-slate-700">تعديل</summary>
                                                <form method="POST" action="{{ route('workspace.finance.price-lists.items.update', $item) }}" class="mt-2 space-y-2 rounded-lg border border-slate-200 p-2">
                                                    @csrf
                                                    @method('PUT')
                                                    <input type="text" name="product_name" value="{{ $item->product_name }}" class="w-full rounded-lg border-slate-300 text-xs">
                                                    <div class="grid grid-cols-2 gap-2">
                                                        <input type="number" step="0.001" min="0.001" name="min_quantity" value="{{ $item->min_quantity }}" class="rounded-lg border-slate-300 text-xs">
                                                        <input type="number" step="0.01" min="0.01" name="price" value="{{ $item->price }}" class="rounded-lg border-slate-300 text-xs">
                                                    </div>
                                                    <input type="number" step="0.01" min="0" max="100" name="tax_rate" value="{{ $item->tax_rate }}" class="w-full rounded-lg border-slate-300 text-xs">
                                                    <button class="rounded-md bg-slate-900 px-2 py-1 text-xs font-semibold text-white">حفظ</button>
                                                </form>
                                                <form method="POST" action="{{ route('workspace.finance.price-lists.items.destroy', $item) }}" class="mt-2">
                                                    @csrf
                                                    @method('DELETE')
                                                    <button class="rounded-md bg-rose-600 px-2 py-1 text-xs font-semibold text-white">حذف</button>
                                                </form>
                                            </details>
                                        </td>
                                    </tr>
                                @empty
                                    <tr><td colspan="5" class="px-2 py-8 text-center text-slate-500">لا توجد عناصر في هذه القائمة بعد.</td></tr>
                                @endforelse
                            </tbody>
                        </table>
                    </div>
                </div>
            </article>
        @endif
    </div>
@endsection
