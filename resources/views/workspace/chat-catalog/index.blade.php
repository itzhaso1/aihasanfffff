<x-app-layout>
    <x-slot name="header">
        <div class="flex flex-wrap items-center justify-between gap-4">
            <div>
                <h1 class="text-xl font-bold text-slate-900">المنتجات والكتالوج والمخزون</h1>
                <p class="mt-0.5 text-xs text-slate-500">إدارة المنتجات، الأسعار، والمخزون المعتمد في المحادثات ومساعد الذكاء الاصطناعي.</p>
            </div>
            <div class="flex flex-wrap items-center gap-2">
                @can('create', App\Models\ChatCatalogItem::class)
                    <a href="{{ route('workspace.chat-catalog.create') }}" class="hs-btn-primary text-xs">
                        <svg class="h-4 w-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 4v16m8-8H4"/></svg>
                        <span>إضافة منتج جديد</span>
                    </a>
                @endcan
            </div>
        </div>
    </x-slot>

    <div class="py-2" x-data="{ currentTab: '{{ request('tab', 'items') }}', viewMode: 'grid', editingCategory: null }">
        <div class="space-y-6">
            @include('partials.flash')

            @if($errors->has('category') || $errors->has('category_name'))
                <div class="rounded-xl border border-red-200 bg-red-50 p-4 text-xs font-semibold text-red-700">
                    {{ $errors->first('category') ?: $errors->first('category_name') }}
                </div>
            @endif

            <!-- KPI & Status Header -->
            <div class="grid gap-3 sm:grid-cols-2 lg:grid-cols-4">
                <div class="hs-stat-card p-4">
                    <span class="text-xs font-medium text-slate-500">إجمالي المنتجات</span>
                    <p class="mt-1 text-2xl font-bold text-slate-900">{{ $items->total() }}</p>
                    <span class="text-[10px] text-slate-400 mt-0.5 block">عناصر الكتالوج النشطة</span>
                </div>
                <div class="hs-stat-card p-4">
                    <span class="text-xs font-medium text-slate-500">التصنيفات المتاحة</span>
                    <p class="mt-1 text-2xl font-bold text-slate-900">{{ $categories->count() }}</p>
                    <span class="text-[10px] text-slate-400 mt-0.5 block">أقسام رئيسية وفرعية</span>
                </div>
                <div class="hs-stat-card p-4">
                    <span class="text-xs font-medium text-slate-500">حالة الكتالوج</span>
                    <div class="mt-2">
                        <x-workspace.status-pill :value="$catalog->status->value" />
                    </div>
                </div>
                <div class="hs-stat-card p-4">
                    <span class="text-xs font-medium text-slate-500">العملة المعتمدة</span>
                    <p class="mt-1 text-xl font-bold text-slate-900">{{ $catalog->default_currency }}</p>
                    <span class="text-[10px] text-slate-400 mt-0.5 block">عملة تسعير المتجر</span>
                </div>
            </div>

            <!-- Tab & View Controls -->
            <div class="flex flex-wrap items-center justify-between gap-3 border-b border-slate-200/80 pb-3">
                <div class="flex gap-2">
                    <button 
                        @click="currentTab = 'items'" 
                        :class="currentTab === 'items' ? 'bg-slate-900 text-white shadow-sm font-bold' : 'bg-white text-slate-600 border border-slate-200 hover:bg-slate-50'" 
                        class="rounded-xl px-4 py-2 text-xs transition"
                    >
                        المنتجات ({{ $items->total() }})
                    </button>
                    <button 
                        @click="currentTab = 'categories'" 
                        :class="currentTab === 'categories' ? 'bg-slate-900 text-white shadow-sm font-bold' : 'bg-white text-slate-600 border border-slate-200 hover:bg-slate-50'" 
                        class="rounded-xl px-4 py-2 text-xs transition"
                    >
                        إدارة التصنيفات ({{ $categories->count() }})
                    </button>
                </div>

                <!-- Grid vs Table View Mode Switcher -->
                <div x-show="currentTab === 'items'" class="flex items-center gap-1 rounded-xl border border-slate-200/90 bg-white p-1 shadow-sm">
                    <button 
                        @click="viewMode = 'grid'" 
                        :class="viewMode === 'grid' ? 'bg-[#E8FAF6] text-[#067E6B] font-bold' : 'text-slate-400 hover:text-slate-600'"
                        class="flex h-7 w-7 items-center justify-center rounded-lg transition"
                        title="عرض كبطاقات"
                    >
                        <svg class="h-4 w-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 6a2 2 0 0 1 2-2h2a2 2 0 0 1 2 2v2a2 2 0 0 1-2 2H6a2 2 0 0 1-2-2V6zM14 6a2 2 0 0 1 2-2h2a2 2 0 0 1 2 2v2a2 2 0 0 1-2 2h-2a2 2 0 0 1-2-2V6zM4 16a2 2 0 0 1 2-2h2a2 2 0 0 1 2 2v2a2 2 0 0 1-2 2H6a2 2 0 0 1-2-2v-2zM14 16a2 2 0 0 1 2-2h2a2 2 0 0 1 2 2v2a2 2 0 0 1-2 2h-2a2 2 0 0 1-2-2v-2z"/></svg>
                    </button>
                    <button 
                        @click="viewMode = 'table'" 
                        :class="viewMode === 'table' ? 'bg-[#E8FAF6] text-[#067E6B] font-bold' : 'text-slate-400 hover:text-slate-600'"
                        class="flex h-7 w-7 items-center justify-center rounded-lg transition"
                        title="عرض كجدول"
                    >
                        <svg class="h-4 w-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M3.75 6.75h16.5M3.75 12h16.5m-16.5 5.25h16.5"/></svg>
                    </button>
                </div>
            </div>

            <!-- Tab 1: Items List (Grid & Table) -->
            <div x-show="currentTab === 'items'" class="space-y-4">
                <!-- Search & Filters -->
                <div class="rounded-2xl border border-slate-200/80 bg-white p-4 shadow-sm">
                    <form method="GET" action="{{ route('workspace.chat-catalog.index') }}" class="grid gap-3 sm:grid-cols-2 lg:grid-cols-6 items-end">
                        <input type="hidden" name="tab" value="items">
                        <div class="lg:col-span-2">
                            <label class="mb-1 block text-xs font-semibold text-slate-700">بحث بالاسم أو SKU أو الباركود</label>
                            <input name="search" value="{{ request('search') }}" placeholder="اكتب اسم المنتج..." class="hs-input text-xs" />
                        </div>
                        <div>
                            <label class="mb-1 block text-xs font-semibold text-slate-700">التصنيف</label>
                            <select name="category_id" class="hs-select text-xs">
                                <option value="">كافة التصنيفات</option>
                                @foreach($categories as $cat)
                                    <option value="{{ $cat->id }}" @selected((int) request('category_id') === $cat->id)>{{ $cat->name }}</option>
                                @endforeach
                            </select>
                        </div>
                        <div>
                            <label class="mb-1 block text-xs font-semibold text-slate-700">التصنيف التشغيلي</label>
                            <select name="classification" class="hs-select text-xs">
                                <option value="">كل الأنواع</option>
                                <option value="physical" @selected(request('classification')==='physical')>مادي (سلعة)</option>
                                <option value="service" @selected(request('classification')==='service')>خدمة</option>
                            </select>
                        </div>
                        <div>
                            <label class="mb-1 block text-xs font-semibold text-slate-700">الحالة التشغيلية</label>
                            <select name="is_active" class="hs-select text-xs">
                                <option value="">الكل</option>
                                <option value="1" @selected(request('is_active')==='1')>نشط</option>
                                <option value="0" @selected(request('is_active')==='0')>متوقف</option>
                            </select>
                        </div>
                        <div class="flex gap-2">
                            <button type="submit" class="hs-btn-primary w-full text-xs py-2 px-3">تصفية</button>
                            @if(request()->anyFilled(['search', 'category_id', 'classification', 'is_active', 'kind']))
                                <a href="{{ route('workspace.chat-catalog.index') }}" class="hs-btn-secondary text-xs py-2 px-3">إلغاء</a>
                            @endif
                        </div>
                    </form>
                </div>

                <!-- 1. Grid View of Products -->
                <div x-show="viewMode === 'grid'" class="grid gap-4 sm:grid-cols-2 lg:grid-cols-3 xl:grid-cols-4">
                    @forelse($items as $item)
                        <div class="hs-card p-0 overflow-hidden flex flex-col justify-between group">
                            <div>
                                <!-- Image Header -->
                                <div class="relative h-44 w-full bg-slate-100 overflow-hidden">
                                    @if($item->image_path)
                                        <img src="{{ \Illuminate\Support\Facades\Storage::disk('public')->url($item->image_path) }}" alt="{{ $item->name }}" class="h-full w-full object-cover transition duration-300 group-hover:scale-105">
                                    @else
                                        <div class="flex h-full w-full items-center justify-center bg-gradient-to-br from-slate-50 to-slate-100 text-slate-300">
                                            <svg class="h-12 w-12" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M4 16l4.586-4.586a2 2 0 012.828 0L16 16m-2-2l1.586-1.586a2 2 0 012.828 0L20 14m-6-6h.01M6 20h12a2 2 0 002-2V6a2 2 0 00-2-2H6a2 2 0 00-2 2v12a2 2 0 002 2z" /></svg>
                                        </div>
                                    @endif
                                    <!-- Badges on image -->
                                    <div class="absolute top-2.5 start-2.5 flex flex-wrap gap-1">
                                        <span class="hs-badge-neutral text-[10px] bg-white/90 backdrop-blur-xs font-bold">
                                            {{ $item->category?->name ?? 'بدون تصنيف' }}
                                        </span>
                                    </div>
                                    <div class="absolute top-2.5 end-2.5">
                                        @if($item->is_active)
                                            <span class="hs-badge-success text-[10px] bg-white/90 backdrop-blur-xs">نشط</span>
                                        @else
                                            <span class="hs-badge-danger text-[10px] bg-white/90 backdrop-blur-xs">متوقف</span>
                                        @endif
                                    </div>
                                </div>

                                <!-- Body -->
                                <div class="p-4 space-y-2">
                                    <a href="{{ route('workspace.chat-catalog.edit', $item) }}" class="font-bold text-sm text-slate-900 hover:text-[#067E6B] transition block truncate">
                                        {{ $item->name }}
                                    </a>
                                    <p class="text-xs text-slate-500 line-clamp-2 leading-relaxed">
                                        {{ $item->description ?: 'لا يوجد وصف مضاف لهذا المنتج.' }}
                                    </p>
                                    <div class="pt-2 flex items-center justify-between border-t border-slate-100">
                                        <div>
                                            <span class="text-base font-extrabold text-slate-900">{{ number_format((float) $item->price, 2) }}</span>
                                            <span class="text-[10px] font-normal text-slate-400">{{ $item->currency }}</span>
                                        </div>
                                        <span class="text-xs text-slate-500 font-medium">
                                            @if($item->stock !== null)
                                                المخزون: <strong class="text-slate-800">{{ $item->stock }}</strong>
                                            @else
                                                غير محدود
                                            @endif
                                        </span>
                                    </div>
                                </div>
                            </div>

                            <!-- Actions Footer -->
                            <div class="border-t border-slate-100 bg-slate-50/60 p-3 flex items-center justify-between gap-2">
                                @can('update', $item)
                                    <a href="{{ route('workspace.chat-catalog.edit', $item) }}" class="hs-btn-secondary text-xs py-1.5 px-3 flex-1">
                                        تعديل
                                    </a>
                                    <form method="POST" action="{{ route($item->is_active ? 'workspace.chat-catalog.deactivate' : 'workspace.chat-catalog.activate', $item) }}">
                                        @csrf
                                        <button type="submit" class="hs-btn-subtle text-xs py-1.5 px-2.5">
                                            {{ $item->is_active ? 'إيقاف' : 'تفعيل' }}
                                        </button>
                                    </form>
                                @endcan
                                @can('delete', $item)
                                    <form method="POST" action="{{ route('workspace.chat-catalog.destroy', $item) }}" onsubmit="return confirm('هل أنت متأكد من أرشفة هذا المنتج؟')">
                                        @csrf
                                        @method('DELETE')
                                        <button type="submit" class="hs-btn-icon text-rose-500 hover:bg-rose-50 hover:text-rose-600 h-8 w-8" title="أرشفة المنتج">
                                            <svg class="h-3.5 w-3.5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 7l-.867 12.142A2 2 0 0116.138 21H7.862a2 2 0 01-1.995-1.858L5 7m5 4v6m4-6v6m1-10V4a1 1 0 00-1-1h-4a1 1 0 00-1 1v3M4 7h16"/></svg>
                                        </button>
                                    </form>
                                @endcan
                            </div>
                        </div>
                    @empty
                        <div class="col-span-full py-12">
                            <div class="hs-empty">
                                <div class="hs-empty-icon">
                                    <svg class="h-6 w-6" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.8" d="m21 7.5-9-5.25L3 7.5m18 0-9 5.25m9-5.25v9l-9 5.25M3 7.5l9 5.25M3 7.5v9l9 5.25m0-9v9"/></svg>
                                </div>
                                <h4 class="hs-empty-title">لا توجد منتجات مسجلة</h4>
                                <p class="hs-empty-text">أضف منتجاتك ليتمكن الموظف الذكي مشعل من تقديمها والتسعير بها للعملاء.</p>
                            </div>
                        </div>
                    @endforelse
                </div>

                <!-- 2. Table View of Products -->
                <div x-show="viewMode === 'table'" class="hs-table-wrap">
                    <table class="hs-table">
                        <thead>
                            <tr>
                                <th>المنتج</th>
                                <th>التصنيف</th>
                                <th>النوع</th>
                                <th>السعر</th>
                                <th>المخزون</th>
                                <th>الحالة</th>
                                <th class="text-end">الإجراءات</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-slate-100">
                            @forelse($items as $item)
                                <tr>
                                    <td>
                                        <div class="flex items-center gap-3">
                                            @if($item->image_path)
                                                <img src="{{ \Illuminate\Support\Facades\Storage::disk('public')->url($item->image_path) }}" alt="" class="h-10 w-10 shrink-0 rounded-xl object-cover border border-slate-100">
                                            @else
                                                <div class="flex h-10 w-10 shrink-0 items-center justify-center rounded-xl bg-slate-100 text-slate-400">
                                                    <svg class="h-5 w-5" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="m21 7.5-9-5.25L3 7.5m18 0-9 5.25m9-5.25v9l-9 5.25M3 7.5l9 5.25M3 7.5v9l9 5.25m0-9v9"/></svg>
                                                </div>
                                            @endif
                                            <div>
                                                <a href="{{ route('workspace.chat-catalog.edit', $item) }}" class="font-bold text-slate-900 hover:text-[#067E6B] transition">{{ $item->name }}</a>
                                                <div class="flex items-center gap-2 mt-0.5 text-[11px] text-slate-400 font-mono">
                                                    @if($item->sku)<span>SKU: {{ $item->sku }}</span>@endif
                                                    @if($item->variants->count() > 0)<span class="rounded bg-slate-100 px-1.5 py-0.5 text-[10px] text-slate-600 font-medium">{{ $item->variants->count() }} نسخ</span>@endif
                                                </div>
                                            </div>
                                        </div>
                                    </td>
                                    <td class="font-medium text-slate-700">{{ $item->category?->name ?? '—' }}</td>
                                    <td>
                                        <span class="hs-badge-neutral text-[11px]">
                                            {{ $item->classification?->value === 'service' ? 'خدمة' : 'سلعة مادية' }}
                                        </span>
                                    </td>
                                    <td class="font-bold text-slate-900">
                                        {{ number_format((float) $item->price, 2) }} {{ $item->currency }}
                                    </td>
                                    <td class="text-xs font-semibold text-slate-700">
                                        {{ $item->stock !== null ? $item->stock . ' وحدة' : 'غير محدود' }}
                                    </td>
                                    <td>
                                        @if($item->is_active)
                                            <span class="hs-badge-success text-[10px]">نشط</span>
                                        @else
                                            <span class="hs-badge-neutral text-[10px]">متوقف</span>
                                        @endif
                                    </td>
                                    <td class="text-end">
                                        <div class="flex items-center justify-end gap-1.5">
                                            @can('update', $item)
                                                <a href="{{ route('workspace.chat-catalog.edit', $item) }}" class="hs-btn-secondary text-xs py-1 px-2.5">تعديل</a>
                                            @endcan
                                        </div>
                                    </td>
                                </tr>
                            @empty
                                <tr>
                                    <td colspan="7" class="py-12 text-center">
                                        <p class="text-xs text-slate-400">لا توجد منتجات مسجلة.</p>
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
                <div class="rounded-2xl border border-slate-200/80 bg-white p-5 shadow-sm space-y-4">
                    <h3 class="text-sm font-bold text-slate-900" x-text="editingCategory ? 'تعديل التصنيف: ' + editingCategory.name : 'إضافة تصنيف جديد'"></h3>
                    
                    <form method="POST" :action="editingCategory ? '{{ url('workspace/chat-catalog/categories') }}/' + editingCategory.id : '{{ route('workspace.chat-catalog.categories.store') }}'" enctype="multipart/form-data" class="space-y-4">
                        @csrf
                        <template x-if="editingCategory">
                            <input type="hidden" name="_method" value="PUT">
                        </template>

                        <div class="grid gap-4 sm:grid-cols-2 lg:grid-cols-4">
                            <div>
                                <label class="mb-1 block text-xs font-semibold text-slate-700">اسم التصنيف <span class="text-red-500">*</span></label>
                                <input name="name" required :value="editingCategory ? editingCategory.name : ''" class="hs-input text-xs" placeholder="مثال: الباقات والاشتراكات" />
                            </div>
                            <div>
                                <label class="mb-1 block text-xs font-semibold text-slate-700">التصنيف الأب (اختياري)</label>
                                <select name="parent_id" class="hs-select text-xs">
                                    <option value="">تصنيف رئيسي</option>
                                    @foreach($categories as $cat)
                                        <option value="{{ $cat->id }}">{{ $cat->name }}</option>
                                    @endforeach
                                </select>
                            </div>
                            <div>
                                <label class="mb-1 block text-xs font-semibold text-slate-700">الرابط الفريد (Slug)</label>
                                <input name="slug" :value="editingCategory ? editingCategory.slug : ''" class="hs-input text-xs font-mono" placeholder="تلقائي" />
                            </div>
                            <div>
                                <label class="mb-1 block text-xs font-semibold text-slate-700">صورة التصنيف</label>
                                <input type="file" name="image_file" accept="image/*" class="w-full text-xs text-slate-600 file:rounded-lg file:border-0 file:bg-slate-100 file:px-3 file:py-1.5 file:text-xs file:font-semibold" />
                            </div>
                        </div>

                        <div>
                            <label class="mb-1 block text-xs font-semibold text-slate-700">وصف التصنيف</label>
                            <textarea name="description" rows="2" :value="editingCategory ? editingCategory.description : ''" class="hs-input text-xs"></textarea>
                        </div>

                        <div class="flex items-center justify-between border-t border-slate-100 pt-3">
                            <div class="flex gap-2">
                                <button type="submit" class="hs-btn-primary text-xs" x-text="editingCategory ? 'حفظ التعديلات' : 'إضافة التصنيف'"></button>
                                <button type="button" x-show="editingCategory" @click="editingCategory = null" class="hs-btn-secondary text-xs">إلغاء</button>
                            </div>
                        </div>
                    </form>
                </div>

                <!-- Categories Grid -->
                <div class="grid gap-4 sm:grid-cols-2 lg:grid-cols-3">
                    @forelse($categories as $category)
                        <div class="hs-card p-4 flex items-center justify-between">
                            <div class="flex items-center gap-3 min-w-0">
                                <div class="flex h-10 w-10 shrink-0 items-center justify-center rounded-xl bg-[#E8FAF6] text-[#067E6B] font-bold">
                                    {{ mb_substr($category->name, 0, 1) }}
                                </div>
                                <div class="min-w-0">
                                    <h4 class="font-bold text-sm text-slate-900 truncate">{{ $category->name }}</h4>
                                    <p class="text-[11px] text-slate-400 mt-0.5 truncate">{{ $category->description ?: 'بدون وصف' }}</p>
                                </div>
                            </div>
                            <button 
                                type="button" 
                                @click="editingCategory = @js($category); window.scrollTo({top: 0, behavior: 'smooth'})"
                                class="hs-btn-secondary text-xs py-1 px-2.5 shrink-0"
                            >
                                تعديل
                            </button>
                        </div>
                    @empty
                        <p class="col-span-full py-8 text-center text-xs text-slate-400">لا توجد تصنيفات حالياً.</p>
                    @endforelse
                </div>
            </div>
        </div>
    </div>
</x-app-layout>
