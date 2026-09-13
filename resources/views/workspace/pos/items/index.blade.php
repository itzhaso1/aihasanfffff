@extends('layouts.pos', ['pageTitle' => 'إدارة الأصناف'])

@section('content')
    <div class="grid gap-4 xl:grid-cols-12">
        <section class="rounded-2xl border border-slate-200 bg-white p-4 shadow-sm xl:col-span-8">
            <div class="mb-4 flex flex-wrap items-center justify-between gap-2">
                <h2 class="text-base font-bold text-slate-900">أصناف الكاشير</h2>
                <form method="GET" class="flex items-center gap-2">
                    <input name="search" value="{{ request('search') }}" placeholder="بحث باسم الصنف..." class="rounded-lg border-slate-300 text-sm" />
                    <select name="category_id" class="rounded-lg border-slate-300 text-sm">
                        <option value="">كل التصنيفات</option>
                        @foreach($categories as $category)
                            <option value="{{ $category->id }}" @selected((int) request('category_id') === (int) $category->id)>{{ $category->name }}</option>
                        @endforeach
                    </select>
                    <button class="rounded-lg bg-slate-900 px-3 py-2 text-xs font-semibold text-white">تطبيق</button>
                </form>
            </div>

            <div class="space-y-4">
                @forelse($items->groupBy(fn($item) => $item->category?->name ?: 'بدون تصنيف') as $categoryName => $groupedItems)
                    <article class="rounded-xl border border-slate-200">
                        <header class="border-b border-slate-200 bg-slate-50 px-3 py-2">
                            <h3 class="text-sm font-bold text-slate-800">{{ $categoryName }}</h3>
                        </header>
                        <div class="overflow-x-auto">
                            <table class="min-w-full text-sm">
                                <thead class="text-slate-600">
                                    <tr>
                                        <th class="px-3 py-2 text-right">الصنف</th>
                                        <th class="px-3 py-2 text-right">التفاصيل</th>
                                        <th class="px-3 py-2 text-right">السعر</th>
                                        <th class="px-3 py-2 text-right">الحالة</th>
                                        <th class="px-3 py-2 text-right">إجراءات</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    @foreach($groupedItems as $item)
                                        <tr class="border-t border-slate-100">
                                            <td class="px-3 py-2">
                                                <div class="flex items-center gap-2">
                                                    @if($item->image_path)
                                                        <img src="{{ asset('storage/'.$item->image_path) }}" alt="{{ $item->name }}" class="h-10 w-10 rounded object-cover">
                                                    @endif
                                                    <div>
                                                        <p class="font-semibold">{{ $item->name }}</p>
                                                        <p class="text-[11px] text-slate-500">{{ $item->item_type ?: 'عام' }}</p>
                                                    </div>
                                                </div>
                                            </td>
                                            <td class="px-3 py-2 text-xs text-slate-600">
                                                <p>الحجم: {{ $item->size_label ?: '—' }}</p>
                                                <p class="line-clamp-2">{{ $item->description ?: 'بدون وصف' }}</p>
                                            </td>
                                            <td class="px-3 py-2 font-semibold">{{ number_format((float) $item->price, 2) }} {{ $item->currency }}</td>
                                            <td class="px-3 py-2">
                                                <span class="rounded-full px-2 py-1 text-xs {{ $item->is_active ? 'bg-emerald-100 text-emerald-700' : 'bg-rose-100 text-rose-700' }}">
                                                    {{ $item->is_active ? 'مفعل' : 'متوقف' }}
                                                </span>
                                            </td>
                                            <td class="px-3 py-2">
                                                <details>
                                                    <summary class="cursor-pointer text-xs font-semibold text-slate-700">تعديل</summary>
                                                    <div class="mt-2 space-y-2 rounded-lg border border-slate-200 p-2">
                                                        <form method="POST" action="{{ route('workspace.pos.items.update', $item) }}" enctype="multipart/form-data" class="space-y-2">
                                                            @csrf
                                                            @method('PUT')
                                                            <input name="name" value="{{ $item->name }}" class="w-full rounded border-slate-300 text-xs" />
                                                            <select name="pos_item_category_id" class="w-full rounded border-slate-300 text-xs">
                                                                <option value="">بدون تصنيف</option>
                                                                @foreach($categories as $category)
                                                                    <option value="{{ $category->id }}" @selected((int) $item->pos_item_category_id === (int) $category->id)>{{ $category->name }}</option>
                                                                @endforeach
                                                            </select>
                                                            <input name="item_type" value="{{ $item->item_type }}" class="w-full rounded border-slate-300 text-xs" placeholder="النوع" />
                                                            <input name="size_label" value="{{ $item->size_label }}" class="w-full rounded border-slate-300 text-xs" placeholder="الحجم" />
                                                            <textarea name="description" rows="2" class="w-full rounded border-slate-300 text-xs" placeholder="الوصف">{{ $item->description }}</textarea>
                                                            <input type="number" name="price" step="0.01" min="0" value="{{ $item->price }}" class="w-full rounded border-slate-300 text-xs" />
                                                            <input name="currency" value="{{ $item->currency }}" class="w-full rounded border-slate-300 text-xs" />
                                                            <input type="number" name="sort_order" min="0" value="{{ $item->sort_order }}" class="w-full rounded border-slate-300 text-xs" />
                                                            <label class="inline-flex items-center gap-2 text-xs">
                                                                <input type="hidden" name="is_active" value="0">
                                                                <input type="checkbox" name="is_active" value="1" @checked($item->is_active)>
                                                                مفعل
                                                            </label>
                                                            <input type="file" name="image_file" class="w-full rounded border-slate-300 text-xs" />
                                                            <label class="inline-flex items-center gap-2 text-xs">
                                                                <input type="checkbox" name="remove_image" value="1">
                                                                حذف الصورة
                                                            </label>
                                                            <button class="rounded bg-slate-900 px-2 py-1 text-xs text-white">حفظ</button>
                                                        </form>
                                                        <form method="POST" action="{{ route('workspace.pos.items.destroy', $item) }}">
                                                            @csrf
                                                            @method('DELETE')
                                                            <button class="rounded bg-rose-600 px-2 py-1 text-xs text-white">حذف</button>
                                                        </form>
                                                    </div>
                                                </details>
                                            </td>
                                        </tr>
                                    @endforeach
                                </tbody>
                            </table>
                        </div>
                    </article>
                @empty
                    <div class="rounded-xl border border-dashed border-slate-300 p-8 text-center text-sm text-slate-500">
                        لا توجد أصناف حتى الآن.
                    </div>
                @endforelse
            </div>

            <div class="mt-4">{{ $items->links() }}</div>
        </section>

        <aside class="space-y-4 xl:col-span-4">
            <article class="rounded-2xl border border-slate-200 bg-white p-4 shadow-sm">
                <h3 class="text-base font-bold text-slate-900">إضافة صنف جديد</h3>
                <form method="POST" action="{{ route('workspace.pos.items.store') }}" enctype="multipart/form-data" class="mt-3 space-y-3">
                    @csrf
                    <input name="name" required placeholder="اسم الصنف" class="w-full rounded-lg border-slate-300 text-sm" />
                    <select name="pos_item_category_id" class="w-full rounded-lg border-slate-300 text-sm">
                        <option value="">بدون تصنيف</option>
                        @foreach($categories as $category)
                            <option value="{{ $category->id }}">{{ $category->name }}</option>
                        @endforeach
                    </select>
                    <input name="item_type" placeholder="النوع (مثال: مشروبات)" class="w-full rounded-lg border-slate-300 text-sm" list="pos-item-types" />
                    <datalist id="pos-item-types">
                        @foreach($types as $type)
                            <option value="{{ $type }}"></option>
                        @endforeach
                    </datalist>
                    <input name="size_label" placeholder="الحجم (اختياري)" class="w-full rounded-lg border-slate-300 text-sm" />
                    <input name="sku" placeholder="SKU (اختياري)" class="w-full rounded-lg border-slate-300 text-sm" />
                    <input name="barcode" placeholder="الباركود (اختياري)" class="w-full rounded-lg border-slate-300 text-sm" />
                    <textarea name="description" rows="2" placeholder="الوصف (اختياري)" class="w-full rounded-lg border-slate-300 text-sm"></textarea>
                    <input type="number" step="0.01" min="0" name="price" required placeholder="السعر" class="w-full rounded-lg border-slate-300 text-sm" />
                    <input name="currency" value="USD" class="w-full rounded-lg border-slate-300 text-sm" />
                    <input type="number" min="0" name="sort_order" value="0" class="w-full rounded-lg border-slate-300 text-sm" placeholder="الترتيب" />
                    <label class="inline-flex items-center gap-2 text-sm text-slate-700">
                        <input type="hidden" name="is_active" value="0">
                        <input type="checkbox" name="is_active" value="1" checked>
                        الصنف مفعل
                    </label>
                    <input type="file" name="image_file" class="w-full rounded-lg border-slate-300 text-sm" />
                    <button class="w-full rounded-lg bg-slate-900 px-3 py-2 text-sm font-semibold text-white">إضافة الصنف</button>
                </form>
            </article>

            <article class="rounded-2xl border border-slate-200 bg-white p-4 shadow-sm">
                <h3 class="text-base font-bold text-slate-900">إعدادات الكاشير العامة</h3>
                <p class="mt-1 text-xs text-slate-500">الضريبة والصوت وأنواع الطلب — تُحسب الضريبة في الخادم عند إنشاء الطلب.</p>
                @php($posSettings = (array) data_get(request()->attributes->get('workspace')?->settings ?? [], 'pos', []))
                <form method="POST" action="{{ route('workspace.pos.settings.pos') }}" class="mt-3 space-y-3">
                    @csrf
                    <div>
                        <label class="mb-1 block text-xs font-semibold text-slate-600">نسبة الضريبة %</label>
                        <input type="number" name="tax_rate" min="0" max="100" step="0.01" value="{{ number_format((float) ($posSettings['tax_rate'] ?? 0), 2, '.', '') }}" class="w-full rounded-lg border-slate-300 text-sm" />
                    </div>
                    <div>
                        <label class="mb-1 block text-xs font-semibold text-slate-600">عملة الكاشير (اختياري)</label>
                        <input name="currency" maxlength="3" value="{{ $posSettings['currency'] ?? '' }}" placeholder="SAR" class="w-full rounded-lg border-slate-300 text-sm" />
                    </div>
                    <label class="inline-flex items-center gap-2 text-sm text-slate-700">
                        <input type="hidden" name="new_order_sound" value="0">
                        <input type="checkbox" name="new_order_sound" value="1" @checked(($posSettings['new_order_sound'] ?? true))>
                        تشغيل صوت عند طلب جديد من المنيو
                    </label>
                    <label class="inline-flex items-center gap-2 text-sm text-slate-700">
                        <input type="hidden" name="enable_delivery" value="0">
                        <input type="checkbox" name="enable_delivery" value="1" @checked(($posSettings['enable_delivery'] ?? false))>
                        تفعيل طلب التوصيل في الكاشير
                    </label>
                    <button class="w-full rounded-lg bg-emerald-600 px-3 py-2 text-sm font-semibold text-white">حفظ إعدادات الكاشير</button>
                </form>
            </article>

            <article class="rounded-2xl border border-slate-200 bg-white p-4 shadow-sm">
                <h3 class="text-base font-bold text-slate-900">إعدادات الكاشير - سلايدر المنيو</h3>
                <p class="mt-1 text-xs text-slate-500">يمكنك إضافة صور سلايدر تظهر في بداية صفحة المنيو (حتى 8 صور).</p>

                <form method="POST" action="{{ route('workspace.pos.settings.menu-slider') }}" enctype="multipart/form-data" class="mt-3 space-y-3">
                    @csrf
                    @if(($menuSliderImages ?? collect())->isNotEmpty())
                        <div class="grid grid-cols-2 gap-2">
                            @foreach($menuSliderImages as $sliderImage)
                                <label class="rounded-lg border border-slate-200 p-2">
                                    <img src="{{ asset('storage/'.$sliderImage) }}" alt="Menu Slider" class="h-20 w-full rounded object-cover">
                                    <span class="mt-2 inline-flex items-center gap-2 text-[11px] text-rose-700">
                                        <input type="checkbox" name="remove_slider_images[]" value="{{ $sliderImage }}">
                                        حذف من السلايدر
                                    </span>
                                </label>
                            @endforeach
                        </div>
                    @else
                        <p class="text-xs text-slate-500">لا توجد صور سلايدر مضافة حاليًا.</p>
                    @endif

                    <div>
                        <label class="mb-1 block text-xs font-semibold text-slate-600">إضافة صور جديدة</label>
                        <input type="file" name="slider_images[]" multiple accept="image/*" class="w-full rounded-lg border-slate-300 text-sm" />
                    </div>
                    <button class="w-full rounded-lg bg-indigo-600 px-3 py-2 text-sm font-semibold text-white">حفظ إعدادات السلايدر</button>
                </form>
            </article>

            <article class="rounded-2xl border border-slate-200 bg-white p-4 shadow-sm">
                <h3 class="text-base font-bold text-slate-900">إدارة التصنيفات</h3>
                <form method="POST" action="{{ route('workspace.pos.categories.store') }}" class="mt-3 grid grid-cols-12 gap-2">
                    @csrf
                    <input name="name" required placeholder="اسم التصنيف" class="col-span-7 rounded-lg border-slate-300 text-sm" />
                    <input type="number" min="0" name="sort_order" value="0" class="col-span-3 rounded-lg border-slate-300 text-sm" />
                    <button class="col-span-2 rounded-lg bg-slate-900 px-2 py-2 text-xs font-semibold text-white">إضافة</button>
                </form>

                <div class="mt-3 space-y-2">
                    @foreach($categories as $category)
                        <div class="rounded-lg border border-slate-200 p-2">
                            <form method="POST" action="{{ route('workspace.pos.categories.update', $category) }}" class="space-y-2">
                                @csrf
                                @method('PUT')
                                <input name="name" value="{{ $category->name }}" class="w-full rounded border-slate-300 text-xs" />
                                <div class="flex items-center gap-2">
                                    <input type="number" min="0" name="sort_order" value="{{ $category->sort_order }}" class="w-24 rounded border-slate-300 text-xs" />
                                    <label class="inline-flex items-center gap-2 text-xs">
                                        <input type="hidden" name="is_active" value="0">
                                        <input type="checkbox" name="is_active" value="1" @checked($category->is_active)>
                                        مفعل
                                    </label>
                                    <button class="rounded bg-slate-900 px-2 py-1 text-xs text-white">حفظ</button>
                                </div>
                            </form>
                            <form method="POST" action="{{ route('workspace.pos.categories.destroy', $category) }}" class="mt-2">
                                @csrf
                                @method('DELETE')
                                <button class="rounded bg-rose-600 px-2 py-1 text-xs text-white">حذف</button>
                            </form>
                        </div>
                    @endforeach
                </div>
            </article>
        </aside>
    </div>
@endsection
