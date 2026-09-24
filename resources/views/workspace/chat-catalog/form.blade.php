@php($item = $item ?? null)
@php($catalog = $catalog ?? null)

<div x-data="{
    activeTab: 'basic',
    classification: '{{ old('classification', $item?->classification?->value ?? 'physical') }}',
    inventoryPolicy: '{{ old('inventory_policy', $item?->inventory_policy?->value ?? 'finite_stock') }}',
    variants: {{ json_encode(old('variants', $item?->variants->map(fn($v) => [
        'id' => $v->id,
        'name' => $v->name,
        'sku' => $v->sku,
        'barcode' => $v->barcode,
        'price' => $v->price,
        'sale_price' => $v->sale_price,
        'compare_at_price' => $v->compare_at_price,
        'currency' => $v->currency,
        'stock' => $v->stock,
        'inventory_policy' => $v->inventory_policy?->value ?? 'finite_stock',
        'is_active' => (bool) $v->is_active,
        'is_visible' => (bool) $v->is_visible,
    ])->toArray() ?? [])) }},
    addVariant() {
        this.variants.push({
            id: null,
            name: '',
            sku: '',
            barcode: '',
            price: '',
            sale_price: '',
            compare_at_price: '',
            currency: '{{ $catalog?->default_currency ?? 'SAR' }}',
            stock: null,
            inventory_policy: 'finite_stock',
            is_active: true,
            is_visible: true
        });
    },
    removeVariant(index) {
        this.variants.splice(index, 1);
    }
}" class="space-y-6">

    <!-- Tab Buttons -->
    <div class="flex flex-wrap gap-2 border-b border-slate-200 pb-3">
        <button type="button" @click="activeTab = 'basic'" :class="activeTab === 'basic' ? 'bg-slate-900 text-white font-medium' : 'bg-slate-100 text-slate-700 hover:bg-slate-200'" class="rounded-lg px-4 py-2 text-sm transition">البيانات الأساسية</button>
        <button type="button" @click="activeTab = 'pricing'" :class="activeTab === 'pricing' ? 'bg-slate-900 text-white font-medium' : 'bg-slate-100 text-slate-700 hover:bg-slate-200'" class="rounded-lg px-4 py-2 text-sm transition">السعر والمخزون</button>
        <button type="button" @click="activeTab = 'variants'" :class="activeTab === 'variants' ? 'bg-slate-900 text-white font-medium' : 'bg-slate-100 text-slate-700 hover:bg-slate-200'" class="rounded-lg px-4 py-2 text-sm transition">
            النسخ والخيارات (<span x-text="variants.length"></span>)
        </button>
        <button type="button" @click="activeTab = 'media'" :class="activeTab === 'media' ? 'bg-slate-900 text-white font-medium' : 'bg-slate-100 text-slate-700 hover:bg-slate-200'" class="rounded-lg px-4 py-2 text-sm transition">الوسائط والصور</button>
        <button type="button" @click="activeTab = 'description'" :class="activeTab === 'description' ? 'bg-slate-900 text-white font-medium' : 'bg-slate-100 text-slate-700 hover:bg-slate-200'" class="rounded-lg px-4 py-2 text-sm transition">الوصف والاكتشاف</button>
        <button type="button" @click="activeTab = 'policies'" :class="activeTab === 'policies' ? 'bg-slate-900 text-white font-medium' : 'bg-slate-100 text-slate-700 hover:bg-slate-200'" class="rounded-lg px-4 py-2 text-sm transition">السياسات وذكاء AI</button>
    </div>

    <!-- Tab 1: Basic Info -->
    <div x-show="activeTab === 'basic'" class="space-y-4">
        <div class="grid gap-4 md:grid-cols-2">
            <div>
                <label class="mb-1 block text-sm font-medium text-slate-700">اسم الصنف <span class="text-red-500">*</span></label>
                <input name="name" required value="{{ old('name', $item?->name) }}" class="w-full rounded-lg border-slate-300 text-sm focus:border-slate-500 focus:ring-slate-500" placeholder="مثال: فستان حرير أحمر" />
                <x-input-error :messages="$errors->get('name')" class="mt-1" />
            </div>
            <div>
                <label class="mb-1 block text-sm font-medium text-slate-700">الرابط الفريد (Slug)</label>
                <input name="slug" value="{{ old('slug', $item?->slug) }}" class="w-full rounded-lg border-slate-300 text-sm focus:border-slate-500 focus:ring-slate-500" placeholder="تلقائي عند الترك فارغاً" />
                <x-input-error :messages="$errors->get('slug')" class="mt-1" />
            </div>
            <div>
                <label class="mb-1 block text-sm font-medium text-slate-700">رمز المنتج (SKU)</label>
                <input name="sku" value="{{ old('sku', $item?->sku) }}" class="w-full rounded-lg border-slate-300 text-sm focus:border-slate-500 focus:ring-slate-500" placeholder="PROD-001" />
                <x-input-error :messages="$errors->get('sku')" class="mt-1" />
            </div>
            <div>
                <label class="mb-1 block text-sm font-medium text-slate-700">الباركود</label>
                <input name="barcode" value="{{ old('barcode', $item?->barcode) }}" class="w-full rounded-lg border-slate-300 text-sm focus:border-slate-500 focus:ring-slate-500" placeholder="6291100000000" />
                <x-input-error :messages="$errors->get('barcode')" class="mt-1" />
            </div>
            <div>
                <label class="mb-1 block text-sm font-medium text-slate-700">العلامة التجارية (Brand)</label>
                <input name="brand" value="{{ old('brand', $item?->brand) }}" class="w-full rounded-lg border-slate-300 text-sm focus:border-slate-500 focus:ring-slate-500" />
                <x-input-error :messages="$errors->get('brand')" class="mt-1" />
            </div>
            <div>
                <label class="mb-1 block text-sm font-medium text-slate-700">التصنيف</label>
                <select name="chat_catalog_category_id" class="w-full rounded-lg border-slate-300 text-sm focus:border-slate-500 focus:ring-slate-500">
                    <option value="">بدون تصنيف</option>
                    @foreach($categories as $category)
                        <option value="{{ $category->id }}" @selected((int) old('chat_catalog_category_id', $item?->chat_catalog_category_id) === $category->id)>{{ $category->name }}</option>
                    @endforeach
                </select>
                <x-input-error :messages="$errors->get('chat_catalog_category_id')" class="mt-1" />
            </div>
            <div>
                <label class="mb-1 block text-sm font-medium text-slate-700">النوع</label>
                <select name="item_kind" class="w-full rounded-lg border-slate-300 text-sm focus:border-slate-500 focus:ring-slate-500">
                    <option value="product" @selected(old('item_kind', $item?->item_kind ?? 'product') === 'product')>منتج (Product)</option>
                    <option value="service" @selected(old('item_kind', $item?->item_kind) === 'service')>خدمة (Service)</option>
                </select>
                <x-input-error :messages="$errors->get('item_kind')" class="mt-1" />
            </div>
            <div>
                <label class="mb-1 block text-sm font-medium text-slate-700">الطبيعة التجاریة</label>
                <select name="tangibility" class="w-full rounded-lg border-slate-300 text-sm focus:border-slate-500 focus:ring-slate-500">
                    <option value="tangible" @selected(old('tangibility', $item?->tangibility ?? 'tangible') === 'tangible')>ملموس (Tangible)</option>
                    <option value="intangible" @selected(old('tangibility', $item?->tangibility) === 'intangible')>غير ملموس / رقمي (Intangible)</option>
                </select>
                <x-input-error :messages="$errors->get('tangibility')" class="mt-1" />
            </div>
            <div>
                <label class="mb-1 block text-sm font-medium text-slate-700">التصنيف التشغيلي</label>
                <select name="classification" x-model="classification" class="w-full rounded-lg border-slate-300 text-sm focus:border-slate-500 focus:ring-slate-500">
                    <option value="physical">مادي (Physical)</option>
                    <option value="digital">رقمي (Digital)</option>
                    <option value="service">خدمة (Service)</option>
                </select>
                <x-input-error :messages="$errors->get('classification')" class="mt-1" />
            </div>
            <div x-show="classification === 'digital'">
                <label class="mb-1 block text-sm font-medium text-slate-700">نوع المنتج الرقمي</label>
                <select name="digital_subtype" class="w-full rounded-lg border-slate-300 text-sm focus:border-slate-500 focus:ring-slate-500">
                    <option value="">اختر النوع الرقمي</option>
                    @foreach(['file'=>'ملف (File)','code'=>'كود / رمز (Code)','license'=>'ترخيص (License)','credential'=>'بيانات دخول (Credential)','account'=>'حساب (Account)','subscription'=>'اشتراك (Subscription)','digital_bundle'=>'حزمة رقمية (Bundle)','custom_digital'=>'مخصص (Custom)'] as $val => $lbl)
                        <option value="{{ $val }}" @selected(old('digital_subtype', $item?->digital_subtype) === $val)>{{ $lbl }}</option>
                    @endforeach
                </select>
                <x-input-error :messages="$errors->get('digital_subtype')" class="mt-1" />
            </div>
            <div>
                <label class="mb-1 block text-sm font-medium text-slate-700">الترتيب</label>
                <input type="number" min="0" name="sort_order" value="{{ old('sort_order', $item?->sort_order ?? 0) }}" class="w-full rounded-lg border-slate-300 text-sm focus:border-slate-500 focus:ring-slate-500" />
                <x-input-error :messages="$errors->get('sort_order')" class="mt-1" />
            </div>
        </div>

        <div class="flex flex-wrap gap-4 pt-2">
            <label class="inline-flex items-center gap-2 text-sm font-medium text-slate-700">
                <input type="hidden" name="is_active" value="0" />
                <input type="checkbox" name="is_active" value="1" @checked(old('is_active', $item?->is_active ?? true)) class="rounded border-slate-300 text-slate-900 focus:ring-slate-500" />
                <span>الصنف نشط (Active)</span>
            </label>
            <label class="inline-flex items-center gap-2 text-sm font-medium text-slate-700">
                <input type="hidden" name="is_visible" value="0" />
                <input type="checkbox" name="is_visible" value="1" @checked(old('is_visible', $item?->is_visible ?? true)) class="rounded border-slate-300 text-slate-900 focus:ring-slate-500" />
                <span>ظاهر للعملاء (Visible)</span>
            </label>
            <label class="inline-flex items-center gap-2 text-sm font-medium text-slate-700">
                <input type="hidden" name="is_searchable" value="0" />
                <input type="checkbox" name="is_searchable" value="1" @checked(old('is_searchable', $item?->is_searchable ?? true)) class="rounded border-slate-300 text-slate-900 focus:ring-slate-500" />
                <span>قابل للبحث (Searchable)</span>
            </label>
        </div>
    </div>

    <!-- Tab 2: Pricing & Inventory -->
    <div x-show="activeTab === 'pricing'" class="space-y-4">
        <div class="grid gap-4 md:grid-cols-2">
            <div>
                <label class="mb-1 block text-sm font-medium text-slate-700">السعر الأساسي <span class="text-red-500">*</span></label>
                <input type="number" step="0.01" min="0" name="price" required value="{{ old('price', $item?->price ?? 0) }}" class="w-full rounded-lg border-slate-300 text-sm focus:border-slate-500 focus:ring-slate-500" />
                <x-input-error :messages="$errors->get('price')" class="mt-1" />
            </div>
            <div>
                <label class="mb-1 block text-sm font-medium text-slate-700">سعر التخفيض (Sale Price)</label>
                <input type="number" step="0.01" min="0" name="sale_price" value="{{ old('sale_price', $item?->sale_price) }}" class="w-full rounded-lg border-slate-300 text-sm focus:border-slate-500 focus:ring-slate-500" />
                <x-input-error :messages="$errors->get('sale_price')" class="mt-1" />
            </div>
            <div>
                <label class="mb-1 block text-sm font-medium text-slate-700">السعر المقارن (Compare At)</label>
                <input type="number" step="0.01" min="0" name="compare_at_price" value="{{ old('compare_at_price', $item?->compare_at_price) }}" class="w-full rounded-lg border-slate-300 text-sm focus:border-slate-500 focus:ring-slate-500" />
                <x-input-error :messages="$errors->get('compare_at_price')" class="mt-1" />
            </div>
            <div>
                <label class="mb-1 block text-sm font-medium text-slate-700">العملة</label>
                <input name="currency" maxlength="3" value="{{ old('currency', $item?->currency) }}" placeholder="{{ $catalog?->default_currency ?? 'SAR' }}" class="w-full rounded-lg border-slate-300 text-sm focus:border-slate-500 focus:ring-slate-500 uppercase" />
                <x-input-error :messages="$errors->get('currency')" class="mt-1" />
            </div>
            <div>
                <label class="mb-1 block text-sm font-medium text-slate-700">سياسة المخزون</label>
                <select name="inventory_policy" x-model="inventoryPolicy" class="w-full rounded-lg border-slate-300 text-sm focus:border-slate-500 focus:ring-slate-500">
                    <option value="finite_stock">مخزون محدود كمي (Finite Stock)</option>
                    <option value="not_tracked">غير متتبع (Not Tracked)</option>
                    <option value="unlimited">غير محدود (Unlimited)</option>
                    <option value="backorder">طلب مسبق (Backorder)</option>
                    <option value="on_request">عند الطلب (On Request)</option>
                    <option value="digital_pool">حوض مخزون رقمي (Digital Pool)</option>
                    <option value="file_asset">أصل ملف رقمي (File Asset)</option>
                    <option value="service_capacity">سعة تقديم خدمة (Service Capacity)</option>
                </select>
                <x-input-error :messages="$errors->get('inventory_policy')" class="mt-1" />
            </div>
            <div x-show="inventoryPolicy === 'finite_stock' || inventoryPolicy === 'backorder'">
                <label class="mb-1 block text-sm font-medium text-slate-700">كمية المخزون المتوفرة</label>
                <input type="number" min="0" name="stock" value="{{ old('stock', $item?->stock) }}" class="w-full rounded-lg border-slate-300 text-sm focus:border-slate-500 focus:ring-slate-500" placeholder="0" />
                <x-input-error :messages="$errors->get('stock')" class="mt-1" />
            </div>
        </div>
    </div>

    <!-- Tab 3: Variants -->
    <div x-show="activeTab === 'variants'" class="space-y-4">
        <div class="flex items-center justify-between">
            <h3 class="text-sm font-semibold text-slate-800">نسخ المنتج (Product Variants)</h3>
            <button type="button" @click="addVariant()" class="rounded-lg bg-slate-900 px-3 py-1.5 text-xs text-white hover:bg-slate-800 transition">
                + إضافة نسخة جديدة
            </button>
        </div>

        <template x-if="variants.length === 0">
            <div class="rounded-xl border border-dashed border-slate-300 p-6 text-center text-sm text-slate-500">
                لا توجد نسخ محددة. الصنف الأساسي هو النسخة الوحيدة. اضغط "+ إضافة نسخة" لإضافة أحجام أو ألوان أو خيارات أخرى.
            </div>
        </template>

        <div class="space-y-3">
            <template x-for="(variant, index) in variants" :key="index">
                <div class="rounded-xl border border-slate-200 bg-slate-50 p-4 space-y-3">
                    <div class="flex items-center justify-between">
                        <span class="text-xs font-semibold text-slate-700" x-text="'النسخة #' + (index + 1) + (variant.name ? ' - ' + variant.name : '')"></span>
                        <button type="button" @click="removeVariant(index)" class="text-xs text-red-600 hover:underline">إزالة النسخة</button>
                    </div>
                    <input type="hidden" :name="'variants[' + index + '][id]'" :value="variant.id">
                    <div class="grid gap-3 md:grid-cols-4">
                        <div>
                            <label class="block text-xs font-medium text-slate-700">اسم النسخة</label>
                            <input :name="'variants[' + index + '][name]'" x-model="variant.name" required class="mt-1 w-full rounded-md border-slate-300 text-xs" placeholder="مثال: مقاس كبير - أحمر" />
                        </div>
                        <div>
                            <label class="block text-xs font-medium text-slate-700">SKU النسخة</label>
                            <input :name="'variants[' + index + '][sku]'" x-model="variant.sku" class="mt-1 w-full rounded-md border-slate-300 text-xs" placeholder="SKU-VAR-1" />
                        </div>
                        <div>
                            <label class="block text-xs font-medium text-slate-700">السعر</label>
                            <input type="number" step="0.01" min="0" :name="'variants[' + index + '][price]'" x-model="variant.price" class="mt-1 w-full rounded-md border-slate-300 text-xs" placeholder="0.00" />
                        </div>
                        <div>
                            <label class="block text-xs font-medium text-slate-700">المخزون</label>
                            <input type="number" min="0" :name="'variants[' + index + '][stock]'" x-model="variant.stock" class="mt-1 w-full rounded-md border-slate-300 text-xs" placeholder="0" />
                        </div>
                    </div>
                </div>
            </template>
        </div>
    </div>

    <!-- Tab 4: Media -->
    <div x-show="activeTab === 'media'" class="space-y-4">
        <div>
            <label class="mb-1 block text-sm font-medium text-slate-700">الصورة الرئيسية للصنف</label>
            @if($item?->image_path)
                <div class="mb-3 flex items-center gap-3 rounded-lg border border-slate-200 bg-slate-50 p-2">
                    <img src="{{ \Illuminate\Support\Facades\Storage::disk('public')->url($item->image_path) }}" alt="" class="h-16 w-16 rounded-md object-cover">
                    <span class="text-xs text-slate-600">الصورة الحالية مسجلة</span>
                </div>
            @endif
            <input type="file" name="image_file" accept="image/*" class="w-full text-sm text-slate-600 file:mr-4 file:rounded-lg file:border-0 file:bg-slate-900 file:px-4 file:py-2 file:text-sm file:font-semibold file:text-white hover:file:bg-slate-800" />
            <x-input-error :messages="$errors->get('image_file')" class="mt-1" />
        </div>

        @if($item?->media && $item->media->isNotEmpty())
            <div class="mt-4 border-t pt-4">
                <h4 class="mb-3 text-sm font-semibold text-slate-800">معرض الصور والوسائط المسجلة</h4>
                <div class="grid gap-3 sm:grid-cols-2 lg:grid-cols-3">
                    @foreach($item->media as $m)
                        <div class="flex items-center gap-3 rounded-lg border p-2">
                            @if($m->kind === 'video_reference')
                                <div class="flex h-12 w-12 items-center justify-center rounded bg-slate-100 text-xs font-bold text-slate-600">فيديو</div>
                            @else
                                <img src="{{ \Illuminate\Support\Facades\Storage::disk('public')->url($m->path) }}" alt="" class="h-12 w-12 rounded object-cover">
                            @endif
                            <div class="text-xs">
                                <span class="font-medium text-slate-800">{{ $m->kind }}</span>
                                @if($m->alt)<p class="text-slate-500 truncate max-w-[120px]">{{ $m->alt }}</p>@endif
                            </div>
                        </div>
                    @endforeach
                </div>
            </div>
        @endif
    </div>

    <!-- Tab 5: Description & Discovery -->
    <div x-show="activeTab === 'description'" class="space-y-4">
        <div>
            <label class="mb-1 block text-sm font-medium text-slate-700">الوصف التفصيلي</label>
            <textarea name="description" rows="4" class="w-full rounded-lg border-slate-300 text-sm focus:border-slate-500 focus:ring-slate-500">{{ old('description', $item?->description) }}</textarea>
            <x-input-error :messages="$errors->get('description')" class="mt-1" />
        </div>
        <div class="grid gap-4 md:grid-cols-2">
            <div>
                <label class="mb-1 block text-sm font-medium text-slate-700">وصف مختصر</label>
                <textarea name="short_description" rows="3" class="w-full rounded-lg border-slate-300 text-sm focus:border-slate-500 focus:ring-slate-500">{{ old('short_description', $item?->short_description) }}</textarea>
                <x-input-error :messages="$errors->get('short_description')" class="mt-1" />
            </div>
            <div>
                <label class="mb-1 block text-sm font-medium text-slate-700">وصف العميل الظاهر</label>
                <textarea name="customer_description" rows="3" class="w-full rounded-lg border-slate-300 text-sm focus:border-slate-500 focus:ring-slate-500">{{ old('customer_description', $item?->customer_description) }}</textarea>
                <x-input-error :messages="$errors->get('customer_description')" class="mt-1" />
            </div>
            <div>
                <label class="mb-1 block text-sm font-medium text-slate-700">إرشادات الاستخدام</label>
                <textarea name="usage_instructions" rows="3" class="w-full rounded-lg border-slate-300 text-sm focus:border-slate-500 focus:ring-slate-500">{{ old('usage_instructions', $item?->usage_instructions) }}</textarea>
                <x-input-error :messages="$errors->get('usage_instructions')" class="mt-1" />
            </div>
            <div>
                <label class="mb-1 block text-sm font-medium text-slate-700">تحذيرات وملاحظات الهامة</label>
                <textarea name="warnings" rows="3" class="w-full rounded-lg border-slate-300 text-sm focus:border-slate-500 focus:ring-slate-500">{{ old('warnings', $item?->warnings) }}</textarea>
                <x-input-error :messages="$errors->get('warnings')" class="mt-1" />
            </div>
        </div>

        <div class="border-t pt-4">
            <h4 class="mb-3 text-sm font-semibold text-slate-800">علامات التمييز (Badges)</h4>
            <div class="grid gap-3 sm:grid-cols-2 md:grid-cols-4">
                <label class="inline-flex items-center gap-2 text-sm text-slate-700">
                    <input type="hidden" name="is_featured" value="0"><input type="checkbox" name="is_featured" value="1" @checked(old('is_featured', $item?->is_featured)) class="rounded border-slate-300" />
                    <span>مميز (Featured)</span>
                </label>
                <label class="inline-flex items-center gap-2 text-sm text-slate-700">
                    <input type="hidden" name="is_bestseller" value="0"><input type="checkbox" name="is_bestseller" value="1" @checked(old('is_bestseller', $item?->is_bestseller)) class="rounded border-slate-300" />
                    <span>الأكثر مبيعاً (Bestseller)</span>
                </label>
                <label class="inline-flex items-center gap-2 text-sm text-slate-700">
                    <input type="hidden" name="is_new" value="0"><input type="checkbox" name="is_new" value="1" @checked(old('is_new', $item?->is_new)) class="rounded border-slate-300" />
                    <span>جديد (New)</span>
                </label>
                <label class="inline-flex items-center gap-2 text-sm text-slate-700">
                    <input type="hidden" name="is_recommended" value="0"><input type="checkbox" name="is_recommended" value="1" @checked(old('is_recommended', $item?->is_recommended)) class="rounded border-slate-300" />
                    <span>موصى به (Recommended)</span>
                </label>
            </div>
        </div>
    </div>

    <!-- Tab 6: Policies & AI -->
    <div x-show="activeTab === 'policies'" class="space-y-4">
        <div class="rounded-xl border border-slate-200 bg-slate-50 p-4 space-y-4">
            <h4 class="text-sm font-semibold text-slate-800">سياسات العميل والتجارة الإلكترونية</h4>
            <div class="grid gap-3 sm:grid-cols-2 md:grid-cols-3">
                @foreach([
                    'returnable' => 'قابل للإرجاع (Returnable)',
                    'refundable' => 'قابل للاسترداد (Refundable)',
                    'exchangeable' => 'قابل للاستبدال (Exchangeable)',
                    'cancellation_allowed' => 'يسمح بالإلغاء (Cancellation)',
                    'warranty_available' => 'ضمان متاح (Warranty)',
                    'terms_required' => 'الشروط مطلوبة (Terms Req.)',
                    'customer_confirmation_required' => 'تأكيد العميل مطلوب',
                    'non_returnable' => 'رقمي غير قابل للإرجاع',
                    'non_refundable' => 'رقمي غير قابل للاسترداد',
                ] as $pKey => $pLabel)
                    <label class="inline-flex items-center gap-2 text-xs text-slate-700">
                        <input type="hidden" name="policies[{{ $pKey }}]" value="0" />
                        <input type="checkbox" name="policies[{{ $pKey }}]" value="1" @checked(old('policies.'.$pKey, data_get($item?->policies, $pKey, false))) class="rounded border-slate-300" />
                        <span>{{ $pLabel }}</span>
                    </label>
                @endforeach
            </div>
        </div>

        <div class="rounded-xl border border-slate-200 bg-slate-50 p-4 space-y-3">
            <h4 class="text-sm font-semibold text-slate-800">ملف معلومات الذكاء الاصطناعي الآمن (AI Safe Profile)</h4>
            <div>
                <label class="mb-1 block text-xs font-medium text-slate-700">ملخص الصنف الآمن للعميل (Customer Summary)</label>
                <textarea name="ai_safe_profile[customer_summary]" rows="3" class="w-full rounded-lg border-slate-300 text-xs" placeholder="ملخص موجه لـ Inbox AI ليجيب به على استفسارات العملاء دقيقة وبدون اختلاق facts.">{{ old('ai_safe_profile.customer_summary', data_get($item?->ai_safe_profile, 'customer_summary')) }}</textarea>
            </div>
        </div>
    </div>

</div>
