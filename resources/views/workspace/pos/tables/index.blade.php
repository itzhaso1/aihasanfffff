@extends('layouts.pos', ['pageTitle' => 'لوحة الطاولات'])

@section('content')
    @include('workspace.pos.partials.order-channel-stats', ['orderChannelStats' => $orderChannelStats])

    @php($workspace = request()->attributes->get('workspace'))

    <div
        x-data="tablesBoard({
            tables: @js($tablesPayload),
            csrf: @js(csrf_token()),
            liveBoardUrl: @js($liveBoardUrl),
        })"
        class="grid gap-3 lg:grid-cols-12"
    >
        {{-- LEFT (RTL start): order details only — no management actions mixed in --}}
        <aside class="order-1 rounded-2xl border border-slate-200 bg-white p-3 shadow-sm lg:col-span-3" data-table-order-details>
            <h2 class="text-sm font-bold text-slate-900">تفاصيل الطلب</h2>

            <template x-if="!selected">
                <p class="mt-6 text-center text-xs text-slate-400">اختر طاولة لعرض تفاصيل طلباتها.</p>
            </template>

            <template x-if="selected">
                <div class="mt-3 space-y-3">
                    <div class="rounded-xl border border-slate-100 bg-white p-2.5">
                        <div class="flex items-center justify-between gap-2">
                            <h3 class="text-sm font-bold text-slate-900" x-text="selected.name"></h3>
                            <span
                                class="rounded-full px-2 py-0.5 text-[10px] font-semibold"
                                :class="selected.status === 'occupied' ? 'bg-rose-50 text-rose-700' : 'bg-emerald-50 text-emerald-700'"
                                x-text="selected.status === 'occupied' ? 'مشغولة' : (selected.status === 'reserved' ? 'محجوزة' : (selected.status === 'cleaning' ? 'تنظيف' : (selected.status === 'closed' ? 'مغلقة' : 'فارغة')))"
                            ></span>
                        </div>
                        <p class="mt-1 text-xs text-slate-500" x-show="selected.customer_name">
                            العميل: <span class="font-semibold text-slate-700" x-text="selected.customer_name"></span>
                        </p>
                        <p class="mt-1 text-xs text-slate-500" x-show="selected.opened_at" data-live-timer x-text="formatDuration(selected.opened_at)"></p>
                    </div>

                    <div class="max-h-72 space-y-1.5 overflow-y-auto rounded-xl border border-slate-100 p-2">
                        <template x-if="!selected.lines.length">
                            <p class="py-4 text-center text-xs text-slate-400">لا توجد عناصر في هذه الجلسة.</p>
                        </template>
                        <template x-for="(line, idx) in selected.lines" :key="idx">
                            <div class="flex items-center justify-between rounded-lg bg-slate-50 px-2 py-1.5 text-xs">
                                <span class="font-semibold text-slate-800">
                                    <span x-text="line.name"></span>
                                    <span class="text-slate-400"> × </span>
                                    <span x-text="line.quantity"></span>
                                </span>
                                <span class="font-semibold text-slate-700" x-text="Number(line.total || 0).toFixed(2)"></span>
                            </div>
                        </template>
                    </div>

                    <div class="flex items-center justify-between border-t border-slate-100 pt-2 text-sm font-bold text-slate-900">
                        <span>الإجمالي</span>
                        <span x-text="Number(selected.total || 0).toFixed(2)"></span>
                    </div>
                </div>
            </template>
        </aside>

        {{-- CENTER: table cards + ⋯ menu for management actions --}}
        <section class="order-2 rounded-2xl border border-slate-200 bg-white p-3 shadow-sm lg:col-span-6">
            <div class="mb-3 flex items-center justify-between gap-2">
                <h2 class="text-sm font-bold text-slate-900">الطاولات</h2>
                <a href="{{ route('workspace.pos.qr-orders.index') }}" class="rounded-lg border border-slate-300 px-3 py-1.5 text-xs font-semibold text-slate-700 hover:bg-slate-100">طلبات QR</a>
            </div>

            <div class="grid gap-2 sm:grid-cols-2 xl:grid-cols-3">
                <template x-for="table in tables" :key="table.id">
                    <article
                        @click="selectTable(table)"
                        :class="selected?.id === table.id ? 'border-emerald-600 ring-1 ring-emerald-600' : 'border-slate-200 hover:border-slate-300'"
                        class="relative cursor-pointer rounded-xl border bg-white p-3 transition"
                        data-table-card
                    >
                        <div class="flex items-start justify-between gap-2">
                            <div>
                                <h3 class="text-sm font-bold text-slate-900" x-text="table.name"></h3>
                                <p
                                    class="mt-1 text-[11px] font-semibold"
                                    :class="table.status === 'occupied' ? 'text-rose-600' : 'text-emerald-600'"
                                    x-text="table.status === 'occupied' ? 'مشغولة' : (table.status === 'reserved' ? 'محجوزة' : (table.status === 'cleaning' ? 'تنظيف' : (table.status === 'closed' ? 'مغلقة' : 'فارغة')))"
                                ></p>
                            </div>

                            <div class="relative" @click.stop data-table-menu>
                                <button
                                    type="button"
                                    @click="toggleMenu(table.id)"
                                    class="rounded-lg border border-slate-200 px-2 py-1 text-sm font-bold text-slate-600 hover:bg-slate-50"
                                    aria-label="منيو الطاولة"
                                >⋯</button>
                                <div
                                    x-show="openMenuId === table.id"
                                    x-cloak
                                    @click.outside="openMenuId = null"
                                    class="absolute left-0 z-20 mt-1 w-44 overflow-hidden rounded-lg border border-slate-200 bg-white py-1 text-xs shadow-lg"
                                >
                                    <a :href="table.show_url" class="block px-3 py-2 text-slate-700 hover:bg-slate-50">عرض الطاولة</a>
                                    <form :action="table.qr_regen_url" method="POST">
                                        <input type="hidden" name="_token" :value="csrf">
                                        <button class="block w-full px-3 py-2 text-right text-slate-700 hover:bg-slate-50">تجديد QR</button>
                                    </form>
                                </div>
                            </div>
                        </div>

                        <div class="mt-3 space-y-1 text-[11px] text-slate-600">
                            <p>الطلبات النشطة: <span class="font-semibold" x-text="table.open_orders_count"></span></p>
                            <p x-show="table.opened_at">المدة: <span data-live-timer :data-opened-at="table.opened_at" x-text="formatDuration(table.opened_at)"></span></p>
                            <p x-show="table.total > 0">الإجمالي: <span class="font-semibold" x-text="Number(table.total).toFixed(2)"></span></p>
                        </div>
                    </article>
                </template>
            </div>

            @if($tables->isEmpty())
                <p class="text-sm text-slate-500">لا توجد طاولات حتى الآن.</p>
            @endif

            <div class="mt-4">
                {{ $tables->links() }}
            </div>
        </section>

        {{-- RIGHT: create table --}}
        <aside class="order-3 space-y-3 lg:col-span-3">
            <article class="rounded-2xl border border-slate-200 bg-white p-3 shadow-sm">
                <h2 class="text-sm font-bold text-slate-900">إضافة طاولة</h2>
                <form method="POST" action="{{ route('workspace.pos.tables.store') }}" class="mt-3 space-y-3">
                    @csrf
                    <div>
                        <label class="mb-1 block text-[11px] font-semibold text-slate-600">اسم/رقم الطاولة</label>
                        <input name="name" required class="w-full rounded-lg border-slate-300 text-sm" placeholder="طاولة 1" />
                    </div>
                    <button class="w-full rounded-lg bg-slate-900 px-3 py-2 text-sm font-semibold text-white">إنشاء</button>
                </form>
                <div class="mt-4 rounded-xl bg-slate-50 p-3 text-xs text-slate-600">
                    <p>المنيو العام:
                        <a class="font-semibold text-slate-800" href="{{ route('menu.general', ['workspace' => $workspace->slug]) }}" target="_blank" rel="noopener">فتح الرابط</a>
                    </p>
                </div>
            </article>
        </aside>

    </div>

    <script>
        function tablesBoard({ tables, csrf, liveBoardUrl = null }) {
            return {
                tables,
                csrf,
                liveBoardUrl,
                selected: null,
                openMenuId: null,
                pollTimer: null,
                selectTable(table) {
                    this.selected = table;
                    this.openMenuId = null;
                },
                toggleMenu(id) {
                    this.openMenuId = this.openMenuId === id ? null : id;
                },
                formatDuration(openedAt) {
                    if (!openedAt) return '';
                    const start = new Date(openedAt).getTime();
                    if (Number.isNaN(start)) return '00:00:00';
                    const diff = Math.max(0, Math.floor((Date.now() - start) / 1000));
                    const hours = String(Math.floor(diff / 3600)).padStart(2, '0');
                    const minutes = String(Math.floor((diff % 3600) / 60)).padStart(2, '0');
                    const seconds = String(diff % 60).padStart(2, '0');
                    return `${hours}:${minutes}:${seconds}`;
                },
                applyLiveTables(nextTables) {
                    const previous = new Map(this.tables.map((table) => [String(table.id), table]));
                    this.tables = nextTables;

                    nextTables.forEach((table) => {
                        const before = previous.get(String(table.id));
                        if (before && before.status !== 'occupied' && table.status === 'occupied') {
                            window.HasoPosFeedback?.notifyNewMenuOrder({
                                table_name: table.name,
                                order_number: table.open_orders_count || '',
                            });
                        }
                    });

                    if (this.selected) {
                        const refreshed = nextTables.find((table) => Number(table.id) === Number(this.selected.id));
                        if (refreshed) {
                            this.selected = refreshed;
                        }
                    }
                },
                async refreshBoard() {
                    if (!this.liveBoardUrl) return;
                    try {
                        const response = await fetch(this.liveBoardUrl, {
                            headers: {
                                'Accept': 'application/json',
                                'X-Requested-With': 'XMLHttpRequest',
                            },
                            credentials: 'same-origin',
                        });
                        if (!response.ok) return;
                        const payload = await response.json();
                        if (Array.isArray(payload.tables)) {
                            this.applyLiveTables(payload.tables);
                        }
                    } catch (_error) {
                        // Keep board usable if polling fails.
                    }
                },
                init() {
                    setInterval(() => {
                        document.querySelectorAll('[data-live-timer]').forEach((node) => {
                            const opened = node.getAttribute('data-opened-at') || (this.selected?.opened_at);
                            if (opened) node.textContent = this.formatDuration(opened);
                        });
                    }, 1000);

                    if (this.liveBoardUrl) {
                        this.pollTimer = setInterval(() => this.refreshBoard(), 3000);
                        document.addEventListener('visibilitychange', () => {
                            if (!document.hidden) {
                                this.refreshBoard();
                            }
                        });
                    }
                },
            };
        }
    </script>
@endsection
