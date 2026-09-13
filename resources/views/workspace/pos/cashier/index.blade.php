@extends('layouts.pos', ['pageTitle' => 'واجهة الكاشير'])

@section('content')
    @include('workspace.pos.partials.order-channel-stats', ['orderChannelStats' => $orderChannelStats])

    {{--
      Layout (RTL): Categories RIGHT | Products CENTER | Cart LEFT (narrower)
      Categories sidebar kept as-is; cart narrowed; product cards denser.
    --}}
    <div
        x-data="cashierPos({
            items: @js($items),
            categories: @js($categories),
            storeOrderUrl: @js($storeOrderUrl),
            recentMenuOrdersUrl: @js($recentMenuOrdersUrl),
            orderStatsUrl: @js($orderStatsUrl),
            taxRate: @js((float) $taxRate),
            soundEnabled: @js((bool) $soundEnabled),
            enableDelivery: @js((bool) data_get(request()->attributes->get('workspace')?->settings ?? [], 'pos.enable_delivery', false)),
            cartEndpoints: {
                addItem: @js(route('workspace.pos.cart.items.store')),
                updateItem: @js(url('/workspace/pos/cart/items')),
                removeItem: @js(url('/workspace/pos/cart/items')),
                meta: @js(route('workspace.pos.cart.meta')),
                checkout: @js(route('workspace.pos.cart.checkout')),
                clear: @js(route('workspace.pos.cart.clear')),
                csrf: @js(csrf_token()),
            },
        })"
        class="grid gap-3 xl:grid-cols-12"
        @pos-cart-toggle.window="document.querySelector('[data-pos-cart]')?.scrollIntoView({ behavior: 'smooth', block: 'start' })"
    >
        {{-- Categories sidebar (RIGHT in RTL = first column) — KEEP EXISTING --}}
        <aside class="hidden xl:col-span-2 xl:block" data-pos-categories-sidebar>
            <nav
                class="sticky top-3 max-h-[calc(100vh-7rem)] overflow-y-auto rounded-2xl border border-slate-200 bg-white p-2 shadow-sm"
                aria-label="التصنيفات"
            >
                <p class="mb-2 px-2 text-xs font-bold text-slate-500">التصنيفات</p>

                <button
                    type="button"
                    @click="selectedCategoryId = ''"
                    :class="selectedCategoryId === ''
                        ? 'border-[var(--hs-brand,#06C2A4)] bg-[var(--hs-brand,#06C2A4)] text-white'
                        : 'border-slate-200 bg-white text-slate-700 hover:bg-slate-50'"
                    class="mb-1 flex w-full items-center justify-between rounded-xl border px-3 py-2.5 text-right text-sm font-semibold transition"
                >
                    <span>الكل</span>
                    <span class="text-[11px] opacity-80" x-text="items.length"></span>
                </button>

                <template x-for="category in categories" :key="category.id">
                    <button
                        type="button"
                        @click="selectedCategoryId = String(category.id)"
                        :class="String(selectedCategoryId) === String(category.id)
                            ? 'border-[var(--hs-brand,#06C2A4)] bg-[var(--hs-brand,#06C2A4)] text-white'
                            : 'border-slate-200 bg-white text-slate-700 hover:bg-slate-50'"
                        class="mb-1 flex w-full items-center justify-between rounded-xl border px-3 py-2.5 text-right text-sm font-semibold transition"
                    >
                        <span class="truncate" x-text="category.name"></span>
                        <span class="shrink-0 text-[11px] opacity-80" x-text="countInCategory(category.id)"></span>
                    </button>
                </template>
            </nav>
        </aside>

        {{-- Products (CENTER) — denser cards --}}
        <section class="rounded-2xl border border-slate-200 bg-white p-3 shadow-sm xl:col-span-7" data-pos-products>
            <div class="flex flex-wrap items-center justify-between gap-2">
                <h2 class="text-sm font-bold text-slate-900">أصناف الكاشير</h2>
                <input
                    x-model="search"
                    @keydown.enter.prevent="addByBarcode()"
                    type="search"
                    placeholder="ابحث بالاسم أو الباركود أو SKU..."
                    class="w-full max-w-xs rounded-lg border-slate-300 bg-white text-sm sm:w-72"
                />
            </div>

            {{-- Narrow screens: horizontal category chips (never a dropdown) --}}
            <div class="mt-3 flex gap-2 overflow-x-auto pb-1 xl:hidden" data-pos-categories-mobile>
                <button
                    type="button"
                    @click="selectedCategoryId = ''"
                    :class="selectedCategoryId === ''
                        ? 'border-[var(--hs-brand,#06C2A4)] bg-[var(--hs-brand,#06C2A4)] text-white'
                        : 'border-slate-200 bg-white text-slate-700'"
                    class="shrink-0 rounded-lg border px-3 py-1.5 text-xs font-semibold"
                >الكل</button>
                <template x-for="category in categories" :key="'m-'+category.id">
                    <button
                        type="button"
                        @click="selectedCategoryId = String(category.id)"
                        :class="String(selectedCategoryId) === String(category.id)
                            ? 'border-[var(--hs-brand,#06C2A4)] bg-[var(--hs-brand,#06C2A4)] text-white'
                            : 'border-slate-200 bg-white text-slate-700'"
                        class="shrink-0 rounded-lg border px-3 py-1.5 text-xs font-semibold"
                        x-text="category.name"
                    ></button>
                </template>
            </div>

            <div class="mt-3 grid grid-cols-2 gap-2 sm:grid-cols-3 md:grid-cols-4 xl:grid-cols-4 2xl:grid-cols-5">
                <template x-for="item in filteredItems" :key="item.id">
                    <button
                        type="button"
                        @click="addItem(item)"
                        class="group flex flex-col overflow-hidden rounded-xl border border-slate-200 bg-white text-right transition hover:border-slate-300 hover:shadow-sm"
                    >
                        <div class="relative aspect-[5/3] w-full bg-slate-50">
                            <template x-if="item.image_path">
                                <img :src="`/storage/${item.image_path}`" alt="" class="h-full w-full object-cover" />
                            </template>
                            <template x-if="!item.image_path">
                                <div class="flex h-full w-full items-center justify-center text-slate-300">
                                    <svg class="h-7 w-7" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M4 16l4.586-4.586a2 2 0 012.828 0L16 16m-2-2l1.586-1.586a2 2 0 012.828 0L20 14M6 20h12a2 2 0 002-2V6a2 2 0 00-2-2H6a2 2 0 00-2 2v12a2 2 0 002 2z"/></svg>
                                </div>
                            </template>
                        </div>
                        <div class="flex flex-1 flex-col gap-0.5 p-2">
                            <p class="line-clamp-2 text-xs font-semibold leading-snug text-slate-900" x-text="item.name"></p>
                            <p class="text-xs font-bold text-slate-900">
                                <span x-text="money(item.price)"></span>
                                <span class="text-[10px] font-medium text-slate-500" x-text="item.currency"></span>
                            </p>
                            <span class="mt-1 inline-flex items-center justify-center rounded-lg border border-emerald-600 px-2 py-1 text-[10px] font-semibold text-emerald-700">+ إضافة</span>
                        </div>
                    </button>
                </template>
            </div>

            <template x-if="filteredItems.length === 0">
                <div class="mt-8 text-center">
                    <p class="text-sm text-slate-500">لا توجد منتجات في هذا التصنيف.</p>
                    <button type="button" @click="selectedCategoryId = ''; search = ''" class="mt-2 text-sm font-semibold text-[var(--hs-brand,#06C2A4)] underline">عرض الكل</button>
                </div>
            </template>
        </section>

        {{-- Cart / order (LEFT, narrower) --}}
        <aside class="space-y-3 xl:col-span-3" data-pos-cart>
            <article class="rounded-2xl border border-slate-200 bg-white p-3 shadow-sm">
                <h2 class="text-sm font-bold text-slate-900">طلب جديد</h2>
                <form method="POST" action="{{ route('workspace.pos.orders.store') }}" @submit.prevent="createOrder">
                    @csrf
                    <div class="mt-3 space-y-2">
                        <div>
                            <label class="mb-1 block text-[11px] font-semibold text-slate-600">نوع الطلب</label>
                            <div class="grid grid-cols-3 gap-1">
                                <button type="button" @click="setOrderType('table')" :class="orderType === 'table' ? 'bg-emerald-600 text-white border-emerald-600' : 'bg-white text-slate-700 border-slate-200'" class="rounded-lg border px-2 py-1.5 text-[11px] font-semibold">طاولة</button>
                                <button type="button" @click="setOrderType('takeaway')" :class="orderType === 'takeaway' ? 'bg-emerald-600 text-white border-emerald-600' : 'bg-white text-slate-700 border-slate-200'" class="rounded-lg border px-2 py-1.5 text-[11px] font-semibold">خارجي</button>
                                <button type="button" x-show="enableDelivery" @click="setOrderType('delivery')" :class="orderType === 'delivery' ? 'bg-emerald-600 text-white border-emerald-600' : 'bg-white text-slate-700 border-slate-200'" class="rounded-lg border px-2 py-1.5 text-[11px] font-semibold">توصيل</button>
                            </div>
                        </div>
                        <div>
                            <label class="mb-1 block text-[11px] font-semibold text-slate-600">العميل (اختياري)</label>
                            <select name="customer_id" x-model="customerId" @change="syncMeta" class="w-full rounded-lg border-slate-300 text-sm">
                                <option value="">بدون عميل</option>
                                @foreach($customers as $customer)
                                    <option value="{{ $customer->id }}">{{ $customer->name }}{{ $customer->phone ? ' - '.$customer->phone : '' }}</option>
                                @endforeach
                            </select>
                        </div>
                        <div x-show="orderType === 'table'">
                            <label class="mb-1 block text-[11px] font-semibold text-slate-600">الطاولة</label>
                            <select name="dining_table_id" x-model="diningTableId" @change="syncMeta" class="w-full rounded-lg border-slate-300 text-sm">
                                <option value="">اختر طاولة</option>
                                @foreach($tables as $table)
                                    <option value="{{ $table->id }}">{{ $table->name }} - {{ match($table->status) { 'occupied' => 'مشغولة', 'reserved' => 'محجوزة', 'cleaning' => 'تنظيف', 'closed' => 'مغلقة', default => 'فارغة' } }}</option>
                                @endforeach
                            </select>
                        </div>
                        <div>
                            <label class="mb-1 block text-[11px] font-semibold text-slate-600">الخصم (مبلغ)</label>
                            <input x-model.number="discount" @change="syncMeta" type="number" name="discount_amount" min="0" step="0.01" class="w-full rounded-lg border-slate-300 text-sm" />
                        </div>
                        <div>
                            <label class="mb-1 block text-[11px] font-semibold text-slate-600">الخصم (%)</label>
                            <input x-model.number="discountPercent" type="number" min="0" max="100" step="0.01" class="w-full rounded-lg border-slate-300 text-sm" />
                        </div>
                        <div>
                            <label class="mb-1 block text-[11px] font-semibold text-slate-600">ملاحظات</label>
                            <textarea name="notes" x-model="notes" @change="syncMeta" rows="2" class="w-full rounded-lg border-slate-300 text-sm"></textarea>
                        </div>
                    </div>

                    <div class="mt-3 rounded-xl border border-slate-200 bg-slate-50 p-2.5">
                        <h3 class="text-[11px] font-bold text-slate-700">ملخص الطلب</h3>
                        <template x-if="cart.length === 0">
                            <p class="mt-2 text-xs text-slate-500">السلة فارغة.</p>
                        </template>
                        <div class="mt-2 max-h-40 space-y-1.5 overflow-y-auto">
                            <template x-for="(line, index) in cart" :key="line.pos_menu_item_id">
                                <div class="rounded-lg bg-white p-1.5 text-[11px]">
                                    <div class="flex items-center justify-between gap-2">
                                        <p class="truncate font-semibold text-slate-800" x-text="line.name"></p>
                                        <button type="button" @click="removeLine(index)" class="text-rose-600">حذف</button>
                                    </div>
                                    <div class="mt-1.5 flex items-center justify-between">
                                        <div class="flex items-center gap-1.5">
                                            <button type="button" @click="decrease(index)" class="rounded border border-slate-300 px-1.5">-</button>
                                            <span x-text="line.quantity"></span>
                                            <button type="button" @click="increase(index)" class="rounded border border-slate-300 px-1.5">+</button>
                                        </div>
                                        <p class="font-semibold">
                                            <span x-text="money(line.quantity * line.unit_price)"></span>
                                            <span x-text="line.currency"></span>
                                        </p>
                                    </div>
                                </div>
                            </template>
                        </div>
                        <div class="mt-2 space-y-0.5 border-t border-slate-200 pt-2 text-[11px] text-slate-700">
                            <div class="flex justify-between"><span>المجموع الفرعي</span><span class="font-semibold" x-text="money(subtotal)"></span></div>
                            <div class="flex justify-between"><span>الخصم</span><span class="font-semibold" x-text="money(effectiveDiscount)"></span></div>
                            <div class="flex justify-between"><span>الضريبة</span><span class="font-semibold" x-text="money(taxAmount)"></span></div>
                            <div class="flex justify-between text-sm font-bold text-slate-900">
                                <span>الإجمالي</span>
                                <span>
                                    <span x-text="money(total)"></span>
                                    <span class="text-[10px] font-semibold text-slate-500" x-text="orderCurrencyLabel"></span>
                                </span>
                            </div>
                        </div>
                    </div>

                    <div class="mt-3 grid gap-2">
                        <button
                            type="submit"
                            :disabled="submitting || cart.length === 0"
                            class="inline-flex w-full items-center justify-center gap-2 rounded-lg bg-emerald-600 px-3 py-2 text-sm font-semibold text-white hover:bg-emerald-700 disabled:cursor-not-allowed disabled:opacity-60"
                        >
                            <svg x-show="submitting" class="h-4 w-4 animate-spin" viewBox="0 0 24 24" fill="none"><circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle><path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8v4a4 4 0 00-4 4H4z"></path></svg>
                            <span x-text="submitting ? 'جاري الإنشاء...' : 'إنشاء الطلب'"></span>
                        </button>
                        <button
                            type="button"
                            @click="checkoutViaCart"
                            :disabled="submitting || cart.length === 0"
                            class="w-full rounded-lg border border-emerald-600 px-3 py-2 text-sm font-semibold text-emerald-700 hover:bg-emerald-50 disabled:opacity-60"
                        >
                            طلب خارجي
                        </button>
                    </div>

                    <template x-if="errorMessage">
                        <div class="mt-3 rounded-lg border border-rose-200 bg-rose-50 p-2 text-xs text-rose-700">
                            <p x-text="errorMessage"></p>
                            <button type="button" @click="createOrder" class="mt-2 font-semibold underline">إعادة المحاولة</button>
                        </div>
                    </template>
                </form>
            </article>
        </aside>

        {{-- Success: optional print --}}
        <div
            x-show="successOpen"
            x-cloak
            class="fixed inset-0 z-50 flex items-center justify-center bg-slate-900/40 p-4"
            @keydown.escape.window="closeSuccess(false)"
        >
            <div class="w-full max-w-sm rounded-2xl border border-slate-200 bg-white p-5 shadow-xl" @click.outside="closeSuccess(false)">
                <div class="mx-auto flex h-12 w-12 items-center justify-center rounded-full bg-emerald-50 text-emerald-600">
                    <svg class="h-7 w-7" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 13l4 4L19 7"/></svg>
                </div>
                <h3 class="mt-3 text-center text-base font-bold text-slate-900">تم إنشاء الطلب بنجاح</h3>
                <p class="mt-1 text-center text-sm text-slate-600" x-show="successOrderNumber">
                    رقم الطلب: #<span x-text="successOrderNumber"></span>
                </p>
                <div class="mt-5 grid gap-2">
                    <button
                        type="button"
                        @click="closeSuccess(true)"
                        :disabled="!successPrintUrl"
                        class="rounded-lg bg-emerald-600 px-3 py-2.5 text-sm font-bold text-white hover:bg-emerald-700 disabled:opacity-50"
                    >طباعة الفاتورة</button>
                    <button
                        type="button"
                        @click="closeSuccess(false)"
                        class="rounded-lg border border-emerald-600 bg-white px-3 py-2.5 text-sm font-semibold text-emerald-700 hover:bg-emerald-50"
                    >متابعة بدون طباعة</button>
                </div>
            </div>
        </div>
    </div>

    <script>
        function cashierPos({ items, categories, cartEndpoints, storeOrderUrl, recentMenuOrdersUrl = null, orderStatsUrl = null, taxRate = 0, soundEnabled = true, enableDelivery = false }) {
            return {
                items,
                categories,
                cartEndpoints,
                storeOrderUrl,
                recentMenuOrdersUrl,
                orderStatsUrl,
                taxRate: Number(taxRate || 0),
                soundEnabled: !!soundEnabled,
                enableDelivery: !!enableDelivery,
                search: '',
                selectedCategoryId: '',
                cart: [],
                discount: 0,
                discountPercent: 0,
                orderType: 'takeaway',
                customerId: '',
                diningTableId: '',
                notes: '',
                submitting: false,
                errorMessage: '',
                successOpen: false,
                successOrderNumber: '',
                successPrintUrl: '',
                lastMenuOrderId: 0,
                init() {
                    this.syncCartBadge();
                    this.$watch('cart', () => this.syncCartBadge(), { deep: true });
                    this.bootstrapMenuOrderPolling();
                    this.refreshOrderStats();
                    setInterval(() => this.refreshOrderStats(), 15000);
                },
                async refreshOrderStats() {
                    if (!this.orderStatsUrl) return;
                    try {
                        const response = await fetch(this.orderStatsUrl, {
                            headers: { 'Accept': 'application/json', 'X-Requested-With': 'XMLHttpRequest' },
                            credentials: 'same-origin',
                        });
                        if (!response.ok) return;
                        const payload = await response.json();
                        const stats = payload.stats || {};
                        document.querySelectorAll('[data-pos-order-channel-stats] [data-stat]').forEach((node) => {
                            const key = node.getAttribute('data-stat');
                            if (key && Object.prototype.hasOwnProperty.call(stats, key)) {
                                node.textContent = String(stats[key]);
                            }
                        });
                    } catch (_error) {
                        // stats refresh is non-blocking
                    }
                },
                setOrderType(type) {
                    this.orderType = type;
                    if (type !== 'table') {
                        this.diningTableId = '';
                    }
                    this.syncMeta();
                },
                syncCartBadge() {
                    const count = this.cart.reduce((sum, line) => sum + Number(line.quantity || 0), 0);
                    if (window.Alpine?.store('posCartUi')) {
                        Alpine.store('posCartUi').setCount(count);
                    }
                },
                notifyAdded(name) {
                    window.HasoPosFeedback?.notifyItemAdded(name);
                    if (window.Alpine?.store('posCartUi')) {
                        Alpine.store('posCartUi').pulseBadge();
                    }
                },
                get filteredItems() {
                    return this.items.filter((item) => {
                        const matchesCategory = !this.selectedCategoryId || Number(item.pos_item_category_id) === Number(this.selectedCategoryId);
                        const term = this.search.trim().toLowerCase();
                        const matchesSearch = term === ''
                            || (item.name || '').toLowerCase().includes(term)
                            || (item.item_type || '').toLowerCase().includes(term)
                            || (item.category?.name || '').toLowerCase().includes(term)
                            || (item.sku || '').toLowerCase().includes(term)
                            || (item.barcode || '').toLowerCase().includes(term);
                        return matchesCategory && matchesSearch;
                    });
                },
                addByBarcode() {
                    const term = this.search.trim().toLowerCase();
                    if (!term) return;
                    const match = this.items.find((item) =>
                        String(item.barcode || '').toLowerCase() === term
                        || String(item.sku || '').toLowerCase() === term
                    );
                    if (match) {
                        this.addItem(match);
                        this.search = '';
                    }
                },
                countInCategory(categoryId) {
                    return this.items.filter((item) => Number(item.pos_item_category_id) === Number(categoryId)).length;
                },
                addItem(item) {
                    const existing = this.cart.find((line) => line.pos_menu_item_id === item.id);
                    if (existing) {
                        existing.quantity += 1;
                        this.syncCartAdd(item.id, 1);
                        this.syncCartBadge();
                        this.notifyAdded(item.name);
                        return;
                    }

                    this.cart.push({
                        pos_menu_item_id: item.id,
                        key: `item_${item.id}`,
                        name: item.name,
                        unit_price: Number(item.price || 0),
                        currency: item.currency || '---',
                        quantity: 1,
                    });
                    this.syncCartAdd(item.id, 1);
                    this.syncCartBadge();
                    this.notifyAdded(item.name);
                },
                increase(index) {
                    this.cart[index].quantity += 1;
                    this.syncCartQty(this.cart[index]);
                    this.syncCartBadge();
                },
                decrease(index) {
                    if (this.cart[index].quantity <= 1) {
                        this.removeLine(index);
                        return;
                    }
                    this.cart[index].quantity -= 1;
                    this.syncCartQty(this.cart[index]);
                    this.syncCartBadge();
                },
                removeLine(index) {
                    const line = this.cart[index];
                    this.cart.splice(index, 1);
                    if (line?.key) {
                        this.cartFetch(`${this.cartEndpoints.removeItem}/${line.key}`, 'DELETE');
                    }
                    this.syncCartBadge();
                },
                get subtotal() {
                    return this.cart.reduce((sum, line) => sum + (line.quantity * line.unit_price), 0);
                },
                get effectiveDiscount() {
                    if (Number(this.discountPercent || 0) > 0) {
                        return Math.max(0, this.subtotal * (Number(this.discountPercent) / 100));
                    }
                    return Number(this.discount || 0);
                },
                get taxAmount() {
                    const taxable = Math.max(0, this.subtotal - this.effectiveDiscount);
                    return Math.max(0, taxable * (Number(this.taxRate || 0) / 100));
                },
                get total() {
                    return Math.max(0, this.subtotal - this.effectiveDiscount + this.taxAmount);
                },
                get orderCurrencyLabel() {
                    const currencies = [...new Set(this.cart.map((line) => line.currency).filter(Boolean))];
                    if (currencies.length === 0) return '';
                    if (currencies.length === 1) return currencies[0];
                    return 'MIX';
                },
                money(amount) {
                    return Number(amount || 0).toFixed(2);
                },
                syncCartAdd(posMenuItemId, quantity) {
                    this.cartFetch(this.cartEndpoints.addItem, 'POST', {
                        pos_menu_item_id: posMenuItemId,
                        quantity,
                    });
                },
                syncCartQty(line) {
                    if (!line?.key) return;
                    this.cartFetch(`${this.cartEndpoints.updateItem}/${line.key}`, 'PATCH', {
                        quantity: line.quantity,
                    });
                },
                syncMeta() {
                    this.cartFetch(this.cartEndpoints.meta, 'POST', {
                        customer_id: this.customerId || null,
                        dining_table_id: this.orderType === 'table' ? (this.diningTableId || null) : null,
                        discount_amount: this.effectiveDiscount || 0,
                        notes: this.notes || null,
                    });
                },
                async bootstrapMenuOrderPolling() {
                    if (!this.recentMenuOrdersUrl) return;
                    try {
                        const response = await fetch(`${this.recentMenuOrdersUrl}?after_id=0`, {
                            headers: { 'Accept': 'application/json', 'X-Requested-With': 'XMLHttpRequest' },
                            credentials: 'same-origin',
                        });
                        const payload = await response.json().catch(() => ({}));
                        this.lastMenuOrderId = Number(payload.latest_id || 0);
                    } catch (_error) {
                        this.lastMenuOrderId = 0;
                    }
                    setInterval(() => this.pollMenuOrders(), 8000);
                },
                async pollMenuOrders() {
                    if (!this.recentMenuOrdersUrl) return;
                    try {
                        const response = await fetch(`${this.recentMenuOrdersUrl}?after_id=${this.lastMenuOrderId}`, {
                            headers: { 'Accept': 'application/json', 'X-Requested-With': 'XMLHttpRequest' },
                            credentials: 'same-origin',
                        });
                        const payload = await response.json().catch(() => ({}));
                        const orders = payload.orders || [];
                        if (orders.length && this.soundEnabled) {
                            orders.slice().reverse().forEach((order) => {
                                window.HasoPosFeedback?.notifyNewMenuOrder(order);
                            });
                        }
                        if (payload.latest_id) {
                            this.lastMenuOrderId = Math.max(this.lastMenuOrderId, Number(payload.latest_id));
                        }
                    } catch (_error) {
                        // polling fallback must never break cashier UI
                    }
                },
                async cartFetch(url, method, body) {
                    try {
                        await fetch(url, {
                            method,
                            headers: {
                                'Content-Type': 'application/json',
                                'Accept': 'application/json',
                                'X-CSRF-TOKEN': this.cartEndpoints.csrf,
                                'X-Requested-With': 'XMLHttpRequest',
                            },
                            body: body ? JSON.stringify(body) : undefined,
                            credentials: 'same-origin',
                        });
                    } catch (error) {
                        console.warn('pos cart sync skipped', error);
                    }
                },
                clearLocalCart() {
                    this.cart = [];
                    this.discount = 0;
                    this.discountPercent = 0;
                    this.notes = '';
                    this.errorMessage = '';
                    this.syncCartBadge();
                },
                openSuccess(payload) {
                    this.successOrderNumber = payload.order_number || payload.order_id || '';
                    this.successPrintUrl = payload.print_url || '';
                    this.successOpen = true;
                    this.clearLocalCart();
                    if (this.cartEndpoints.clear) {
                        this.cartFetch(this.cartEndpoints.clear, 'POST');
                    }
                },
                closeSuccess(shouldPrint) {
                    const url = this.successPrintUrl;
                    this.successOpen = false;
                    this.successPrintUrl = '';
                    this.successOrderNumber = '';
                    if (shouldPrint && url) {
                        window.open(url, '_blank', 'noopener');
                    }
                },
                async createOrder() {
                    if (this.submitting || this.cart.length === 0) return;
                    if (this.orderType === 'table' && !this.diningTableId) {
                        this.errorMessage = 'يرجى اختيار طاولة لهذا الطلب.';
                        return;
                    }
                    this.submitting = true;
                    this.errorMessage = '';
                    await this.syncMeta();

                    const body = {
                        customer_id: this.customerId || null,
                        dining_table_id: this.orderType === 'table' ? (this.diningTableId || null) : null,
                        order_type: this.orderType,
                        notes: this.notes || null,
                        items: this.cart.map((line) => ({
                            pos_menu_item_id: line.pos_menu_item_id,
                            quantity: line.quantity,
                        })),
                    };
                    if (Number(this.discountPercent || 0) > 0) {
                        body.discount_percent = this.discountPercent;
                    } else {
                        body.discount_amount = this.discount || 0;
                    }

                    try {
                        const response = await fetch(this.storeOrderUrl, {
                            method: 'POST',
                            headers: {
                                'Content-Type': 'application/json',
                                'Accept': 'application/json',
                                'X-CSRF-TOKEN': this.cartEndpoints.csrf,
                                'X-Requested-With': 'XMLHttpRequest',
                            },
                            body: JSON.stringify(body),
                            credentials: 'same-origin',
                        });
                        const payload = await response.json().catch(() => ({}));
                        if (!response.ok) {
                            this.errorMessage = payload.message || 'تعذر إنشاء الطلب، حاول مرة أخرى.';
                            return;
                        }
                        this.openSuccess(payload);
                        this.refreshOrderStats();
                    } catch (error) {
                        this.errorMessage = 'تعذر إنشاء الطلب، حاول مرة أخرى.';
                    } finally {
                        this.submitting = false;
                    }
                },
                async checkoutViaCart() {
                    if (this.submitting || this.cart.length === 0) return;
                    this.setOrderType('takeaway');
                    this.submitting = true;
                    this.errorMessage = '';
                    await this.syncMeta();
                    try {
                        const response = await fetch(this.cartEndpoints.checkout, {
                            method: 'POST',
                            headers: {
                                'Content-Type': 'application/json',
                                'Accept': 'application/json',
                                'X-CSRF-TOKEN': this.cartEndpoints.csrf,
                                'X-Requested-With': 'XMLHttpRequest',
                            },
                            body: JSON.stringify({
                                customer_id: this.customerId || null,
                                dining_table_id: null,
                                discount_amount: this.effectiveDiscount || 0,
                                notes: this.notes || null,
                            }),
                            credentials: 'same-origin',
                        });
                        const payload = await response.json().catch(() => ({}));
                        if (!response.ok) {
                            this.errorMessage = payload.message || 'تعذر إنشاء الطلب، حاول مرة أخرى.';
                            return;
                        }
                        this.openSuccess(payload);
                        this.refreshOrderStats();
                    } catch (error) {
                        this.errorMessage = 'تعذر إنشاء الطلب، حاول مرة أخرى.';
                    } finally {
                        this.submitting = false;
                    }
                },
            };
        }
    </script>
@endsection
