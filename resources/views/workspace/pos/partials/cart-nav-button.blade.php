{{-- Shared cart badge button for POS navbar / menu header --}}
<button
    type="button"
    @click="typeof $store !== 'undefined' && $store.posCartUi ? $store.posCartUi.toggleDrawer() : null; $dispatch('pos-cart-toggle')"
    class="relative inline-flex h-10 w-10 items-center justify-center rounded-xl border border-slate-200 bg-white text-slate-700 transition hover:bg-slate-50"
    aria-label="سلة الطلبات"
    data-pos-cart-nav
>
    <svg class="h-5 w-5" fill="none" viewBox="0 0 24 24" stroke="currentColor" aria-hidden="true">
        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.8" d="M3 3h2l.4 2M7 13h10l3-8H6.4M7 13L5.4 5M7 13l-2 7h14m-9-3a1 1 0 11-2 0 1 1 0 012 0zm8 0a1 1 0 11-2 0 1 1 0 012 0z"/>
    </svg>
    <span
        x-show="$store.posCartUi.count > 0"
        x-cloak
        class="absolute -top-1.5 -left-1.5 flex h-5 min-w-5 items-center justify-center rounded-full bg-emerald-600 px-1 text-[10px] font-bold text-white"
        :class="$store.posCartUi.bumpPulse ? 'scale-125' : 'scale-100'"
        style="transition: transform 180ms ease"
        x-text="$store.posCartUi.count"
        data-pos-cart-badge
    ></span>
</button>
