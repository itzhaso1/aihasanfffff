<x-communication-layout>
    <x-slot name="header">
        <div class="flex flex-wrap items-center justify-between gap-4">
            <div>
                <h2 class="text-xl font-semibold text-slate-800">المنتجات والمخزون</h2>
                <p class="mt-0.5 text-xs text-slate-500">مرجع للذكاء الاصطناعي عند الإجابة عن أسئلة العملاء. مستقل عن المالية والكاشير، وليس لبيع المنتجات أو تسليمها عبر الشات.</p>
            </div>
            <div class="flex flex-wrap items-center gap-2">
                @can('create', App\Models\ChatCatalogItem::class)
                    <a href="{{ route('workspace.chat-catalog.create') }}" class="rounded-xl bg-slate-900 px-4 py-2 text-xs font-medium text-white hover:bg-slate-800 shadow-sm transition">
                        + إضافة منتج جديد
                    </a>
                @endcan
            </div>
        </div>
    </x-slot>

    <div class="py-6" x-data="{ currentTab: '{{ request('tab', 'items') }}', editingCategory: null }">
        <div class="mx-auto max-w-7xl px-4 space-y-6">
            @include('workspace.partials.nav')
            @include('partials.flash')

            @if($errors->has('category') || $errors->has('category_name'))
                <div class="rounded-xl border border-red-200 bg-red-50 p-4 text-sm text-red-700">
                    {{ $errors->first('category') ?: $errors->first('category_name') }}
                </div>
            @endif

            <!-- KPI & Status Header -->
            <div class="grid gap-4 sm:grid-cols-2 lg:grid-cols-4">
                <div class="rounded-2xl border border-slate-200 bg-white p-4 shadow-sm">
                    <span class="text-xs font-medium text-slate-500">إجمالي المنتجات</span>
                    <p class="mt-1 text-2xl font-bold text-slate-900">{{ $items->total() }}</p>
                </div>
                <div class="rounded-2xl border border-slate-200 bg-white p-4 shadow-sm">
                    <span class="text-xs font-medium text-slate-500">التصنيفات المتاحة</span>
                    <p class="mt-1 text-2xl font-bold text-slate-900">{{ $categories->count() }}</p>
                </div>
                <div class="rounded-2xl border border-slate-200 bg-white p-4 shadow-sm">
                    <span class="text-xs font-medium text-slate-500">الحالة العامة</span>
                    <div class="mt-1">
                        <x-workspace.status-pill :value="$catalog->status->value" />
                    </div>
                </div>
                <div class="rounded-2xl border border-slate-200 bg-white p-4 shadow-sm">
                    <span class="text-xs font-medium text-slate-500">العملة الافتراضية</span>
                    <p class="mt-1 text-xl font-bold text-slate-900">{{ $catalog->default_currency }}</p>
                </div>
            </div>

            <!-- Tab Buttons -->
            <div class="flex items-center justify-between border-b border-slate-200 pb-3">
                <div class="flex gap-2">
                    <button @click="currentTab = 'items'" :class="currentTab === 'items' ? 'bg-slate-900 text-white' : 'bg-slate-100 text-slate-700 hover:bg-slate-200'" class="rounded-xl px-4 py-2 text-xs font-medium transition">
                        المنتجات ({{ $items->total() }})
                    </button>
                    <button @click="currentTab = 'categories'" :class="currentTab === 'categories' ? 'bg-slate-900 text-white' : 'bg-slate-100 text-slate-700 hover:bg-slate-200'" class="rounded-xl px-4 py-2 text-xs font-medium transition">
                        إدارة التصنيفات ({{ $categories->count() }})
                    </button>
                </div>
            </div>

            <!-- Tab 1: Items List -->
            <div x-show="currentTab === 'items'" class="space-y-4">
                <!-- Search & Filters -->
                <div class="rounded-2xl border border-slate-200 bg-white p-4 shadow-sm">
                    <form method="GET" class="grid gap-3 sm:grid-cols-2 lg:grid-cols-6 items-end">
                        <input type="hidden" name="tab" value="items">
                        <div class="lg:col-span-2">
                            <label class="mb-1 block text-xs font-medium text-slate-700">بحث بالاسم أو SKU أو الباركود</label>
                            <input name="search" value="{{ request('search') }}" placeholder="ابحث هنا..." class="w-full rounded-xl border-slate-300 text-xs focus:border-slate-500 focus:ring-slate-500" />
                        </div>
                        <div>
                            <label class="mb-1 block text-xs font-medium text-slate-700">التصنيف</label>
                            <select name="category_id" class="w-full rounded-xl border-slate-300 text-xs focus:border-slate-500 focus:ring-slate-500">
                                <option value="">الكل</option>
                                @foreach($categories as $cat)
                                    <option value="{{ $cat->id }}" @selected((int) request('category_id') === $cat->id)>{{ $cat->name }}</option>
                                @endforeach
                            </select>
                        </div>
                        <div>
                            <label class="mb-1 block text-xs font-medium text-slate-700">التصنيف التشغيلي</label>
                            <select name="classification" class="w-full rounded-xl border-slate-300 text-xs focus:border-slate-500 focus:ring-slate-500">
                                <option value="">كل الأنواع</option>
                                <option value="physical" @selected(request('classification')==='physical')>مادي</option>
                                <option value="service" @selected(request('classification')==='service')>خدمة</option>
                            </select>
                        </div>
                        <div>
                            <label class="mb-1 block text-xs font-medium text-slate-700">الحالة التشغيلية</label>
                            <select name="is_active" class="w-full rounded-xl border-slate-300 text-xs focus:border-slate-500 focus:ring-slate-500">
                                <option value="">الكل</option>
                                <option value="1" @selected(request('is_active')==='1')>نشط</option>
                                <option value="0" @selected(request('is_active')==='0')>متوقف</option>
                            </select>
                        </div>
                        <div class="flex gap-2">
                            <button type="submit" class="w-full rounded-xl bg-slate-900 px-3 py-2 text-xs font-medium text-white hover:bg-slate-800 transition">تصفية</button>
                            @if(request()->anyFilled(['search', 'category_id', 'classification', 'is_active', 'kind']))
                                <a href="{{ route('workspace.chat-catalog.index') }}" class="rounded-xl border border-slate-300 px-3 py-2 text-xs font-medium text-slate-600 hover:bg-slate-100 transition">إلغاء</a>
                            @endif
                        </div>
                    </form>
                </div>

                <!-- Table -->
                <div class="overflow-x-auto rounded-2xl border border-slate-200 bg-white shadow-sm">
                    <table class="w-full text-right text-xs text-slate-700">
                        <thead class="bg-slate-50 text-slate-500 border-b border-slate-200">
                            <tr>
                                <th class="px-4 py-3 font-semibold">المنتج</th>
                                <th class="px-4 py-3 font-semibold">التصنيف</th>
                                <th class="px-4 py-3 font-semibold">التصنيف التشغيلي</th>
                                <th class="px-4 py-3 font-semibold">السعر</th>
                                <th class="px-4 py-3 font-semibold">طريقة التوفر</th>
                                <th class="px-4 py-3 font-semibold">الحالة</th>
                                <th class="px-4 py-3 font-semibold">الإجراءات</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-slate-200">
                            @forelse($items as $item)
                                <tr class="hover:bg-slate-50/50 transition">
                                    <td class="px-4 py-3">
                                        <div class="flex items-center gap-3">
                                            @if($item->image_path)
                                                <img src="{{ \Illuminate\Support\Facades\Storage::disk('public')->url($item->image_path) }}" alt="" class="h-10 w-10 shrink-0 rounded-lg object-cover">
                                            @else
                                                <div class="flex h-10 w-10 shrink-0 items-center justify-center rounded-lg bg-slate-100 text-slate-400">
                                                    <svg class="h-5 w-5" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 16l4.586-4.586a2 2 0 012.828 0L16 16m-2-2l1.586-1.586a2 2 0 012.828 0L20 14m-6-6h.01M6 20h12a2 2 0 002-2V6a2 2 0 00-2-2H6a2 2 0 00-2 2v12a2 2 0 002 2z" /></svg>
                                                </div>
                                            @endif
                                            <div>
                                                <a href="{{ route('workspace.chat-catalog.edit', $item) }}" class="font-semibold text-slate-900 hover:text-blue-600 transition">{{ $item->name }}</a>
                                                <div class="flex items-center gap-2 mt-0.5 text-[11px] text-slate-500">
                                                    @if($item->sku)<span>SKU: {{ $item->sku }}</span>@endif
                                                    @if($item->variants->count() > 0)<span class="rounded bg-slate-100 px-1.5 py-0.5 text-[10px] text-slate-600 font-medium">{{ $item->variants->count() }} نسخ</span>@endif
                                                </div>
                                            </div>
                                        </div>
                                    </td>
                                    <td class="px-4 py-3 font-medium text-slate-800">{{ $item->category?->name ?? '—' }}</td>
                                    <td class="px-4 py-3">
                                        <span class="inline-flex items-center rounded-md px-2 py-0.5 text-[11px] font-medium
                                            @if($item->classification?->value === 'service') bg-sky-50 text-sky-700
                                            @else bg-slate-100 text-slate-700 @endif">
                                            {{ $item->classification?->value === 'service' ? 'service' : 'physical' }}
                                        </span>
                                    </td>
                                    <td class="px-4 py-3 font-semibold text-slate-900">
                                        {{ number_format((float) $item->price, 2) }} {{ $item->currency }}
                                    </td>
                                    <td class="px-4 py-3">
                                        @if(in_array($item->inventory_policy?->value, ['finite_stock', 'backorder'], true))
                                            <span class="font-medium text-slate-800">{{ $item->stock ?? 0 }} وحدة</span>
                                        @elseif(in_array($item->inventory_policy?->value, ['digital_pool', 'file_asset'], true))
                                            <span class="text-slate-500">{{ \App\Enums\ChatCatalog\ChatCatalogInventoryPolicy::NotTracked->label() }}</span>
                                        @else
                                            <span class="text-slate-500">{{ $item->inventory_policy?->label() ?? '—' }}</span>
                                        @endif
                                    </td>
                                    <td class="px-4 py-3">
                                        <x-workspace.status-pill :value="$item->is_active ? 'active' : 'cancelled'" />
                                    </td>
                                    <td class="px-4 py-3">
                                        <div class="flex items-center gap-2">
                                            @can('update', $item)
                                                <a href="{{ route('workspace.chat-catalog.edit', $item) }}" class="rounded-lg border border-slate-200 px-2.5 py-1 text-xs font-medium text-slate-700 hover:bg-slate-100 transition">تعديل</a>
                                                <form method="POST" action="{{ route($item->is_active ? 'workspace.chat-catalog.deactivate' : 'workspace.chat-catalog.activate', $item) }}">
                                                    @csrf
                                                    <button type="submit" class="rounded-lg border border-slate-200 px-2.5 py-1 text-xs font-medium {{ $item->is_active ? 'text-amber-700 hover:bg-amber-50' : 'text-emerald-700 hover:bg-emerald-50' }} transition">
                                                        {{ $item->is_active ? 'إيقاف' : 'تفعيل' }}
                                                    </button>
                                                </form>
                                            @endcan
                                            @can('delete', $item)
                                                <form method="POST" action="{{ route('workspace.chat-catalog.destroy', $item) }}" onsubmit="return confirm('هل أنت تأكد من أرشفة هذا المنتج؟')">
                                                    @csrf
                                                    @method('DELETE')
                                                    <button type="submit" class="rounded-lg border border-red-200 px-2.5 py-1 text-xs font-medium text-red-600 hover:bg-red-50 transition">أرشفة</button>
                                                </form>
                                            @endcan
                                        </div>
                                    </td>
                                </tr>
                            @empty
                                <tr>
                                    <td colspan="7" class="py-12">
                                        <x-empty-state title="لا توجد منتجات" text="لم يتم العثور على منتجات مطابقة للبحث أو التصفية." />
                                    </td>
                                </tr>
                            @endforelse
                        </tbody>
                    </table>
                </div>
                <div class="pt-2">{{ $items->links() }}</div>
            </div>

            <!-- Tab 2: Categories Management -->
            <div x-show="currentTab === 'categories'" class="space-y-6">
                <!-- Category Create / Edit Form -->
                <div class="rounded-2xl border border-slate-200 bg-white p-5 shadow-sm space-y-4">
                    <h3 class="text-sm font-semibold text-slate-900" x-text="editingCategory ? 'تعديل التصنيف: ' + editingCategory.name : 'إضافة تصنيف جديد'"></h3>
                    
                    <form method="POST" :action="editingCategory ? '{{ url('workspace/chat-catalog/categories') }}/' + editingCategory.id : '{{ route('workspace.chat-catalog.categories.store') }}'" enctype="multipart/form-data" class="space-y-4">
                        @csrf
                        <template x-if="editingCategory">
                            <input type="hidden" name="_method" value="PUT">
                        </template>

                        <div class="grid gap-4 sm:grid-cols-2 lg:grid-cols-4">
                            <div>
                                <label class="mb-1 block text-xs font-medium text-slate-700">اسم التصنيف <span class="text-red-500">*</span></label>
                                <input name="name" required :value="editingCategory ? editingCategory.name : ''" class="w-full rounded-xl border-slate-300 text-xs focus:border-slate-500 focus:ring-slate-500" placeholder="مثال: الملابس النسائية" />
                            </div>
                            <div>
                                <label class="mb-1 block text-xs font-medium text-slate-700">التصنيف الأب (Parent)</label>
                                <select name="parent_id" class="w-full rounded-xl border-slate-300 text-xs focus:border-slate-500 focus:ring-slate-500">
                                    <option value="">تصنيف رئيسي</option>
                                    @foreach($categories as $cat)
                                        <option value="{{ $cat->id }}">{{ $cat->name }}</option>
                                    @endforeach
                                </select>
                            </div>
                            <div>
                                <label class="mb-1 block text-xs font-medium text-slate-700">الرابط الفريد (Slug)</label>
                                <input name="slug" :value="editingCategory ? editingCategory.slug : ''" class="w-full rounded-xl border-slate-300 text-xs focus:border-slate-500 focus:ring-slate-500" placeholder="تلقائي عند الترك فارغاً" />
                            </div>
                            <div>
                                <label class="mb-1 block text-xs font-medium text-slate-700">صورة التصنيف</label>
                                <input type="file" name="image_file" accept="image/*" class="w-full text-xs text-slate-600 file:rounded-lg file:border-0 file:bg-slate-100 file:px-3 file:py-1.5 file:text-xs file:font-medium" />
                            </div>
                        </div>

                        <div>
                            <label class="mb-1 block text-xs font-medium text-slate-700">وصف التصنيف</label>
                            <textarea name="description" rows="2" :value="editingCategory ? editingCategory.description : ''" class="w-full rounded-xl border-slate-300 text-xs focus:border-slate-500 focus:ring-slate-500"></textarea>
                        </div>

                        <div class="flex items-center justify-between border-t pt-3">
                            <button type="button" x-show="editingCategory" @click="editingCategory = null" class="text-xs text-slate-600 hover:underline">إلغاء التعديل</button>
                            <button type="submit" class="rounded-xl bg-slate-900 px-5 py-2 text-xs font-medium text-white hover:bg-slate-800 transition" x-text="editingCategory ? 'حفظ التحديثات' : 'إضافة التصنيف'"></button>
                        </div>
                    </form>
                </div>

                <!-- Categories List Table -->
                <div class="overflow-x-auto rounded-2xl border border-slate-200 bg-white shadow-sm">
                    <table class="w-full text-right text-xs text-slate-700">
                        <thead class="bg-slate-50 text-slate-500 border-b border-slate-200">
                            <tr>
                                <th class="px-4 py-3 font-semibold">التصنيف</th>
                                <th class="px-4 py-3 font-semibold">التصنيف الأب</th>
                                <th class="px-4 py-3 font-semibold">عدد المنتجات</th>
                                <th class="px-4 py-3 font-semibold">الرابط (Slug)</th>
                                <th class="px-4 py-3 font-semibold">الإجراءات</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-slate-200">
                            @forelse($categories as $cat)
                                <tr class="hover:bg-slate-50/50 transition">
                                    <td class="px-4 py-3 font-semibold text-slate-900">{{ $cat->name }}</td>
                                    <td class="px-4 py-3 text-slate-600">{{ $cat->parent?->name ?? '—' }}</td>
                                    <td class="px-4 py-3 font-medium text-slate-800">{{ $cat->items_count }} منتج</td>
                                    <td class="px-4 py-3 text-slate-500 font-mono text-[11px]">{{ $cat->slug }}</td>
                                    <td class="px-4 py-3">
                                        <div class="flex items-center gap-2">
                                            @can('update', $cat)
                                                <button type="button" @click="editingCategory = {{ json_encode($cat) }}" class="rounded-lg border border-slate-200 px-2.5 py-1 text-xs font-medium text-slate-700 hover:bg-slate-100 transition">تعديل</button>
                                            @endcan
                                            @can('delete', $cat)
                                                <form method="POST" action="{{ route('workspace.chat-catalog.categories.destroy', $cat) }}" onsubmit="return confirm('هل أنت تأكد من حذف هذا التصنيف؟')">
                                                    @csrf
                                                    @method('DELETE')
                                                    <button type="submit" class="rounded-lg border border-red-200 px-2.5 py-1 text-xs font-medium text-red-600 hover:bg-red-50 transition">حذف</button>
                                                </form>
                                            @endcan
                                        </div>
                                    </td>
                                </tr>
                            @empty
                                <tr>
                                    <td colspan="5" class="py-8">
                                        <x-empty-state title="لا توجد تصنيفات" text="لم يتم إنشاء أي تصنيفات بعد." />
                                    </td>
                                </tr>
                            @endforelse
                        </tbody>
                    </table>
                </div>
            </div>

        </div>
    </div>
</x-communication-layout>
