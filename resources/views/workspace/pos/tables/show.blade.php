@extends('layouts.pos', ['pageTitle' => $table->name])

@section('content')
    @php($menuGroups = $menuItems->groupBy(fn ($item) => $item->category?->name ?: ($item->item_type ?: 'عام')))
    @php($defaultCategory = (string) ($menuGroups->keys()->first() ?? ''))
    @php($hasCurrentSession = (bool) $currentSession)
    @php($openedAtIso = $currentSession?->opened_at?->toIso8601String())
    @php($tableOpenLabel = $hasCurrentSession ? 'مفتوحة' : 'مغلقة')
    @php($billableOrders = $sessionOrders->reject(fn ($order) => $order->pos_status === 'cancelled'))
    @php($sessionSubtotal = (float) $billableOrders->sum('subtotal'))
    @php($sessionDiscount = (float) $billableOrders->sum('discount_amount'))
    @php($sessionTotal = (float) $billableOrders->sum('total_amount'))
    @php($ordersCount = $billableOrders->count())
    @php($itemsCount = (int) $billableOrders->sum(fn ($order) => $order->items->sum('quantity')))
    @php($sessionNote = (string) ($billableOrders->firstWhere(fn ($o) => filled($o->notes))?->notes ?? ''))
    @php($splitItems = $billableOrders->flatMap(fn ($order) => $order->items->map(fn ($item) => [
        'id' => $item->id,
        'name' => $item->product_name.($item->variant_name ? ' - '.$item->variant_name : ''),
        'quantity' => (int) $item->quantity,
        'unit_price' => (float) $item->unit_price,
        'total' => (float) $item->total_amount,
    ]))->values())
    @php($otherTablesJson = $otherTables->map(fn ($t) => ['id' => $t->id, 'name' => $t->name, 'status' => $t->status])->values())

    <div
        class="pb-28"
        x-data="tableShowBoard({
            defaultCategory: @js($defaultCategory),
            splitItems: @js($splitItems),
            otherTables: @js($otherTablesJson),
            sessionNote: @js($sessionNote),
            hasSession: @js($hasCurrentSession),
        })"
    >
        <div class="mb-3 flex items-center justify-between gap-2">
            <a href="{{ route('workspace.pos.tables.index') }}" class="rounded-lg border border-slate-200 bg-white px-3 py-2 text-xs font-semibold text-slate-700 hover:bg-slate-50">← رجوع للطاولات</a>
            <h1 class="text-sm font-bold text-slate-900">{{ $table->name }}</h1>
        </div>

        {{-- RTL: first column = RIGHT = table info --}}
        <section class="grid gap-3 xl:grid-cols-12">
            {{-- RIGHT: معلومات الطاولة + خيارات --}}
            <aside class="space-y-3 xl:col-span-4 xl:order-1">
                <article class="rounded-2xl border border-slate-200 bg-white p-4 shadow-sm">
                    <h2 class="text-sm font-bold text-slate-900">معلومات الطاولة</h2>
                    <div class="mt-3 flex flex-wrap items-center gap-2">
                        <p class="text-2xl font-extrabold text-slate-900">{{ $table->name }}</p>
                        <span class="inline-flex items-center gap-1 rounded-full px-2.5 py-1 text-[11px] font-semibold {{ $table->status === 'occupied' ? 'bg-rose-50 text-rose-700' : 'bg-emerald-50 text-emerald-700' }}">
                            <span class="h-1.5 w-1.5 rounded-full {{ $table->status === 'occupied' ? 'bg-rose-500' : 'bg-emerald-500' }}"></span>
                            {{ $table->status === 'occupied' ? 'مشغولة' : 'فارغة' }}
                        </span>
                        <span class="inline-flex items-center gap-1 rounded-full px-2.5 py-1 text-[11px] font-semibold {{ $hasCurrentSession ? 'bg-slate-100 text-slate-700' : 'bg-slate-100 text-slate-600' }}">
                            {{ $tableOpenLabel }}
                        </span>
                    </div>
                    <p class="mt-2 text-xs text-slate-500">
                        @if($hasCurrentSession)
                            المدة: <span class="font-semibold text-slate-700" data-opened-at="{{ $openedAtIso }}">00:00:00</span>
                        @else
                            لا توجد جلسة نشطة
                        @endif
                    </p>

                    <p class="mt-3 rounded-xl border border-amber-100 bg-amber-50 px-3 py-2 text-xs text-amber-800">
                        حالة الطاولة للعرض فقط. فتح/إغلاق الجلسة والدفع من تطبيق الكاشير.
                    </p>
                    <div class="mt-4 grid grid-cols-3 gap-2 text-center">
                        <div class="rounded-xl border border-slate-100 bg-slate-50 px-2 py-2">
                            <p class="text-sm font-bold text-slate-900">{{ $ordersCount }}</p>
                            <p class="text-[10px] text-slate-500">طلبات</p>
                        </div>
                        <div class="rounded-xl border border-slate-100 bg-slate-50 px-2 py-2">
                            <p class="text-sm font-bold text-slate-900">{{ $itemsCount }}</p>
                            <p class="text-[10px] text-slate-500">أصناف</p>
                        </div>
                        <div class="rounded-xl border border-slate-100 bg-slate-50 px-2 py-2">
                            <p class="text-sm font-bold text-emerald-700">{{ number_format($sessionTotal, 2) }}</p>
                            <p class="text-[10px] text-slate-500">الإجمالي</p>
                        </div>
                    </div>

                    <div class="mt-4 grid gap-2">
                        <p class="rounded-xl border border-slate-200 bg-slate-50 px-3 py-2.5 text-sm text-slate-600">إضافة الطلبات تتم من تطبيق الكاشير أو QR Menu.</p>
                    </div>
                </article>

                <article class="rounded-2xl border border-slate-200 bg-white p-3 shadow-sm">
                    <h3 class="px-1 text-sm font-bold text-slate-900">خيارات الطاولة</h3>
                    <div class="mt-2 divide-y divide-slate-100">
                        <p class="px-2 py-2.5 text-sm text-slate-500">النقل والدمج والتقسيم والإغلاق متاحة من تطبيق الكاشير فقط.</p>
                        <button type="button" onclick="window.print()" class="flex w-full items-center justify-between gap-2 px-2 py-2.5 text-right text-sm text-slate-700 hover:bg-slate-50">
                            <span>طباعة الحساب</span>
                            <svg class="h-4 w-4 text-slate-400" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.8" d="M6 9V4h12v5M6 18H4a2 2 0 01-2-2v-5a2 2 0 012-2h16a2 2 0 012 2v5a2 2 0 01-2 2h-2M6 14h12v6H6v-6z"/></svg>
                        </button>
                    </div>
                </article>
            </aside>

            {{-- LEFT: تفاصيل الطلبات --}}
            <section class="xl:col-span-8 xl:order-2">
                <article class="rounded-2xl border border-slate-200 bg-white p-4 shadow-sm">
                    <div class="flex flex-wrap items-center justify-between gap-2">
                        <h2 class="text-sm font-bold text-slate-900">تفاصيل طلبات الطاولة</h2>
                        <div class="flex flex-wrap gap-1.5">
                            <button type="button" @click="filter = 'all'" :class="filter === 'all' ? 'bg-emerald-600 text-white border-emerald-600' : 'bg-white text-slate-600 border-slate-200'" class="rounded-full border px-3 py-1 text-[11px] font-semibold">الكل ({{ $sessionOrders->count() }})</button>
                            <button type="button" @click="filter = 'open'" :class="filter === 'open' ? 'bg-emerald-600 text-white border-emerald-600' : 'bg-white text-slate-600 border-slate-200'" class="rounded-full border px-3 py-1 text-[11px] font-semibold">مفتوحة ({{ $billableOrders->reject(fn($o) => $o->payment_status === 'paid')->count() }})</button>
                            <button type="button" @click="filter = 'paid'" :class="filter === 'paid' ? 'bg-emerald-600 text-white border-emerald-600' : 'bg-white text-slate-600 border-slate-200'" class="rounded-full border px-3 py-1 text-[11px] font-semibold">مدفوعة ({{ $billableOrders->where('payment_status', 'paid')->count() }})</button>
                            <button type="button" @click="filter = 'cancelled'" :class="filter === 'cancelled' ? 'bg-emerald-600 text-white border-emerald-600' : 'bg-white text-slate-600 border-slate-200'" class="rounded-full border px-3 py-1 text-[11px] font-semibold">ملغية ({{ $sessionOrders->where('pos_status', 'cancelled')->count() }})</button>
                        </div>
                    </div>
                    <input x-model="search" type="search" placeholder="ابحث في الطلبات..." class="mt-3 w-full rounded-xl border-slate-200 text-sm" />

                    <div class="mt-4 space-y-3">
                        @forelse($sessionOrders as $order)
                            @php($cashierName = (string) data_get($order->metadata, 'created_by_name', data_get($order->metadata, 'payment_method') === 'cashier' ? 'كاشير مباشر' : 'طلب'))
                            @php($statusKey = $order->pos_status === 'cancelled' ? 'cancelled' : ($order->payment_status === 'paid' ? 'paid' : 'open'))
                            <article
                                class="rounded-2xl border border-slate-200 bg-white p-3 shadow-sm"
                                data-order-card
                                data-status="{{ $statusKey }}"
                                data-search="{{ strtolower($order->order_number.' '.$cashierName) }}"
                                x-show="matchesOrder(@js($statusKey), @js(strtolower($order->order_number.' '.$cashierName)))"
                            >
                                <div class="flex items-start justify-between gap-2">
                                    <div class="flex flex-wrap items-center gap-2">
                                        <span class="rounded-lg bg-emerald-50 px-2 py-1 text-[11px] font-bold text-emerald-700">#{{ $order->order_number }}</span>
                                        <span class="rounded-full px-2 py-0.5 text-[10px] font-semibold {{ $order->pos_status === 'cancelled' ? 'bg-rose-50 text-rose-700' : ($order->payment_status === 'paid' ? 'bg-slate-100 text-slate-700' : 'bg-emerald-50 text-emerald-700') }}">
                                            {{ $posStatuses[$order->pos_status] ?? $order->pos_status }}
                                        </span>
                                        <span class="text-[11px] text-slate-500">{{ $cashierName }} · {{ optional($order->created_at)->format('H:i') }}</span>
                                    </div>
                                    <div class="relative" x-data="{ open: false }">
                                        <button type="button" @click="open = !open" class="rounded-lg border border-slate-200 px-2 py-1 text-sm font-bold text-slate-500">⋯</button>
                                        <div x-show="open" @click.outside="open = false" x-cloak class="absolute left-0 z-20 mt-1 w-44 overflow-hidden rounded-xl border border-slate-200 bg-white py-1 text-xs shadow-lg">
                                            <a href="{{ route('workspace.pos.orders.print', $order) }}" target="_blank" class="block px-3 py-2 text-slate-700 hover:bg-slate-50">طباعة الطلب</a>
                                        </div>
                                    </div>
                                </div>

                                <div class="mt-3 overflow-x-auto">
                                    <table class="min-w-full text-xs">
                                        <thead>
                                            <tr class="text-slate-400">
                                                <th class="pb-2 text-right font-semibold">الصنف</th>
                                                <th class="pb-2 text-center font-semibold">الكمية</th>
                                                <th class="pb-2 text-center font-semibold">السعر</th>
                                                <th class="pb-2 text-left font-semibold">الإجمالي</th>
                                            </tr>
                                        </thead>
                                        <tbody class="divide-y divide-slate-100">
                                            @foreach($order->items as $item)
                                                <tr>
                                                    <td class="py-1.5 font-semibold text-slate-800">{{ $item->product_name }}{{ $item->variant_name ? ' - '.$item->variant_name : '' }}</td>
                                                    <td class="py-1.5 text-center text-slate-600">x {{ $item->quantity }}</td>
                                                    <td class="py-1.5 text-center text-slate-600">{{ number_format((float) $item->unit_price, 2) }}</td>
                                                    <td class="py-1.5 text-left font-semibold text-slate-800">{{ number_format((float) $item->total_amount, 2) }}</td>
                                                </tr>
                                            @endforeach
                                        </tbody>
                                    </table>
                                </div>

                                <div class="mt-3 grid grid-cols-2 gap-2 border-t border-slate-100 pt-2 text-[11px] text-slate-500 sm:grid-cols-4">
                                    <div>المجموع الفرعي <span class="font-semibold text-slate-800">{{ number_format((float) $order->subtotal, 2) }}</span></div>
                                    <div>ض.ق.م <span class="font-semibold text-slate-800">{{ number_format((float) $order->tax_amount, 2) }}</span></div>
                                    <div>الخصم <span class="font-semibold text-slate-800">{{ number_format((float) $order->discount_amount, 2) }}</span></div>
                                    <div class="font-bold text-emerald-700">الإجمالي {{ number_format((float) $order->total_amount, 2) }} {{ $order->currency }}</div>
                                </div>
                            </article>
                        @empty
                            <p class="py-10 text-center text-sm text-slate-400">لا توجد طلبات في هذه الجلسة.</p>
                        @endforelse
                    </div>
                </article>
            </section>
        </section>

        {{-- Bottom sticky bar --}}
        <div class="fixed inset-x-0 bottom-0 z-30 border-t border-slate-200 bg-white/95 backdrop-blur">
            <div class="mx-auto flex max-w-7xl flex-wrap items-center justify-between gap-3 px-4 py-3">
                <div class="flex flex-wrap items-center gap-4">
                    <div>
                        <p class="text-[11px] text-slate-500">الإجمالي الكلي</p>
                        <p class="text-lg font-extrabold text-emerald-700">{{ number_format($sessionTotal, 2) }}</p>
                    </div>
                    <div class="text-xs text-slate-600">الخصم: <span class="font-semibold">{{ number_format($sessionDiscount, 2) }}</span></div>
                </div>
                <div class="flex flex-wrap items-center gap-2">
                    <button type="button" onclick="window.print()" class="rounded-xl border border-slate-200 bg-white px-3 py-2 text-xs font-semibold text-slate-700 hover:bg-slate-50">طباعة</button>
                    <span class="rounded-xl border border-slate-200 bg-slate-50 px-3 py-2 text-xs font-semibold text-slate-600">الحساب للعرض — الإغلاق من تطبيق الكاشير</span>
                </div>
            </div>
        </div>
        <div id="bill-anchor" class="sr-only">الحساب</div>
    </div>

    <script>
        function tableShowBoard({ defaultCategory, splitItems, otherTables, sessionNote, hasSession }) {
            const emptyQty = () => Object.fromEntries(splitItems.map((item) => [item.id, 0]));
            return {
                panel: null,
                filter: 'all',
                search: '',
                selectedCategory: defaultCategory || '',
                selectedItemId: '',
                editingOrderId: null,
                hasSession,
                sessionNote,
                otherTables,
                splitItems,
                splitGroups: [
                    { qty: emptyQty() },
                    { qty: emptyQty() },
                ],
                matchesOrder(status, haystack) {
                    if (this.filter === 'open' && status !== 'open') return false;
                    if (this.filter === 'paid' && status !== 'paid') return false;
                    if (this.filter === 'cancelled' && status !== 'cancelled') return false;
                    const term = this.search.trim().toLowerCase();
                    if (!term) return true;
                    return String(haystack || '').includes(term);
                },
                addSplitGroup() {
                    this.splitGroups.push({ qty: emptyQty() });
                },
                splitAllocatedTotal() {
                    let total = 0;
                    this.splitGroups.forEach((group) => {
                        this.splitItems.forEach((item) => {
                            const qty = Number(group.qty[item.id] || 0);
                            total += qty * Number(item.unit_price || 0);
                        });
                    });
                    return total;
                },
                splitIsValid() {
                    return this.splitItems.every((item) => {
                        const used = this.splitGroups.reduce((sum, group) => sum + Number(group.qty[item.id] || 0), 0);
                        return used === Number(item.quantity);
                    });
                },
            };
        }

        const formatDuration = (openedAt) => {
            if (!openedAt) return '00:00:00';
            const start = new Date(openedAt).getTime();
            if (Number.isNaN(start)) return '00:00:00';
            const diff = Math.max(0, Math.floor((Date.now() - start) / 1000));
            const hours = String(Math.floor(diff / 3600)).padStart(2, '0');
            const minutes = String(Math.floor((diff % 3600) / 60)).padStart(2, '0');
            const seconds = String(diff % 60).padStart(2, '0');
            return `${hours}:${minutes}:${seconds}`;
        };

        const timerNodes = document.querySelectorAll('[data-opened-at]');
        if (timerNodes.length > 0) {
            const tick = () => timerNodes.forEach((node) => { node.textContent = formatDuration(node.dataset.openedAt); });
            tick();
            setInterval(tick, 1000);
        }
    </script>
@endsection
