{{-- Compact order-channel stats for cashier dashboard --}}
@php($stats = $orderChannelStats ?? null)
@if($stats)
    <section class="mb-3 grid gap-2 sm:grid-cols-2 xl:grid-cols-4" data-pos-order-channel-stats>
        <article class="rounded-2xl border border-slate-200 bg-white p-3 shadow-sm">
            <p class="text-[11px] font-semibold text-slate-500">اليوم · داخل المطعم (طاولة)</p>
            <p class="mt-1 text-2xl font-extrabold text-slate-900" data-stat="table">{{ (int) $stats['table'] }}</p>
            <p class="mt-1 text-[11px] text-slate-500">مفتوحة الآن: <span class="font-semibold text-emerald-700" data-stat="open_table">{{ (int) $stats['open_table'] }}</span></p>
        </article>
        <article class="rounded-2xl border border-slate-200 bg-white p-3 shadow-sm">
            <p class="text-[11px] font-semibold text-slate-500">اليوم · طلب خارجي</p>
            <p class="mt-1 text-2xl font-extrabold text-slate-900" data-stat="takeaway">{{ (int) $stats['takeaway'] }}</p>
            <p class="mt-1 text-[11px] text-slate-500">مفتوحة الآن: <span class="font-semibold text-emerald-700" data-stat="open_takeaway">{{ (int) $stats['open_takeaway'] }}</span></p>
        </article>
        <article class="rounded-2xl border border-slate-200 bg-white p-3 shadow-sm">
            <p class="text-[11px] font-semibold text-slate-500">اليوم · توصيل</p>
            <p class="mt-1 text-2xl font-extrabold text-slate-900" data-stat="delivery">{{ (int) $stats['delivery'] }}</p>
            <p class="mt-1 text-[11px] text-slate-500">مفتوحة الآن: <span class="font-semibold text-emerald-700" data-stat="open_delivery">{{ (int) $stats['open_delivery'] }}</span></p>
        </article>
        <article class="rounded-2xl border border-emerald-200 bg-emerald-50/60 p-3 shadow-sm">
            <p class="text-[11px] font-semibold text-emerald-700">إجمالي طلبات اليوم</p>
            <p class="mt-1 text-2xl font-extrabold text-emerald-800" data-stat="total">{{ (int) $stats['total'] }}</p>
            <p class="mt-1 text-[11px] text-emerald-700">مفتوحة الآن: <span class="font-semibold" data-stat="open_total">{{ (int) $stats['open_total'] }}</span></p>
        </article>
    </section>
@endif
